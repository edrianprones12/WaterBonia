<?php
/**
 * WaterBonia - Execution Portal header (user / kiosk facing)
 *
 * The Execution Portal is the primary public dispensing experience.
 *
 * LAYOUT: One sidebar brand and compact page/status toolbar.
 *   LEFT:  Current section title
 *   RIGHT: actual device status
 */
$page_title = $page_title ?? 'Water Dispensing System';
$availability = $availability ?? null;
$portal_navigation_items = [
    ['label' => 'Dispense Water', 'anchor' => 'dispense-workflow', 'icon' => 'M6 4h10v13a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V4Z M16 7h2a4 4 0 0 1 0 8h-2'],
    ['label' => 'Container Settings', 'anchor' => 'container-settings', 'icon' => 'M4 7h16 M4 17h16 M8 4v6 M16 14v6'],
    ['label' => 'Device Status', 'anchor' => 'led-card', 'icon' => 'M5 3h14v18H5V3Z M9 7h6 M9 17h6 M10 11h4v3h-4Z'],
    ['label' => 'Activity Logs', 'anchor' => 'activity-logs', 'icon' => 'M8 3H5v18h14V3h-3 M8 2h8v4H8V2Z M8 10h8 M8 14h8 M8 18h5'],
    ['label' => 'Safety Sequence', 'anchor' => 'safety-sequence', 'icon' => 'M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Z M8 12l3 3 5-6'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaterBonia - <?= sanitize($page_title) ?></title>
    <link rel="stylesheet" href="<?= base_url('/assets/css/style.css?v=' . filemtime(__DIR__ . '/../assets/css/style.css')) ?>">
</head>
<body class="portal-body">
<a class="portal-skip-link" href="#execution-main">Skip to content</a>
<div class="portal-layout">
    <aside class="portal-navigation" id="portal-navigation" aria-label="WaterBonia navigation">
        <div class="portal-navigation-brand"><span class="portal-navigation-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="25" height="25" fill="currentColor"><path d="M12 2c3 5 7 9 7 13a7 7 0 0 1-14 0c0-4 4-8 7-13Z"/></svg></span><strong>WATERBONIA</strong><span>Water Dispensing System</span></div>
        <?php foreach (['desktop', 'mobile'] as $portal_navigation_surface): ?>
        <?php if ($portal_navigation_surface === 'mobile'): ?><details class="portal-navigation-mobile"><summary><span>Sections</span><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16 M4 12h16 M4 18h16"/></svg></summary><?php endif; ?>
        <nav class="portal-navigation-links <?= $portal_navigation_surface === 'desktop' ? 'portal-navigation-desktop' : '' ?>" aria-label="WaterBonia sections">
            <?php foreach ($portal_navigation_items as $portal_navigation_item): ?>
            <a href="<?= base_url('/pages/dispense.php#' . $portal_navigation_item['anchor']) ?>" data-portal-link="<?= $portal_navigation_item['anchor'] ?>" aria-controls="<?= $portal_navigation_item['anchor'] ?>"<?= $portal_navigation_item['anchor'] === 'dispense-workflow' ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $portal_navigation_item['icon'] ?>"/></svg><span><?= sanitize($portal_navigation_item['label']) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($portal_navigation_surface === 'mobile'): ?></details><?php endif; ?>
        <?php endforeach; ?>
    </aside>

    <header class="portal-header">
        <p class="portal-page-heading" id="portal-page-heading">Dispense Water</p>
        <div class="portal-actions">
            <a class="btn btn-outline" href="<?= base_url('/pages/dashboard.php') ?>">Dashboard</a>
            <?php if (!empty($availability)): ?>
            <span class="status-pill is-<?= sanitize($availability['tone']) ?>"
                  id="status-pill" title="<?= sanitize($availability['hint']) ?>">
                <span class="pill-dot"></span>
                <span id="status-pill-label"><?= sanitize($availability['label']) ?></span>
            </span>
            <?php endif; ?>

        </div>
    </header>

    <main class="portal-main" id="execution-main" tabindex="-1">
