-- ============================================================
-- WaterBonia: Dispensing v2 Migration
-- Concept: Choose container → Place → Dispense → Complete
-- Load-cell measured, auto-stop at target.
-- ADDITIVE ONLY — does not drop or alter existing data.
-- ============================================================

-- 1. Extend dispensing_sessions with container type & amounts
ALTER TABLE `dispensing_sessions`
    ADD COLUMN `container_type` ENUM('cup','tumbler') DEFAULT NULL
        AFTER `hand_detected`,
    ADD COLUMN `target_amount_ml` INT UNSIGNED DEFAULT NULL
        AFTER `container_type`,
    ADD COLUMN `measured_amount_ml` INT UNSIGNED DEFAULT NULL
        AFTER `target_amount_ml`;

-- v2 stop reasons: target reached / container removed / command expired
ALTER TABLE `dispensing_sessions`
    MODIFY COLUMN `stop_reason` ENUM(
        'target_reached',
        'container_removed',
        'cup_removed',
        'hand_removed',
        'timeout',
        'low_water',
        'manual_stop',
        'completed',
        'command_expired',
        'error'
    ) DEFAULT NULL;

-- Back-fill legacy rows as cup (type unknown, recorded as cup for continuity)
UPDATE `dispensing_sessions`
   SET `container_type` = 'cup'
 WHERE `container_type` IS NULL;

-- 2. Add container_detected to sensor_readings (presence only, no type)
ALTER TABLE `sensor_readings`
    ADD COLUMN `container_detected` TINYINT(1) DEFAULT 0
        AFTER `cup_detected`;

-- 3. Device command queue (web → ESP32 via polling)
CREATE TABLE IF NOT EXISTS `device_commands` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`      INT UNSIGNED NOT NULL,
    `user_id`        INT UNSIGNED DEFAULT NULL,
    `command_type`   ENUM('dispense','stop','sync') NOT NULL DEFAULT 'dispense',
    `container_type` ENUM('cup','tumbler') DEFAULT NULL,
    `target_amount_ml` INT UNSIGNED DEFAULT NULL,
    `status`         ENUM('pending','claimed','completed','cancelled','expired','rejected')
                     NOT NULL DEFAULT 'pending',
    `requested_at`   DATETIME NOT NULL,
    `claimed_at`     DATETIME DEFAULT NULL,
    `completed_at`   DATETIME DEFAULT NULL,
    `expires_at`     DATETIME NOT NULL,
    `reject_reason`  VARCHAR(191) DEFAULT NULL,
    `session_id`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_device_status` (`device_id`, `status`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_commands_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. System settings: container targets (single source of truth)
-- INSERT IGNORE so re-running the migration never overwrites an admin's edits.
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `value_type`, `description`) VALUES
    ('container_cup_target_ml',    '250', 'integer', 'Target dispense volume for cup (mL)'),
    ('container_tumbler_target_ml','500', 'integer', 'Target dispense volume for tumbler (mL)'),
    ('dispense_command_ttl_sec',   '30',  'integer', 'How long a pending dispense command stays valid (seconds)');
