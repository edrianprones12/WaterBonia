-- ============================================================
-- WaterBonia - IoT Smart Water Dispenser Database
-- ============================================================
-- Database: waterbonia
-- Engine:  InnoDB
-- Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS `waterbonia`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `waterbonia`;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE `users` (
  `id`            INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)         NOT NULL,
  `email`         VARCHAR(255)        NOT NULL,
  `password_hash` VARCHAR(255)        NOT NULL,
  `full_name`     VARCHAR(150)        NOT NULL DEFAULT '',
  `role`          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  `status`        ENUM('active','inactive')        NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME            NULL     DEFAULT NULL,
  `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. DEVICES
-- ============================================================
CREATE TABLE `devices` (
  `id`                 INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  `device_code`        VARCHAR(50)        NOT NULL,
  `device_name`        VARCHAR(150)       NOT NULL DEFAULT '',
  `location`           VARCHAR(255)       NOT NULL DEFAULT '',
  `esp32_identifier`   VARCHAR(100)       NOT NULL DEFAULT '',
  `status`             ENUM('online','offline','maintenance','disabled') NOT NULL DEFAULT 'offline',
  `firmware_version`   VARCHAR(30)        NOT NULL DEFAULT '',
  `ip_address`         VARCHAR(45)        NOT NULL DEFAULT '',
  `wifi_ssid`          VARCHAR(100)       NOT NULL DEFAULT '',
  `last_seen_at`       DATETIME           NULL     DEFAULT NULL,
  `created_at`         DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_device_code` (`device_code`),
  KEY `idx_devices_status` (`status`),
  KEY `idx_devices_esp32_identifier` (`esp32_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. SENSOR_READINGS
-- ============================================================
CREATE TABLE `sensor_readings` (
  `id`                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `device_id`            INT UNSIGNED     NOT NULL,
  `water_level_percent`  TINYINT UNSIGNED NULL     DEFAULT NULL,
  `cup_detected`         TINYINT(1)       NOT NULL DEFAULT 0,
  `hand_detected`        TINYINT(1)       NOT NULL DEFAULT 0,
  `pump_status`          TINYINT(1)       NOT NULL DEFAULT 0,
  `battery_percent`      TINYINT UNSIGNED NULL     DEFAULT NULL,
  `recorded_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_device_id` (`device_id`),
  KEY `idx_sr_recorded_at` (`recorded_at`),
  CONSTRAINT `fk_sr_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. DISPENSING_SESSIONS
-- ============================================================
CREATE TABLE `dispensing_sessions` (
  `id`                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `device_id`            INT UNSIGNED     NOT NULL,
  `started_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`             DATETIME         NULL     DEFAULT NULL,
  `duration_seconds`     INT UNSIGNED     NULL     DEFAULT NULL,
  `cup_detected`         TINYINT(1)       NOT NULL DEFAULT 0,
  `hand_detected`        TINYINT(1)       NOT NULL DEFAULT 0,
  `water_level_before`   TINYINT UNSIGNED NULL     DEFAULT NULL,
  `water_level_after`    TINYINT UNSIGNED NULL     DEFAULT NULL,
  `amount_dispensed_ml`  INT UNSIGNED     NULL     DEFAULT NULL,
  `stop_reason`          ENUM('cup_removed','hand_removed','timeout','low_water','manual_stop','completed','error') NULL DEFAULT NULL,
  `status`               ENUM('started','completed','interrupted','failed') NOT NULL DEFAULT 'started',
  PRIMARY KEY (`id`),
  KEY `idx_ds_device_id` (`device_id`),
  KEY `idx_ds_started_at` (`started_at`),
  KEY `idx_ds_status` (`status`),
  CONSTRAINT `fk_ds_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. ACTIVITY_LOGS
-- ============================================================
CREATE TABLE `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   INT UNSIGNED    NULL     DEFAULT NULL,
  `user_id`     INT UNSIGNED    NULL     DEFAULT NULL,
  `event_type`  VARCHAR(50)     NOT NULL,
  `event_level` ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
  `message`     TEXT            NOT NULL,
  `metadata`    JSON            NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_device_id` (`device_id`),
  KEY `idx_al_user_id` (`user_id`),
  KEY `idx_al_created_at` (`created_at`),
  KEY `idx_al_event_type` (`event_type`),
  CONSTRAINT `fk_al_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. ALERTS
-- ============================================================
CREATE TABLE `alerts` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`         INT UNSIGNED    NULL     DEFAULT NULL,
  `alert_type`        VARCHAR(50)     NOT NULL,
  `severity`          ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  `title`             VARCHAR(200)    NOT NULL,
  `message`           TEXT            NOT NULL,
  `status`            ENUM('active','acknowledged','resolved') NOT NULL DEFAULT 'active',
  `triggered_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at`   DATETIME        NULL     DEFAULT NULL,
  `acknowledged_by`   INT UNSIGNED    NULL     DEFAULT NULL,
  `resolved_at`       DATETIME        NULL     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alerts_device_id` (`device_id`),
  KEY `idx_alerts_status` (`status`),
  KEY `idx_alerts_alert_type` (`alert_type`),
  KEY `idx_alerts_triggered_at` (`triggered_at`),
  KEY `idx_alerts_acknowledged_by` (`acknowledged_by`),
  CONSTRAINT `fk_alerts_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_alerts_acknowledged_by` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. SYSTEM_SETTINGS
-- ============================================================
CREATE TABLE `system_settings` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100)   NOT NULL,
  `setting_value` TEXT           NOT NULL,
  `value_type`    ENUM('string','integer','float','boolean','json') NOT NULL DEFAULT 'string',
  `description`   VARCHAR(500)   NOT NULL DEFAULT '',
  `updated_by`    INT UNSIGNED   NULL     DEFAULT NULL,
  `updated_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SAMPLE DATA (development only)
-- ============================================================

-- Administrator (see database/README.md for default credentials)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `status`)
VALUES (
  'admin_waterbonia',
  'admin@waterbonia.local',
  '$2y$10$b3BMeGKVSBUBsXEIxvQR9urNnUpgHERmp.cjWznSmUTB2jsxgd4L.',
  'System Administrator',
  'admin',
  'active'
);

-- Sample device
INSERT INTO `devices` (`device_code`, `device_name`, `location`, `esp32_identifier`, `status`, `firmware_version`)
VALUES (
  'WB-DEV-001',
  'WaterBonia Dispenser Alpha',
  'Main Office',
  'ESP32-WB-001',
  'offline',
  '0.1.0'
);

-- Default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `value_type`, `description`) VALUES
('system_name',                       'WaterBonia',           'string',  'Display name of the system'),
('timezone',                          'Asia/Manila',          'string',  'Default timezone for timestamps'),
('low_water_threshold',               '20',                   'integer', 'Water level percent that triggers a low-water alert'),
('dispensing_timeout_seconds',        '30',                   'integer', 'Maximum seconds a dispensing session may run before timeout'),
('device_offline_timeout_seconds',    '120',                  'integer', 'Seconds after last_seen_at before a device is considered offline (should be > heartbeat interval)'),
('default_water_capacity_ml',         '5000',                 'integer', 'Assumed tank capacity in millilitres');
