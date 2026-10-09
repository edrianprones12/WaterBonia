<?php
/**
 * Demo HTTP integration smoke tests. Uses a guest session and never impersonates
 * an administrator, sends device telemetry, or changes production records.
 * Run against a DEMO_MODE=true server:
 * php tests/portal_http_test.php http://127.0.0.1:8088/WaterBonia [hardware-server-URL]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$portal_test_url = rtrim($argv[1] ?? 'http://127.0.0.1:8088/WaterBonia', '/');
$portal_test_cookie = '';
$portal_test_count = 0;

function http_request(string $path, ?array $body = null, ?string $token = null, bool $form = false): array
{
    global $portal_test_url, $portal_test_cookie;
    $headers = 'Accept: application/json,text/html' . "\r\n";
    if ($portal_test_cookie !== '') $headers .= 'Cookie: ' . $portal_test_cookie . "\r\n";
    $options = ['method' => $body === null ? 'GET' : 'POST', 'header' => $headers,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 12];
    if ($body !== null) {
        if ($token !== null) $body['csrf_token'] = $token;
        $options['header'] .= 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json') . "\r\n";
        $options['content'] = $form ? http_build_query($body) : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $content = file_get_contents($portal_test_url . $path, false, stream_context_create(['http' => $options]));
    if ($content === false) throw new RuntimeException('HTTP server unavailable: ' . $portal_test_url);
    $response_headers = $http_response_header ?? [];
    preg_match('/^HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $matches);
    foreach ($response_headers as $header) {
        if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $header, $cookie)) $portal_test_cookie = $cookie[1];
    }
    return [(int)($matches[1] ?? 0), $content, $response_headers];
}

function check(bool $result, string $name): void
{
    global $portal_test_count;
    if (!$result) throw new RuntimeException('FAIL ' . $name);
    $portal_test_count++;
    echo 'PASS ' . $name . PHP_EOL;
}

try {
    [$status, , $headers] = http_request('/');
    check($status === 302 && in_array('Location: /WaterBonia/pages/dispense.php', $headers, true), 'Main URL routes to the Execution Portal using the application base URL');
    foreach (['/admin/', '/admin/index.php'] as $route) {
        [$status, , $headers] = http_request($route);
        check($status === 302 && in_array('Location: /WaterBonia/pages/dispense.php', $headers, true), 'Retired route redirects to the Execution Portal: ' . $route);
    }
    foreach (['/_admin_shot.php', '/_demo_test.php'] as $fixture) {
        [$status] = http_request($fixture);
        check($status === 404, 'Old administrator screenshot fixture is inaccessible over HTTP: ' . $fixture);
    }
    [$status, $html] = http_request('/pages/dispense.php');
    check($status === 200 && str_contains($html, 'What would you like to fill?'), 'Guest Execution Portal opens successfully');
    check(!preg_match('/Admin portal|href="[^"\n]*\/(admin|auth)\/|id="sidebar"/i', $html)
        && str_contains($html, 'id="portal-navigation"')
        && str_contains($html, '<details class="portal-navigation-mobile">'), 'Execution Portal provides public sidebar navigation and a mobile menu without administrative links');
    check(substr_count($html, 'class="portal-navigation-brand"') === 1
        && !str_contains($html, 'class="portal-brand"')
        && !str_contains($html, 'class="portal-navigation-note"')
        && !str_contains($html, 'An Automatic Web and Sensor-Based Water Dispensing System'), 'One compact sidebar brand remains without duplicated header or project-description branding');
    check(str_contains($html, '<span>Water Dispensing System</span>')
        && !str_contains($html, 'Execution Portal'), 'Public branding uses the Water Dispensing System subtitle throughout');
    preg_match('/<header\b[^>]*class="portal-header"[^>]*>(.*?)<\/header>/s', $html, $portal_header_match);
    $toolbar_html = $portal_header_match[1] ?? '';
    check((bool)preg_match('/id="portal-page-heading"[^>]*>\s*Dispense Water\s*<\//', $toolbar_html)
        && str_contains($toolbar_html, 'id="status-pill"')
        && str_contains($toolbar_html, 'id="demo-pill"'), 'Compact toolbar displays Dispense Water and the system and Demo Mode indicators');
    preg_match_all('/\sid="([^"]+)"/', $html, $portal_id_matches);
    $portal_id_counts = array_count_values($portal_id_matches[1]);
    check(!array_filter($portal_id_counts, fn($count) => $count > 1), 'Rendered portal has unique element IDs');
    $sidebar_readouts = ['cup-fill-volume-input', 'tumbler-fill-volume-input', 'cup-fill-volume-feedback',
        'tumbler-fill-volume-feedback', 'cup-target-volume', 'tumbler-target-volume',
        'water-cup-threshold', 'water-tumbler-threshold', 'device-status-connection', 'device-status-container',
        'device-status-pump'];
    check(count(array_intersect($sidebar_readouts, array_keys($portal_id_counts))) === count($sidebar_readouts), 'Container Settings and Device Status expose their editable amounts and live readouts');
    preg_match_all('/<a\b[^>]*>/', $html, $portal_link_tags);
    foreach (['dispense-workflow' => 'Dispense Water', 'container-settings' => 'Container Settings', 'led-card' => 'Device Status',
        'activity-logs' => 'Activity Logs', 'safety-sequence' => 'Safety Sequence', 'demo-card' => 'Demo Controls'] as $view_id => $section_title) {
        preg_match('/<[a-z][a-z0-9-]*\b[^>]*\bid="' . preg_quote($view_id, '/') . '"[^>]*>/i', $html, $view_match);
        $view_tag = $view_match[0] ?? '';
        $is_hidden = (bool)preg_match('/\shidden(?:\s|=|>)/', $view_tag);
        $linked = false;
        foreach ($portal_link_tags[0] as $link_tag) {
            if (str_contains($link_tag, 'href="/WaterBonia/pages/dispense.php#' . $view_id . '"')
                && preg_match('/\sdata-portal-link(?:\s|=|>)/', $link_tag)
                && str_contains($link_tag, 'aria-controls="' . $view_id . '"')) $linked = true;
        }
        check(preg_match('/\sdata-portal-section(?:\s|=|>)/', $view_tag)
            && $is_hidden === ($view_id !== 'dispense-workflow') && str_contains($view_tag, 'data-portal-title="' . $section_title . '"')
            && $linked, 'Sidebar links to a dedicated view with its toolbar title and correct initial visibility: ' . $view_id);
    }
    check(!preg_match('/Fatal error|Warning:|Notice:/', $html), 'Rendered portal contains no PHP runtime warnings');
    preg_match('/window\.WaterBoniaDispenser=(\{.*?\});<\/script>/s', $html, $config_match);
    $config = json_decode($config_match[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    check($config['demoMode'] === true && str_contains($html, 'DEMO MODE'), 'Demo mode is announced clearly');
    check($config['targets'] === ['cup' => 250, 'tumbler' => 500], 'Cup and Tumbler retain 250/500 mL initial defaults');
    check($config['thresholds']['cup']['options'] === [] && $config['thresholds']['tumbler']['options'] === []
        && $config['thresholds']['cup']['min'] === 1 && $config['thresholds']['cup']['max'] === 2000
        && $config['thresholds']['tumbler']['min'] === 1 && $config['thresholds']['tumbler']['max'] === 2000,
        'Each container accepts a whole-number dispensing amount within its configured range');
    check((bool)preg_match('/<input\b[^>]*type="number"[^>]*id="water-threshold-select"/', $html)
        && str_contains($html, 'Dispensing threshold (mL)'), 'The Place stage exposes a numeric fill cutoff');
    check(!preg_match('/reservoir|water.level|low.water|calibration required/i', $html)
        && !isset($config['demoReservoirMl'], $config['lowThreshold'], $config['lowWaterReal']),
        'Public controls and configuration contain no reservoir, water-level, or low-water sensor requirements');
    $activity_position = strpos($html, 'id="activity-logs"');
    $footer_position = strpos($html, 'class="portal-footer"');
    check(str_contains($html, '<th scope="col">Date &amp; time</th>')
        && str_contains($html, 'No activity has been recorded yet.')
        && !str_contains($html, 'class="execution-support-grid"')
        && $activity_position !== false && $footer_position > $activity_position, 'Activity Logs retain date/time in their own view before the copyright footer');
    check(str_contains($html, '/WaterBonia/assets/css/style.css')
        && str_contains($html, '/WaterBonia/assets/js/dispenser.js')
        && str_contains($html, '/WaterBonia/assets/js/portal-navigation.js')
        && $config['statusUrl'] === '/WaterBonia/pages/dispenser-status.php', 'Styles, script, and API endpoint preserve the /WaterBonia/ installation path');
    [$status, $css] = http_request('/assets/css/style.css');
    check($status === 200 && str_contains($css, '.execution-activity-table'), 'Portal stylesheet serves Activity Logs styling');
    [$status, $js] = http_request('/assets/js/dispenser.js');
    $controller_served = $status === 200 && str_contains($js, 'waterbonia.demo.activities.v2');
    [$status, $navigation_js] = http_request('/assets/js/portal-navigation.js');
    check($status === 200 && str_contains($navigation_js, 'data-portal-section')
        && str_contains($navigation_js, 'data-portal-link'), 'Sidebar navigation controller is served for section switching');

    $csrf = $config['csrfToken'];
    [$status, $body] = http_request('/pages/dispense.php', ['action' => 'request_dispense', 'container_type' => 'cup', 'threshold_ml' => 300], null, true);
    check($status === 403 && str_contains($body, 'Invalid CSRF token'), 'Portal form POST rejects missing CSRF before dispensing');
    [$status, $body] = http_request('/pages/dispenser-status.php', ['action' => 'select_threshold', 'container_type' => 'cup', 'threshold_ml' => 300]);
    check($status === 403 && json_decode($body, true)['success'] === false, 'Threshold endpoint rejects a missing CSRF token');
    foreach ([['cup', 275], ['cup', 150], ['tumbler', 650]] as [$type, $value]) {
        [$status, $body] = http_request('/pages/dispenser-status.php', ['action' => 'select_threshold', 'container_type' => $type, 'threshold_ml' => $value], $csrf);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        check($status === 200 && $data['success'] === true && $data['threshold_ml'] === $value
            && $data['target_amount_ml'] === $value, "Server uses {$type} selected amount {$value} mL as its actual target");
    }
    foreach ([-1, 'abc', 0, 2001, 100000, 150.5, true, [], null, '1e2', '150.0'] as $value) {
        [$status, $body] = http_request('/pages/dispenser-status.php', ['action' => 'select_threshold', 'container_type' => 'cup', 'threshold_ml' => $value], $csrf);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        check($status === 422 && $data['success'] === false, 'Server rejects invalid Cup threshold ' . json_encode($value));
    }
    [$status, $body] = http_request('/pages/dispenser-status.php', ['action' => 'select_threshold', 'container_type' => 'bottle', 'threshold_ml' => 300], $csrf);
    check($status === 422 && json_decode($body, true)['success'] === false, 'Server rejects unsupported container types');
    [$status, $html] = http_request('/pages/dispense.php');
    preg_match('/window\.WaterBoniaDispenser=(\{.*?\});<\/script>/s', $html, $config_match);
    $refreshed = json_decode($config_match[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
    check($refreshed['selectedThresholds'] === ['cup' => 150, 'tumbler' => 650], 'Independently selected Cup and Tumbler fill amounts persist after refresh');
    check((bool)preg_match('/id="cup-target-volume"[^>]*>150 mL<\//', $html)
        && (bool)preg_match('/id="tumbler-target-volume"[^>]*>650 mL<\//', $html),
        'Container cards show saved amounts, including a Cup amount below its initial default');
    [$status, $body] = http_request('/pages/dispenser-status.php', ['action' => 'request_dispense', 'container_type' => 'cup', 'threshold_ml' => 300], $csrf);
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check($data['success'] === false && preg_match('/demo|simulated/i', $data['error'] ?? $data['message'] ?? ''), 'Demo mode rejects production dispensing requests');
    [$status, $body] = http_request('/pages/dispenser-status.php');
    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check($status === 200 && $data['success'] === true && isset($data['activities']) && $data['activities'] === [], 'Demo status does not load production hardware activity');
    check(!array_intersect(['low_water_real', 'water_level', 'reservoir', 'reservoir_volume_ml', 'reservoir_volume_note'], array_keys($data['state']))
        && $data['state']['selected_thresholds'] === ['cup' => 150, 'tumbler' => 650],
        'Status exposes saved fill amounts without obsolete reservoir or level fields');
    check($controller_served, 'Portal controller serves isolated demo-history support');
    if (!empty($argv[2])) {
        $portal_test_url = rtrim($argv[2], '/'); $portal_test_cookie = '';
        [$status, $html] = http_request('/pages/dispense.php');
        check($status === 503 && str_contains($html, 'Dispensing is blocked'), 'Hardware-mode page fails closed when MySQL is unavailable');
        check(!preg_match('/DEMO MODE|Demo Prototype Controls|SIMULATED RESERVOIR|id="demo-card"|id="dispense-btn"/', $html), 'Hardware outage page exposes no demo controls/readings or usable dispense button');
        check(!preg_match('/PDOException|SQLSTATE|Fatal error|Warning:|Stack trace/i', $html), 'Hardware outage page does not expose backend exception details');
    foreach (['dispense-workflow', 'container-settings', 'led-card', 'activity-logs', 'safety-sequence'] as $view_id) {
            preg_match('/<[a-z][a-z0-9-]*\b[^>]*\bid="' . preg_quote($view_id, '/') . '"[^>]*>/i', $html, $outage_view_match);
            $view_tag = $outage_view_match[0] ?? '';
            check(preg_match('/\sdata-portal-section(?:\s|=|>)/', $view_tag)
                && (bool)preg_match('/\shidden(?:\s|=|>)/', $view_tag) === ($view_id !== 'dispense-workflow')
                && str_contains($view_tag, 'data-portal-title="'),
                'Hardware outage retains a dedicated unavailable view: ' . $view_id);
        }
        [$status, $body] = http_request('/pages/dispenser-status.php');
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        check($status === 503 && $data['success'] === false && !isset($data['state']) && !isset($data['activities']), 'Hardware status outage returns safe 503 JSON without invented telemetry or activity');
    }
    echo PHP_EOL . $portal_test_count . ' HTTP checks passed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
