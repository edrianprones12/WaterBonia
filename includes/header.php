<?php
/**
 * WaterBonia - Page Header (top bar)
 */
$alert_count = active_alert_count();
$page_title = $page_title ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaterBonia - <?= sanitize($page_title) ?></title>
    <link rel="stylesheet" href="<?= base_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="app-main">
        <header class="top-header">
            <div class="header-left">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                    <span></span><span></span><span></span>
                </button>
                <h1 class="page-title"><?= sanitize($page_title) ?></h1>
            </div>
            <div class="header-right">
                <a href="<?= base_url('/pages/alerts.php') ?>" class="header-alert" title="Active Alerts">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    <?php if ($alert_count > 0): ?>
                        <span class="alert-badge"><?= $alert_count ?></span>
                    <?php endif; ?>
                </a>

            </div>
        </header>
        <main class="content-area">
