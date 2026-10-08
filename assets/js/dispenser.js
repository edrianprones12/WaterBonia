/* WaterBonia execution portal state controller. */
(function () {
    'use strict';
    const cfg = window.WaterBoniaDispenser || {};
    const demoMode = !!cfg.demoMode;
    const targets = cfg.targets || { cup: 250, tumbler: 500 };
    const el = (id) => document.getElementById(id);
    const panels = document.querySelectorAll('.dispense-panel');
    const cards = document.querySelectorAll('.container-card');
    const rail = el('dispense-steps');
    const button = el('dispense-btn');
    let selected = null, currentStep = 1, polling = null, sending = false, hadSession = false;

    const demo = { phase: 'choose', type: null, container: false, low: false, offline: false, pumping: false, current: 0, target: 0, timers: [] };
    const LED = {
        blue: ['#2563eb', 'BLUE — READY / WAITING', 'The dispenser is idle and waiting for a container.'],
        cyan: ['#06b6d4', 'CYAN — CONTAINER DETECTED', 'A container is detected on the dispenser platform.'],
        green: ['#16a34a', 'GREEN — DISPENSING', 'The pump is running toward the selected target.'],
        white: ['#e2e8f0', 'WHITE — COMPLETE', 'The target volume was reached and the pump is off.'],
        yellow: ['#eab308', 'YELLOW — LOW WATER', 'Refill the water reservoir before dispensing.'],
        red: ['#ef4444', 'RED — ERROR / INTERRUPTED', 'Dispensing is stopped until the issue is resolved.']
    };

    function title(value) { return value ? value.charAt(0).toUpperCase() + value.slice(1) : '—'; }
    function target() { return targets[selected] || targets.cup || 250; }
    function setText(id, value) { const node = el(id); if (node) node.textContent = value; }
    function setStep(step) {
        currentStep = step;
        panels.forEach((panel) => { panel.hidden = Number(panel.dataset.panel) !== step; });
        if (!rail) return;
        rail.querySelectorAll('.dispense-step').forEach((node) => {
            const n = Number(node.dataset.step);
            node.classList.toggle('is-active', n === step);
            node.classList.toggle('is-done', n < step || (step === 4 && n === 4));
        });
        rail.querySelectorAll('.step-connector').forEach((node) => node.classList.toggle('is-done', Number(node.dataset.step) <= step));
    }
    function updateContainerCopy() {
        const name = title(selected);
        const amount = target();
        setText('place-container-name', name);
        setText('place-container-target', 'Target: ' + amount + ' mL');
        setText('place-description', 'Place your selected ' + (selected || 'container') + ' on the dispenser platform.');
        setText('dispense-container', name);
        setText('dispense-target-label', 'Target: ' + amount + ' mL');
        setText('target-value', amount);
    }
    function renderLed(key) {
        const state = LED[key] || LED.blue;
        if (el('led-dot')) el('led-dot').style.background = state[0];
        setText('led-label', state[1]); setText('led-desc', state[2]);
    }
    function renderProgress(current, total, pump, system) {
        const pct = total ? Math.max(0, Math.min(100, Math.round((current / total) * 100))) : 0;
        if (el('progress-fill')) el('progress-fill').style.width = pct + '%';
        setText('measured-value', current); setText('target-value', total); setText('progress-percent', pct + '%');
        setText('ro-container', title(selected)); setText('ro-target', total + ' mL'); setText('ro-pump', pump ? 'ON' : 'OFF'); setText('ro-system', system);
    }
    function renderPlace(detected, message) {
        const indicator = el('place-indicator');
        if (indicator) indicator.classList.toggle('is-detected', detected);
        setText('container-sensor-status', detected ? 'Container detected' : 'Waiting for container');
        setText('place-heading', detected ? 'Container ready' : 'Place your container');
        setText('place-description', detected ? 'Container detected. You can now start dispensing.' : 'Place your selected ' + (selected || 'container') + ' on the dispenser platform.');
        setText('dispense-hint', message || (detected ? 'Ready to dispense.' : 'Presence sensor on dispenser platform'));
    }
    function setButton(enabled) { if (button) button.disabled = !enabled; }
    function resetSelection() {
        selected = null;
        cards.forEach((card) => { card.classList.remove('is-selected'); const input = card.querySelector('input'); if (input) input.checked = false; });
        setStep(1);
    }
    function complete(interrupted, detail) {
        setStep(4);
        const amount = demoMode ? demo.target : target();
        setText('complete-title', interrupted ? 'DISPENSING INTERRUPTED' : 'DISPENSING COMPLETE');
        setText('complete-heading', interrupted ? 'Dispensing stopped safely.' : 'Your water is ready.');
        setText('complete-detail', detail || (interrupted ? 'Resolve the issue, then return to a safe state.' : 'The requested target has been reached.'));
        setText('complete-icon', interrupted ? '!' : '✓');
        setText('co-container', title(demoMode ? demo.type : selected));
        setText('co-target', amount + ' mL'); setText('co-measured', (demoMode ? demo.current : amount) + ' mL');
        setText('co-pump', 'OFF'); setText('co-reason', interrupted ? 'RED / INTERRUPTED' : 'WHITE / GREEN FLASH');
        if (el('complete-note')) el('complete-note').hidden = interrupted;
        renderLed(interrupted ? 'red' : 'white');
    }

    cards.forEach((card) => card.addEventListener('click', () => {
        selected = card.dataset.container;
        cards.forEach((item) => { item.classList.toggle('is-selected', item === card); const input = item.querySelector('input'); if (input) input.checked = item === card; });
        updateContainerCopy(); setStep(2);
        if (demoMode) { demo.type = selected; demo.phase = 'place'; renderDemo(); }
        else renderPlace(false, 'Waiting for a container on the platform.');
    }));

    function demoClear() { demo.timers.forEach(clearTimeout); demo.timers = []; }
    function demoRenderLed() {
        renderLed(demo.offline || demo.phase === 'interrupted' ? 'red' : demo.low ? 'yellow' : demo.pumping ? 'green' : demo.phase === 'complete' ? 'white' : demo.container ? 'cyan' : 'blue');
    }
    function renderDemo() {
        demoRenderLed();
        const ready = !!selected && demo.container && !demo.low && !demo.offline && !demo.pumping && demo.phase !== 'complete' && demo.phase !== 'interrupted';
        if (currentStep === 2) {
            let note = demo.container ? 'Ready to dispense.' : 'Use “Simulate container detected” to continue.';
            if (demo.low) note = 'Low water simulated — dispensing is unavailable.';
            if (demo.offline) note = 'Device offline simulated — dispensing is unavailable.';
            renderPlace(demo.container, note);
        }
        setButton(ready);
    }
    function demoStart() {
        if (!selected || !demo.container || demo.low || demo.offline || demo.pumping) return;
        demo.type = selected; demo.target = target(); demo.current = 0; demo.phase = 'dispensing'; demo.pumping = true;
        setStep(3); renderDemo(); renderProgress(0, demo.target, true, 'GREEN');
        [0, 20, 40, 60, 80, 90, 100].forEach((pct, index) => {
            demo.timers.push(setTimeout(() => {
                if (!demo.pumping) return;
                demo.current = Math.round(demo.target * pct / 100);
                renderProgress(demo.current, demo.target, true, 'GREEN');
                if (pct === 100) { demoClear(); demo.pumping = false; demo.phase = 'complete'; complete(false); }
            }, index * 900));
        });
    }
    function demoInterrupt(message) { demoClear(); demo.pumping = false; demo.phase = 'interrupted'; complete(true, message); }
    function demoReset() {
        demoClear(); Object.assign(demo, { phase: 'choose', type: null, container: false, low: false, offline: false, pumping: false, current: 0, target: 0 });
        resetSelection(); renderLed('blue');
    }
    function demoControl(action) {
        if (action === 'reset') return demoReset();
        if (action === 'container_on') { demo.container = true; if (demo.phase === 'interrupted') demo.phase = 'place'; }
        if (action === 'container_off') { demo.container = false; if (demo.pumping) return demoInterrupt('The container was removed before the target was reached.'); }
        if (action === 'low_water') { demo.low = true; if (demo.pumping) return demoInterrupt('Low water was detected. The pump was stopped safely.'); }
        if (action === 'offline') { demo.offline = true; if (demo.pumping) return demoInterrupt('The simulated device went offline. The pump was stopped safely.'); }
        if (action === 'target') { if (demo.pumping) { demo.current = demo.target; demo.pumping = false; demo.phase = 'complete'; demoClear(); complete(false); } }
        if (action === 'next') {
            if (!selected) return;
            if (!demo.container) demo.container = true;
            else if (!demo.pumping && demo.phase !== 'complete') demoStart();
            else if (demo.phase === 'complete') demoReset();
        }
        renderDemo();
    }

    function renderAvailability(state) {
        const pill = el('status-pill'), label = el('status-pill-label');
        if (!pill || !label || !state) return;
        pill.className = 'status-pill is-' + (state.tone || 'neutral'); label.textContent = state.label || 'SYSTEM AVAILABLE';
    }
    function renderLive(state) {
        renderAvailability(state.availability || cfg.availability);
        const led = state.led || cfg.led;
        if (led) { if (el('led-dot')) el('led-dot').style.background = led.hex; setText('led-label', led.label); setText('led-desc', led.description); }
        if (!selected) return;
        const waterOk = state.water_level === null || state.water_level > (cfg.lowThreshold || 20);
        if (state.has_session) {
            hadSession = true; setStep(3);
            const session = state.session || {}, amount = Number(session.measured_amount_ml || 0), goal = Number(session.target_amount_ml || target());
            if (session.container_type) selected = session.container_type;
            updateContainerCopy(); renderProgress(amount, goal, !!state.pump_status, state.pump_status ? 'GREEN' : 'READY'); return;
        }
        if (hadSession && state.last_session) { hadSession = false; complete(false); return; }
        if (state.has_pending_command) { setStep(3); renderProgress(0, target(), false, 'QUEUED'); return; }
        setStep(2);
        const message = !state.device_online ? 'Device is offline — dispensing unavailable.' : !waterOk ? 'Water level is too low to dispense.' : state.container_detected ? 'Ready to dispense.' : 'Waiting for a container on the platform.';
        renderPlace(!!state.container_detected, message); setButton(!!state.container_detected && state.device_online && waterOk && !sending);
    }
    function requestDispense() {
        if (!selected || sending) return;
        sending = true; setButton(false); setText('dispense-hint', 'Sending request to dispenser…');
        fetch(cfg.statusUrl, { method: 'POST', headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify({action: 'request_dispense', container_type: selected, csrf_token: cfg.csrfToken}) })
            .then((response) => response.json()).then((data) => { sending = false; if (data.success) { setStep(3); } else setText('dispense-hint', data.error || data.message || 'Request failed.'); pollNow(); })
            .catch(() => { sending = false; setText('dispense-hint', 'Network error — could not send request.'); });
    }
    function pollNow() { fetch(cfg.statusUrl, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then((r) => r.json()).then((data) => { if (data.success && data.state) renderLive(data.state); }).catch(() => {}); }
    function startPolling() { if (demoMode || polling) return; pollNow(); polling = setInterval(pollNow, 2000); }

    if (button) button.addEventListener('click', () => demoMode ? demoStart() : requestDispense());
    [el('done-btn'), el('restart-btn')].forEach((node) => node && node.addEventListener('click', () => { if (demoMode) demoReset(); else { hadSession = false; resetSelection(); } }));
    document.querySelectorAll('[data-demo]').forEach((node) => node.addEventListener('click', () => demoControl(node.dataset.demo)));
    document.addEventListener('visibilitychange', () => { if (document.hidden && polling) { clearInterval(polling); polling = null; } else if (!document.hidden) startPolling(); });
    renderAvailability(cfg.availability); renderLed('blue'); setStep(1); if (demoMode) renderDemo(); else startPolling();
}());
