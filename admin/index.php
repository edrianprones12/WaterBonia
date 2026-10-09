<?php
/**
 * Retired public portal route. Shared authentication and protected
 * maintenance pages remain available for existing backend operations.
 */
require_once __DIR__ . '/../includes/functions.php';

redirect('/pages/dispense.php');
