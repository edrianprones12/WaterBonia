<?php
// TEMPORARY screenshot harness: admin dashboard as admin.
// Development harnesses must never create authenticated public web sessions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/functions.php';
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['login_time'] = time();
require __DIR__ . '/pages/dashboard.php';
