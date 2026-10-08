<?php
require_once __DIR__ . '/includes/functions.php';

if (logged_in()) {
    log_event(null, $_SESSION['user_id'], 'user_logout', 'info', 'User logged out');
}

session_destroy();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
// Logout always returns to the Admin Portal entry point (admin login).
redirect('/admin/');
