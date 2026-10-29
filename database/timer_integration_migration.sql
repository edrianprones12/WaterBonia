-- Apply once AFTER all three existing migrations; back up the live DB first.
-- Does not rewrite historical volume readings or start any physical operation.
USE waterbonia;
ALTER TABLE devices
 ADD COLUMN boot_id VARCHAR(32) NULL,
 ADD COLUMN report_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN last_heartbeat_at DATETIME NULL;
ALTER TABLE sensor_readings
 MODIFY COLUMN container_detected TINYINT(1) NULL DEFAULT NULL,
 ADD COLUMN sensor_status ENUM('ok','unavailable','fault') NOT NULL DEFAULT 'unavailable',
 ADD COLUMN boot_id VARCHAR(32) NULL,
 ADD COLUMN report_sequence BIGINT UNSIGNED NULL,
 ADD COLUMN active_command_id BIGINT UNSIGNED NULL,
 ADD COLUMN pump_elapsed_ms INT UNSIGNED NULL,
 ADD UNIQUE KEY uq_timer_sample(device_id,boot_id,report_sequence);
ALTER TABLE device_commands
 ADD COLUMN duration_ms INT UNSIGNED NULL,
 ADD COLUMN request_id VARCHAR(64) NULL,
 ADD COLUMN claim_boot_id VARCHAR(32) NULL,
 ADD UNIQUE KEY uq_timer_request(device_id,user_id,request_id),
 MODIFY COLUMN status ENUM('pending','claimed','running','completed','cancelled','expired','rejected','failed','stopped') NOT NULL DEFAULT 'pending';
ALTER TABLE dispensing_sessions
 ADD COLUMN configured_duration_ms INT UNSIGNED NULL,
 ADD COLUMN pump_elapsed_ms INT UNSIGNED NULL,
 ADD COLUMN device_started_at DATETIME NULL,
 ADD COLUMN device_finished_at DATETIME NULL,
 ADD COLUMN result_received_at DATETIME NULL,
 MODIFY COLUMN stop_reason ENUM('target_reached','container_removed','cup_removed','hand_removed','timeout','low_water','manual_stop','completed','error','command_expired','duration_elapsed','sensor_fault','max_runtime_exceeded','device_reset') NULL DEFAULT NULL;
INSERT IGNORE INTO system_settings(setting_key,setting_value,value_type,description) VALUES
 ('portal_device_id','0','integer','Explicit real dispenser device ID; zero disables real dispensing'),
 ('container_cup_duration_seconds','0','integer','Calibrated Cup runtime, 1-30 seconds; zero disables'),
 ('container_tumbler_duration_seconds','0','integer','Calibrated Tumbler runtime, 1-30 seconds; zero disables');
-- Existing freshness configuration is retained; timer guards additionally cap it.
