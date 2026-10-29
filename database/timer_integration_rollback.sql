-- Maintenance-only rollback. Export timer operation evidence before running.
-- Restore application backup too. Never run while any operation is unresolved.
USE waterbonia;
-- New columns and historical results are deliberately retained to avoid data loss.
-- Disabling real mode is the safe reversible rollback; DDL removal is unnecessary.
UPDATE system_settings SET setting_value='0' WHERE setting_key IN
 ('portal_device_id','container_cup_duration_seconds','container_tumbler_duration_seconds');
