<?php
/**
 * WaterBonia - Sidebar Navigation
 */
$current = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

$nav_groups = [
    'MAIN' => [
        ['slug' => 'dashboard',   'label' => 'Dashboard',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>'],
        ['slug' => 'water-level', 'label' => 'Water Level', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/></svg>'],
        ['slug' => 'dispensing',  'label' => 'Dispensing',  'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>'],
        ['slug' => 'device-status','label' => 'Device Status','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z"/></svg>'],
    ],
    'MONITORING' => [
        ['slug' => 'activity-logs','label' => 'Activity Logs','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'],
        ['slug' => 'alerts',      'label' => 'Alerts',      'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'],
        ['slug' => 'analytics',   'label' => 'Analytics',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>'],
    ],
    'SYSTEM' => [
        ['slug' => 'devices',     'label' => 'Devices',     'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>'],
        ['slug' => 'settings',    'label' => 'Settings',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>'],
        ['slug' => 'help',        'label' => 'Help / About', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'],
    ],
];
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">💧</div>
        <div class="brand-text">
            <span class="brand-name">WATERBONIA</span>
            <span class="brand-sub">Smart Water Dispenser</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($nav_groups as $group_label => $items): ?>
            <div class="nav-group">
                <span class="nav-group-label"><?= $group_label ?></span>
                <?php foreach ($items as $item): ?>
                    <a href="<?= base_url('/pages/' . $item['slug'] . '.php') ?>"
                       class="nav-link <?= $current === $item['slug'] ? 'active' : '' ?>">
                        <span class="nav-icon"><?= $item['icon'] ?></span>
                        <span class="nav-text"><?= $item['label'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span class="version">v0.1.0-dev</span>
    </div>
</aside>
