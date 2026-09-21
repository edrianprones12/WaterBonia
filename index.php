<?php
/**
 * WaterBonia - Main Router
 */
require_once __DIR__ . '/includes/functions.php';

if (!logged_in()) {
    redirect('/auth/login.php');
}

redirect('/pages/dashboard.php');
