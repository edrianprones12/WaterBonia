<?php
// TEMPORARY harness — Execution Portal demo flow (guest, no admin session).
require_once __DIR__ . '/includes/functions.php';
// deliberately NOT logging in: the execution portal must work for a guest
ob_start();
require __DIR__ . '/pages/dispense.php';
$html = ob_get_clean();
echo $html;
?>
<script>
(function () {
    var res = [];
    function log(k, v) { res.push(k + '=' + v); }
    function $(s) { return document.querySelector(s); }
    function txt(s) { var e = $(s); return e ? e.textContent.trim() : 'MISSING'; }
    function state() {
        var active = document.querySelector('.dispense-step.is-active');
        return {
            step: active ? active.dataset.step : '-',
            btn: $('#dispense-btn').disabled,
            chip: txt('#dispenser-live-state'),
            led: txt('#led-label'),
            pill: txt('#status-pill-label'),
            current: txt('#ro-current'),
            progress: txt('#ro-progress'),
            pump: txt('#ro-pump'),
            title: txt('#complete-title'),
            reasonLabel: txt('#co-reason-label'),
            reason: txt('#co-reason'),
            banner: !$('#low-water-banner').hidden,
            sensor: txt('#container-sensor-status'),
            sidebar: !!document.getElementById('sidebar'),
            portalBtn: !!document.querySelector('.portal-actions a[href*="/admin/"]'),
            demoCard: !!document.getElementById('demo-card'),
            espOnline: document.body.innerText.indexOf('ESP32 ONLINE') >= 0,
            sysAvailable: document.body.innerText.indexOf('SYSTEM AVAILABLE') >= 0,
            stateDebug: /State:\s*[A-Z_]+/.test(document.body.innerText)
        };
    }
    log('initial', JSON.stringify(state()));

    document.querySelector('.container-card[data-container="cup"]').click();
    log('afterChoose', JSON.stringify(state()));

    document.querySelector('[data-demo="container_on"]').click();
    log('afterSimDetect', JSON.stringify(state()));

    $('#dispense-btn').click();
    log('afterDispense', JSON.stringify(state()));

    setTimeout(function () { log('midProgress', JSON.stringify(state())); }, 3000);

    setTimeout(function () {
        log('complete', JSON.stringify(state()));
        $('#restart-btn').click();
        log('afterRestart', JSON.stringify(state()));

        document.querySelector('[data-demo="reset"]').click();
        document.querySelector('.container-card[data-container="cup"]').click();
        document.querySelector('[data-demo="container_on"]').click();
        document.querySelector('[data-demo="low_water"]').click();
        log('lowWater', JSON.stringify(state()));

        document.querySelector('[data-demo="reset"]').click();
        document.querySelector('[data-demo="offline"]').click();
        log('offline', JSON.stringify(state()));

        document.querySelector('[data-demo="reset"]').click();
        document.querySelector('.container-card[data-container="tumbler"]').click();
        document.querySelector('[data-demo="container_on"]').click();
        $('#dispense-btn').click();
        document.querySelector('[data-demo="container_off"]').click();
        log('interrupted', JSON.stringify(state()));

        document.querySelector('[data-demo="reset"]').click();
        log('afterReset', JSON.stringify(state()));

        var div = document.createElement('div');
        div.id = 'flow-test-results';
        div.textContent = res.join('\n');
        document.body.appendChild(div);
    }, 9000);
})();
</script>
