<?php
/** Public history projection regression; synthetic fixtures, no database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function get_setting(string $key, string $default = ''): string { return $default; }
class PortalActivityStatement extends PDOStatement
{
    public array $parameters = [];
    public function __construct() {}
    public function execute(?array $params = null): bool { $this->parameters = $params ?? []; return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $fixtures = [
            ['id' => 1, 'device_id' => 7, 'event_type' => 'container_selected', 'event_level' => 'info', 'message' => 'Private user message', 'metadata' => '{"container_type":"cup","target_amount_ml":250,"threshold_ml":350,"token":"private-secret"}', 'created_at' => '2026-10-09 09:41:02'],
            ['id' => 2, 'device_id' => 7, 'event_type' => 'configuration_changed', 'event_level' => 'info', 'message' => 'Private admin setting', 'metadata' => null, 'created_at' => '2026-10-09 09:42:00'],
            ['id' => 3, 'device_id' => 8, 'event_type' => 'container_selected', 'event_level' => 'info', 'message' => 'Other-device activity', 'metadata' => null, 'created_at' => '2026-10-09 09:43:00'],
            ['id' => 4, 'device_id' => 7, 'event_type' => 'dispensing_started', 'event_level' => 'info', 'message' => '<script>private-secret</script> target: 500mL', 'metadata' => null, 'created_at' => '2026-10-09 09:44:00'],
            ['id' => 5, 'device_id' => 7, 'event_type' => 'sensor_error', 'event_level' => 'critical', 'message' => 'Raw secret: private-secret', 'metadata' => '{"container_type":"<script>","target_amount_ml":"500<script>"}', 'created_at' => '2026-10-09 09:45:00'],
            ['id' => 6, 'device_id' => 9, 'event_type' => 'device_connected', 'event_level' => 'info', 'message' => '', 'metadata' => null, 'created_at' => 'not-a-date'],
            ['id' => 7, 'device_id' => 9, 'event_type' => 'device_connected', 'event_level' => 'info', 'message' => '', 'metadata' => null, 'created_at' => '2026-02-31 09:45:00'],
            ['id' => 8, 'device_id' => 7, 'event_type' => 'fill_volume_selected', 'event_level' => 'info', 'message' => '', 'metadata' => '{"container_type":"cup","target_amount_ml":137,"fill_amount_ml":137,"threshold_ml":137,"threshold_semantics":"fill_volume_ml"}', 'created_at' => '2026-10-09 09:46:00'],
            ['id' => 9, 'device_id' => 7, 'event_type' => 'low_water', 'event_level' => 'warning', 'message' => 'Obsolete sensor warning', 'metadata' => null, 'created_at' => '2026-10-09 09:47:00'],
            ['id' => 10, 'device_id' => 7, 'event_type' => 'water_threshold_selected', 'event_level' => 'info', 'message' => '', 'metadata' => '{"container_type":"cup","target_amount_ml":250,"threshold_ml":350}', 'created_at' => '2026-10-09 09:48:00'],
        ];
        return array_values(array_filter($fixtures, fn($row) => $row['device_id'] === $this->parameters[0] && in_array($row['event_type'], array_slice($this->parameters, 1), true)));
    }
}
class PortalActivityDatabase extends PDO
{
    public string $sql = '';
    public PortalActivityStatement $statement;
    public function __construct() { $this->statement = new PortalActivityStatement(); }
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->sql = $query; return $this->statement; }
}
$database = new PortalActivityDatabase();
function db(): PDO { global $database; return $database; }
require_once __DIR__ . '/../config/portal.php';
$checks = 0;
function check(bool $value, string $description): void
{
    global $checks;
    $checks++;
    if (!$value) throw new RuntimeException('FAIL: ' . $description);
}
check(portal_activity_records(null) === [], 'no device has an empty history');
$entries = portal_activity_records(7, 500);
check(count($entries) === 5, 'allowlisted device history excludes obsolete water warnings');
check(str_contains($database->sql, 'WHERE device_id = ? AND event_type IN ('), 'history query is scoped and parameterized');
check(str_ends_with($database->sql, 'LIMIT 50'), 'public history result size is bounded');
check($entries[0]['time'] === '09:41:02' && $entries[0]['created_at'] === '2026-10-09 09:41:02' && $entries[0]['date_time'] === '2026-10-09 09:41:02', 'full date and time derive from server records without shifting timezone');
foreach (portal_activity_records(9) as $entry) check($entry['date_time'] === 'Date & time unavailable' && $entry['time'] === 'Unavailable', 'invalid stored timestamps never become fabricated dates');
check($entries[0]['details'] === 'Cup — 250 mL target', 'legacy reservoir minimum is never relabeled as fill amount');
check($entries[3]['event'] === 'Fill amount selected' && $entries[3]['details'] === 'Cup — 137 mL fill amount', 'explicit fill semantics produce accurate new history');
check($entries[4]['event'] === 'Historical threshold selected' && !str_contains($entries[4]['details'], '350'), 'old reservoir threshold event stays historical');
check(str_contains($entries[1]['details'], 'Target: 500 mL.'), 'existing real target facts remain readable');
check($entries[2]['status'] === 'Error', 'critical hardware event keeps error status');
$public = json_encode($entries);
check(!str_contains($public, 'private-secret') && !str_contains($public, '<script>') && !str_contains($public, 'Private admin') && !str_contains($public, 'Other-device'), 'public projection hides raw messages/secrets/admin history/other devices');
check(!array_key_exists('metadata', $entries[0]) && !array_key_exists('message', $entries[0]), 'raw storage fields are not returned');
echo "PASS: {$checks} public activity projection checks. No database writes.\n";
