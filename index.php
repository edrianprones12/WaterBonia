<?php
/**
 * WaterBonia - Root router
 *
 * "/"  →  EXECUTION PORTAL (user dispensing experience)
 *
 * The Execution Portal is public and opens without a login.
 */
require_once __DIR__ . '/includes/functions.php';

redirect('/pages/dispense.php');
