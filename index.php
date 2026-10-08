<?php
/**
 * WaterBonia - Root router
 *
 * "/"  →  EXECUTION PORTAL (user dispensing experience)
 *
 * The Execution Portal is public: it does not require an administrator
 * login. Administration is reached through the "Admin portal" button,
 * which points at /admin/.
 */
require_once __DIR__ . '/includes/functions.php';

redirect('/pages/dispense.php');
