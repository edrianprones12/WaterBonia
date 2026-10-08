<?php
// TEMPORARY screenshot harness: admin dashboard as admin.
require_once __DIR__ . '/includes/functions.php';
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['login_time'] = time();
require __DIR__ . '/pages/dashboard.php';
