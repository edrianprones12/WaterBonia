<?php
/**
 * WaterBonia - Execution Portal header (user / kiosk facing)
 *
 * The Execution Portal NEVER renders the administrative sidebar.
 * Administration is only reachable through the "Admin portal" button,
 * which points at /admin/.
 *
 * HEADER LAYOUT (Figma-synced):
 *   LEFT:  WaterBonia brand + "Smart Water Dispenser"
 *   RIGHT: SYSTEM AVAILABLE / DEMO MODE pills + Admin portal button
 */
$page_title = $page_title ?? 'Execution Portal';
$availability = $availability ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaterBonia - <?= sanitize($page_title) ?></title>
    <link rel="stylesheet" href="<?= base_url('/assets/css/style.css') ?>">
</head>
<body class="portal-body">
<div class="portal-layout">

    <header class="portal-header">
        <div class="portal-brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                    <path d="M12 2.7c.4 0 .7.2.9.5 2.4 3.6 6.6 8.6 6.6 12.1A7.5 7.5 0 0 1 12 22.8a7.5 7.5 0 0 1-7.5-7.5c0-3.5 4.2-8.5 6.6-12.1.2-.3.5-.5.9-.5z"/>
                </svg>
            </span>
            <span class="brand-text">
                <span class="brand-title">WATERBONIA</span>
                <span class="brand-sub">Smart Water Dispenser</span>
            </span>
        </div>
        <div class="portal-actions">
            <?php if (!empty($availability)): ?>
            <span class="status-pill is-<?= sanitize($availability['tone']) ?>"
                  id="status-pill" title="<?= sanitize($availability['hint']) ?>">
                <span class="pill-dot"></span>
                <span id="status-pill-label"><?= sanitize($availability['label']) ?></span>
            </span>
            <?php endif; ?>
            <span class="status-pill is-demo" id="demo-pill" title="Simulation — No physical hardware connected">
                <span class="pill-dot"></span>
                <span>DEMO MODE</span>
            </span>
            <a class="btn btn-outline btn-portal" href="<?= base_url('/admin/') ?>">Admin portal</a>
        </div>
    </header>

    <main class="portal-main">