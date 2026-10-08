<?php
/**
 * WaterBonia - ADMIN PORTAL entry
 *
 * "/admin/"  →  admin login (signed out)  |  admin dashboard (signed in)
 *
 * The Admin Portal keeps the existing authentication, session management
 * and role authorization unchanged — this file only provides a single,
 * clearly separated entry point for it.
 */
require_once __DIR__ . '/../includes/functions.php';

if (logged_in()) {
    redirect('/pages/dashboard.php');
}

redirect('/auth/login.php');
