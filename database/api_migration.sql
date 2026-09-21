-- ============================================================
-- WaterBonia - API Migration: device_tokens table
-- ============================================================
-- Run this AFTER waterbonia.sql
-- ============================================================

-- Ensure esp32_identifier is truly unique for API safety
-- (index already exists; add unique constraint if not present)
ALTER TABLE `devices`
  ADD UNIQUE KEY `uq_devices_esp32_identifier` (`esp32_identifier`);

-- ============================================================
-- 8. DEVICE TOKENS
-- ============================================================
CREATE TABLE `device_tokens` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `device_id`     INT UNSIGNED    NOT NULL,
  `token_hash`    VARCHAR(255)    NOT NULL,
  `token_name`    VARCHAR(100)    NOT NULL DEFAULT '',
  `last_used_at`  DATETIME        NULL     DEFAULT NULL,
  `expires_at`    DATETIME        NULL     DEFAULT NULL,
  `status`        ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at`    DATETIME        NULL     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dt_device_id` (`device_id`),
  KEY `idx_dt_token_hash` (`token_hash`),
  KEY `idx_dt_status` (`status`),
  CONSTRAINT `fk_dt_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
