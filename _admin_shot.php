<?php
// TEMPORARY screenshot harness: dashboard.
// Development harnesses must never create public web sessions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/includes/functions.php';
require __DIR__ . '/pages/dashboard.php';
