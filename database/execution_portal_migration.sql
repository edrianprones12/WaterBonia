-- WaterBonia per-container dispensing cutoffs. Apply after dispensing_v2_migration.sql.
-- Additive and safe to re-run: preserves configured values and all history.
USE `waterbonia`;

INSERT IGNORE INTO `system_settings` (`setting_key`,`setting_value`,`value_type`,`description`) VALUES
('container_cup_fill_default_ml','250','integer','Default Cup dispensing cutoff (mL)'),
('container_cup_fill_min_ml','1','integer','Minimum Cup dispensing cutoff (whole mL)'),
('container_cup_fill_max_ml','2000','integer','Maximum Cup dispensing cutoff (whole mL)'),
('container_tumbler_fill_default_ml','500','integer','Default Tumbler dispensing cutoff (mL)'),
('container_tumbler_fill_min_ml','1','integer','Minimum Tumbler dispensing cutoff (whole mL)'),
('container_tumbler_fill_max_ml','2000','integer','Maximum Tumbler dispensing cutoff (whole mL)'),
('portal_telemetry_max_age_seconds','30','integer','Maximum installed sensor reading age for dispensing authorization (seconds)');

-- No schema columns or historical readings are deleted. Legacy reservoir settings
-- remain preserved when present and are ignored by the dispensing cutoff code.
