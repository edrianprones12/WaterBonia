/* WaterBonia execution portal: demo state stays in the browser; hardware state comes from the server. */
(function () {
    'use strict';
    const cfg = window.WaterBoniaDispenser || {};
    const demoMode = cfg.demoMode === true;
    const targets = cfg.targets || {};
    const thresholds = cfg.thresholds || {};
    const thresholdValues = Object.assign({}, targets, cfg.selectedThresholds || {});
    const fillVersions = { cup: 0, tumbler: 0 };
    const el = (id) => document.getElementById(id);
    const panels = document.querySelectorAll('.dispense-panel');
    const cards = document.querySelectorAll('.container-card');
    const button = el('dispense-btn');
    const thresholdSelect = el('water-threshold-select');
    const HISTORY_KEY = 'waterbonia.demo.activities.v2';
    const HISTORY_LIMIT = 100;
    let selected = null, currentStep = 1, thresholdPending = false, thresholdValid = false, thresholdError = '';
    let selectionVersion = 0, polling = null, pollBusy = false, sending = false, connectionFailed = !demoMode, requestError = '';
    let liveState = null, activeSessionId = null, awaitingSession = false;
    let operationTarget = null, requestCommandId = null;
    let visibleActivities = 10, activities = [];
    const demo = { phase: 'choose', type: null, container: false, offline: false, pumping: false, current: 0, target: 0, timers: [] };
    const LED = {
        blue: ['#2563eb', 'BLUE — READY / WAITING', 'The simulated dispenser is waiting for a container.'],
        cyan: ['#06b6d4', 'CYAN — CONTAINER DETECTED', 'A simulated container is detected on the platform.'],
        green: ['#16a34a', 'GREEN — DISPENSING', 'The simulated pump is running toward the selected target.'],
        white: ['#e2e8f0', 'WHITE — COMPLETE', 'The simulated target was reached and the pump is off.'],
        red: ['#ef4444', 'RED — ERROR / INTERRUPTED', 'The simulated dispenser is stopped until the issue is resolved.']
    };

    function number(value) {
        if (value === null || value === undefined || value === '' || typeof value === 'boolean') return null;
        const result = Number(value);
        return Number.isFinite(result) && result >= 0 ? result : null;
    }
    function title(value) { return value ? value.charAt(0).toUpperCase() + value.slice(1) : '—'; }
    function target() { return number(thresholdValues[selected]); }
    function displayedTarget() { return currentStep >= 3 && operationTarget !== null ? operationTarget : target(); }
    function threshold() { return number(thresholdValues[selected]); }
    function volume(value) { return value === null ? 'Unavailable' : value + ' mL'; }
    function setText(id, value) { const node = el(id); if (node) node.textContent = String(value); }
    function setFillFeedback(type, message, tone = 'neutral') {
        const node = el(type + '-fill-volume-feedback');
        if (node) {
            node.textContent = message || ''; node.hidden = !message;
            node.classList.toggle('is-error', tone === 'error');
            node.classList.toggle('is-success', tone === 'success');
        }
    }
    function renderThresholdReadouts() {
        ['cup', 'tumbler'].forEach((type) => {
            const amount = volume(number(thresholdValues[type]));
            setText('water-' + type + '-threshold', amount);
            setText(type + '-target-volume', amount);
        });
    }
    function operationActive() {
        return sending || demo.pumping || awaitingSession || !!(liveState && (liveState.has_session || liveState.has_pending_command || liveState.pump_status));
    }
    function renderFillLocks() {
        ['cup', 'tumbler'].forEach((type) => { const input = el(type + '-fill-volume-input'); if (input) input.disabled = operationActive(); });
        if (thresholdSelect) thresholdSelect.disabled = currentStep !== 2 || operationActive() || !selected;
    }
    function setFillInput(type) {
        const input = el(type + '-fill-volume-input'), rule = thresholds[type] || {};
        if (input) { input.value = String(thresholdValues[type] ?? ''); input.min = String(rule.min); input.max = String(rule.max); input.step = '1'; }
    }
    function setButton(enabled) { if (button) button.disabled = !enabled; }
    function setStep(step) {
        currentStep = step;
        panels.forEach((panel) => { panel.hidden = Number(panel.dataset.panel) !== step; });
        const rail = el('dispense-steps');
        if (!rail) return;
        rail.querySelectorAll('.dispense-step').forEach((node) => {
            const n = Number(node.dataset.step);
            node.classList.toggle('is-active', n === step);
            node.classList.toggle('is-done', n < step || (step === 4 && n === 4));
            if (n === step) node.setAttribute('aria-current', 'step'); else node.removeAttribute('aria-current');
        });
        rail.querySelectorAll('.step-connector').forEach((node) => node.classList.toggle('is-done', Number(node.dataset.step) <= step));
    }
    function updateContainerCopy() {
        renderThresholdReadouts();
        setText('place-container-name', title(selected));
        setText('place-container-target', 'Target: ' + volume(target()));
        setText('dispense-container', title(selected));
        setText('dispense-target-label', 'Target: ' + volume(displayedTarget()));
        setText('target-value', displayedTarget() === null ? '—' : displayedTarget());
        setText('threshold-container-name', title(selected));
        setText('threshold-value', threshold() === null ? 'Unavailable' : threshold());
    }
    function renderThresholdOptions() {
        if (!thresholdSelect) return;
        const config = thresholds[selected] || {};
        thresholdSelect.value = String(threshold());
        thresholdSelect.min = String(config.min); thresholdSelect.max = String(config.max); thresholdSelect.step = '1';
        renderFillLocks();
    }
    function localThresholdValid(type, value) {
        const config = thresholds[type] || {};
        return Number.isSafeInteger(value) && value > 0 && value >= Number(config.min) && value <= Number(config.max);
    }
    async function fetchJson(options) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 6000);
        try {
            const response = await fetch(cfg.statusUrl, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal }, options));
            const data = await response.json();
            if (response.ok === false && data.success !== false) throw new Error('Server request failed.');
            return data;
        } finally { clearTimeout(timer); }
    }
    function post(action, extra) {
        return fetchJson({ method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(Object.assign({ action, csrf_token: cfg.csrfToken }, extra)) });
    }
    async function validateThreshold(value, recordSelection, type = selected) {
        if (!['cup', 'tumbler'].includes(type)) return;
        if (operationActive()) { setFillFeedback(type, 'Finish the active dispensing operation before changing the fill amount.'); return; }
        const version = ++fillVersions[type], selection = selectionVersion, forSelected = selected === type;
        setFillFeedback(type, 'Validating fill amount…');
        if (forSelected) { thresholdPending = true; thresholdValid = false; thresholdError = ''; requestError = ''; }
        refresh();
        try {
            const data = await post('select_threshold', { container_type: type, threshold_ml: value });
            if (version !== fillVersions[type]) return;
            if (operationActive()) { setFillFeedback(type, 'Finish the active dispensing operation before changing the fill amount.'); return; }
            const accepted = number(data.threshold_ml);
            if (!data.success || !localThresholdValid(type, accepted) || accepted !== number(value)
                || (data.target_amount_ml !== undefined && number(data.target_amount_ml) !== accepted)) throw new Error(data.error || data.message || 'Enter a permitted whole-number fill amount in mL.');
            const previous = thresholdValues[type];
            thresholdValues[type] = accepted; setFillInput(type); setFillFeedback(type, 'Saved fill amount: ' + volume(accepted) + '.', 'success');
            if (selected === type && selection === selectionVersion) { thresholdValid = true; if (thresholdSelect) thresholdSelect.value = String(accepted); }
            if (demoMode && (recordSelection || Number(previous) !== accepted)) addActivity('Fill amount selected', title(type) + ' fill cutoff: ' + volume(accepted) + '.');
        } catch (error) {
            if (version !== fillVersions[type]) return;
            const message = error.message || 'Fill amount validation unavailable. Please enter the amount again.';
            setFillFeedback(type, message, 'error');
            if (selected === type && selection === selectionVersion) thresholdError = message;
        } finally {
            if (version === fillVersions[type]) {
                if (selected === type && selection === selectionVersion) thresholdPending = false;
                updateContainerCopy(); refresh();
            }
        }
    }
    function selectContainer(type) {
        if (!['cup', 'tumbler'].includes(type) || operationActive()) return;
        ++selectionVersion; selected = type; thresholdValid = false; thresholdError = ''; requestError = '';
        if (number(thresholdValues[type]) === null) thresholdValues[type] = number((thresholds[type] || {}).default);
        cards.forEach((card) => {
            card.classList.toggle('is-selected', card.dataset.container === type);
            const input = card.querySelector('input'); if (input) input.checked = card.dataset.container === type;
        });
        setStep(2); updateContainerCopy(); renderThresholdOptions();
        if (demoMode) {
            demo.type = type; demo.phase = 'place';
            addActivity('Container selected', title(type) + ' — ' + volume(target()) + ' target');
            validateThreshold(threshold(), true);
        } else {
            const version = selectionVersion;
            thresholdPending = true; refresh();
            post('select_container', { container_type: type }).then((data) => {
                if (selected !== type || version !== selectionVersion) return;
                if (!data.success) throw new Error(data.error || data.message || 'Container selection failed.');
                validateThreshold(threshold(), false);
            }).catch((error) => {
                if (selected !== type || version !== selectionVersion) return;
                thresholdPending = false; thresholdError = error.message; refresh();
            });
        }
        if (thresholdSelect) thresholdSelect.focus();
    }
    function renderPlace(detected, message, known) {
        const indicator = el('place-indicator');
        if (indicator) indicator.classList.toggle('is-detected', detected && known !== false);
        setText('container-sensor-status', known === false ? 'Container status unavailable' : detected ? 'Container detected' : 'Waiting for container');
        setText('place-heading', detected && known !== false ? 'Container ready' : 'Place your container');
        setText('place-description', detected && known !== false ? 'Container detected. Confirm the fill amount, then start dispensing.' : 'Place your selected ' + (selected || 'container') + ' on the dispenser platform.');
        setText('dispense-hint', message);
    }
    function renderLed(key) {
        const state = LED[key];
        if (el('led-dot')) el('led-dot').style.background = state[0];
        setText('led-label', state[1] + ' (SIMULATED)'); setText('led-desc', state[2]);
    }
    function renderHardwareLed(state) {
        const led = state && state.led;
        const confirmed = !!(state && state.device_online && state.telemetry_valid && !connectionFailed && led && led.confirmed);
        if (el('led-dot')) el('led-dot').style.background = confirmed ? led.hex : '#94a3b8';
        setText('led-label', confirmed ? led.label : 'RGB LED STATE UNAVAILABLE');
        setText('led-desc', confirmed ? led.description : 'Waiting for valid device-reported RGB telemetry.');
    }
    function renderAvailability(state) {
        const pill = el('status-pill');
        if (pill && state) pill.className = 'status-pill is-' + (['success', 'warning', 'danger', 'neutral'].includes(state.tone) ? state.tone : 'neutral');
        if (state) setText('status-pill-label', state.label || 'STATUS UNAVAILABLE');
    }
    function renderDeviceStatus(connection, container, pump) {
        setText('device-status-connection', connection);
        setText('device-status-container', container);
        setText('device-status-pump', pump);
    }
    function renderDispenseSensor(detected, known, note) {
        const indicator = el('dispense-sensor-indicator');
        const dot = indicator && indicator.querySelector('.status-dot');
        if (dot) dot.classList.toggle('is-on', !!detected && !!known);
        setText('dispense-sensor-status', !known ? 'Container status unavailable' : detected ? 'Container detected' : 'Waiting for container');
        setText('dispense-sensor-note', note || (!known ? 'Fresh device telemetry required' : detected ? 'Presence sensor confirms container' : 'Presence sensor reports no container'));
    }
    function renderProgress(current, total, pump, system) {
        const pct = current !== null && total !== null && total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : null;
        if (el('progress-fill')) el('progress-fill').style.width = (pct || 0) + '%';
        setText('measured-value', current === null ? '—' : current); setText('target-value', total === null ? '—' : total);
        setText('progress-percent', pct === null ? 'Unavailable' : pct + '%');
        setText('ro-container', title(selected)); setText('ro-target', volume(total));
        setText('ro-pump', pump === null ? 'UNKNOWN' : pump ? 'ON' : 'OFF'); setText('ro-system', system || 'UNKNOWN');
    }
    function demoBlockReason() {
        if (!selected || target() === null) return 'Choose a configured container.';
        if (thresholdPending) return 'Validating fill amount…';
        if (thresholdError) return thresholdError;
        if (!thresholdValid) return 'Enter a valid fill amount to continue.';
        if (demo.offline) return 'Device offline simulated — dispensing unavailable. Reset demo to reconnect.';
        if (!demo.container) return 'Use “Simulate container detected” to continue.';
        if (demo.pumping) return 'Dispensing is already active.';
        if (demo.phase === 'complete' || demo.phase === 'interrupted') return 'Select Done or Dispense Again to continue.';
        return '';
    }
    function renderDemo() {
        renderAvailability({ tone: demo.offline ? 'danger' : 'success', label: demo.offline ? 'DEMO DEVICE OFFLINE' : 'DEMO SYSTEM AVAILABLE' });
        renderLed(demo.offline || demo.phase === 'interrupted' ? 'red' : demo.pumping ? 'green' : demo.phase === 'complete' ? 'white' : demo.container ? 'cyan' : 'blue');
        renderDeviceStatus(demo.offline ? 'SIMULATED OFFLINE' : 'SIMULATED ONLINE', demo.container ? 'SIMULATED DETECTED' : 'SIMULATED NOT DETECTED', demo.pumping ? 'SIMULATED ON' : 'SIMULATED OFF');
        renderFillLocks();
        const blocked = demoBlockReason();
        if (currentStep === 2) renderPlace(demo.container, blocked || 'Ready to dispense using simulated water.');
        setButton(currentStep === 2 && !blocked);
    }
    function hardwareBlockReason(state) {
        if (connectionFailed || !state) return 'Device status unavailable — waiting for a successful status update.';
        if (!state.device_online) return 'Device is offline — dispensing unavailable.';
        if (!state.telemetry_valid) return 'Device sensor data is unavailable or stale — dispensing unavailable.';
        if (state.safety_blocked) return state.safety_message || 'A device safety condition prevents dispensing.';
        if (state.has_session || state.has_pending_command || state.pump_status) return 'A dispensing operation is already active.';
        if (requestError) return requestError;
        if (thresholdPending) return 'Validating fill amount…';
        if (thresholdError) return thresholdError;
        if (!thresholdValid) return 'Enter a valid fill amount to continue.';
        if (!state.container_detected) return 'Waiting for a container on the platform.';
        if (sending) return 'Sending request to dispenser…';
        return '';
    }
    function renderLive(state) {
        const telemetryKnown = !!(state.device_online && state.telemetry_valid && !connectionFailed);
        renderAvailability(state.availability || cfg.availability); renderHardwareLed(state);
        renderDeviceStatus(connectionFailed || typeof state.device_online !== 'boolean' ? 'UNKNOWN' : state.device_online ? 'ONLINE' : 'OFFLINE', telemetryKnown ? (state.container_detected ? 'DETECTED' : 'NOT DETECTED') : 'UNKNOWN', telemetryKnown ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN');
        renderFillLocks();
        renderDispenseSensor(state.container_detected, telemetryKnown, state.safety_blocked ? state.safety_message : '');
        if (state.has_session && state.session) {
            const session = state.session; activeSessionId = session.id; awaitingSession = true;
            operationTarget = number(session.target_amount_ml);
            if (['cup', 'tumbler'].includes(session.container_type)) selected = session.container_type;
            setStep(3); updateContainerCopy(); setButton(false);
            setText('dispense-target-label', 'Target: ' + volume(number(session.target_amount_ml)));
            renderProgress(number(session.measured_amount_ml), number(session.target_amount_ml), telemetryKnown ? !!state.pump_status : null, telemetryKnown && state.led && state.led.confirmed ? state.led.label : 'UNKNOWN');
            setText('dispense-description', telemetryKnown ? (state.pump_status ? 'The device reports its pump running. Waiting for the recorded session result.' : 'The device reports its pump stopped. Waiting for the recorded session result.') : 'Device telemetry is unavailable. Pump and sensor state are unknown.');
            setText('dispensing-status', telemetryKnown ? (state.pump_status ? 'DISPENSING IN PROGRESS…' : 'WAITING FOR DEVICE SESSION RESULT…') : 'DEVICE TELEMETRY UNAVAILABLE');
            return;
        }
        const last = state.last_session, linkedCommand = state.request_command;
        const matches = last && last.id !== undefined && ((activeSessionId !== null && String(last.id) === String(activeSessionId))
            || (requestCommandId !== null && requestCommandId !== undefined && linkedCommand && String(linkedCommand.id) === String(requestCommandId)
                && linkedCommand.session_id && String(last.id) === String(linkedCommand.session_id)));
        if (awaitingSession && matches && ['completed', 'interrupted', 'failed'].includes(last.status)) {
            awaitingSession = false; activeSessionId = null;
            operationTarget = number(last.target_amount_ml);
            complete(last.status !== 'completed', last.stop_reason || (last.status === 'completed' ? 'The device reported this dispensing session completed.' : 'The device reported this dispensing session stopped.'), last, state);
            return;
        }
        if (state.has_pending_command) {
            awaitingSession = true; setStep(3); setButton(false);
            const command = state.pending_command || {};
            requestCommandId = command.id;
            operationTarget = number(command.target_amount_ml);
            if (['cup', 'tumbler'].includes(command.container_type)) selected = command.container_type;
            updateContainerCopy(); renderProgress(null, number(command.target_amount_ml), telemetryKnown ? !!state.pump_status : null, telemetryKnown && state.led && state.led.confirmed ? state.led.label : 'UNKNOWN');
            setText('dispense-target-label', 'Target: ' + volume(number(command.target_amount_ml)));
            setText('dispense-description', 'Request queued. Waiting for device confirmation before reporting measured water or pump activity.');
            setText('dispensing-status', 'REQUEST QUEUED — WAITING FOR DEVICE'); return;
        }
        const command = state.request_command;
        if (awaitingSession && command && requestCommandId !== null && String(command.id) === String(requestCommandId) && !command.session_id && ['cancelled', 'rejected', 'expired', 'failed'].includes(command.status)) {
            awaitingSession = false; activeSessionId = null;
            operationTarget = number(command.target_amount_ml);
            updateContainerCopy();
            complete(true, 'The request was ' + command.status + ' without a recorded dispensing session. Return to Choose before retrying.', Object.assign({}, command, { measured_amount_ml: null }), state);
            setText('complete-title', 'DISPENSING REQUEST ' + command.status.toUpperCase());
            setText('complete-heading', 'The dispensing request has ended.');
            return;
        }
        if (awaitingSession && currentStep === 3) {
            setButton(false);
            setText('dispense-description', 'Waiting for a recorded result from the device. The browser cannot confirm completion.');
            setText('dispensing-status', state.safety_message || 'WAITING FOR DEVICE SESSION RESULT');
            renderProgress(null, displayedTarget(), telemetryKnown ? !!state.pump_status : null, telemetryKnown && state.led && state.led.confirmed ? state.led.label : 'UNKNOWN');
            return;
        }
        if (currentStep === 4) {
            setText('co-pump', telemetryKnown ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN');
            setText('co-reason', telemetryKnown && state.led && state.led.confirmed ? state.led.label : 'UNKNOWN');
            setButton(false); return;
        }
        if (!selected) { setButton(false); return; }
        setStep(2);
        const blocked = hardwareBlockReason(state);
        renderPlace(!!state.container_detected, blocked || 'Ready to dispense.', telemetryKnown);
        setButton(!blocked);
    }
    function refresh() { if (demoMode) renderDemo(); else if (liveState && !connectionFailed) renderLive(liveState); else { setButton(false); renderPlace(false, thresholdError || 'Device status unavailable — waiting for a successful status update.', false); } }
    function complete(interrupted, detail, session, state) {
        operationTarget = demoMode ? demo.target : number(session.target_amount_ml);
        setStep(4); setButton(false);
        setText('complete-title', interrupted ? 'DISPENSING INTERRUPTED' : 'DISPENSING COMPLETE');
        setText('complete-heading', interrupted ? 'Dispensing stopped.' : 'Your water is ready.');
        setText('complete-detail', detail || (interrupted ? 'Resolve the issue, then return to Choose.' : 'The simulated target has been reached.'));
        setText('complete-icon', interrupted ? '!' : '✓');
        setText('co-container', title(demoMode ? demo.type : session.container_type));
        setText('co-target', volume(demoMode ? demo.target : number(session.target_amount_ml)));
        setText('co-measured', volume(demoMode ? demo.current : number(session.measured_amount_ml)));
        setText('co-pump', demoMode ? 'OFF' : state.device_online && state.telemetry_valid ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN');
        setText('co-reason', demoMode ? (interrupted ? 'RED / INTERRUPTED' : 'WHITE / GREEN FLASH') : state.device_online && state.telemetry_valid && state.led && state.led.confirmed ? state.led.label : 'UNKNOWN');
        if (el('complete-note')) el('complete-note').hidden = interrupted || !demoMode;
        updateContainerCopy();
        if (demoMode) { renderProgress(demo.current, demo.target, false, interrupted ? 'RED / INTERRUPTED' : 'WHITE / COMPLETE'); renderDemo(); }
        renderFillLocks();
    }
    function demoClear() { demo.timers.forEach(clearTimeout); demo.timers = []; }
    function demoFinish() {
        if (!demo.pumping) return;
        demoClear(); demo.current = demo.target; demo.pumping = false; demo.phase = 'complete';
        addActivity('Target reached', volume(demo.current) + ' simulated measured water');
        addActivity('Dispensing completed', title(demo.type) + ' — ' + volume(demo.current) + '; simulated pump OFF');
        complete(false);
    }
    function demoStart() {
        if (currentStep !== 2 || demoBlockReason()) return;
        addActivity('Dispensing requested', title(selected) + ' — ' + volume(target()) + ' fill target');
        demo.type = selected; demo.target = target(); demo.current = 0; demo.phase = 'dispensing'; demo.pumping = true; operationTarget = demo.target;
        setStep(3); updateContainerCopy(); setText('dispense-description', 'Simulated pump running. The demo stops automatically at the configured target.');
        setText('dispensing-status', 'SIMULATED DISPENSING IN PROGRESS…'); renderDispenseSensor(demo.container, true, 'Simulated detection confirms container'); renderDemo(); renderProgress(0, demo.target, true, 'GREEN');
        addActivity('Dispensing started', title(selected) + ' — ' + volume(demo.target) + '; simulated pump ON');
        [20, 40, 60, 80, 90, 100].forEach((pct, index) => {
            demo.timers.push(setTimeout(() => {
                if (!demo.pumping) return;
                if (pct === 100) return demoFinish();
                demo.current = Number((demo.target * pct / 100).toFixed(2));
                renderProgress(demo.current, demo.target, true, 'GREEN');
                if ([20, 40, 60, 80].includes(pct)) addActivity('Dispensing progress', demo.current + ' / ' + demo.target + ' mL (' + pct + '% simulated)');
            }, (index + 1) * 900));
        });
    }
    function demoInterrupt(message) {
        demoClear(); demo.pumping = false; demo.phase = 'interrupted';
        addActivity('Dispensing interrupted', message + ' Simulated pump OFF; measured ' + volume(demo.current) + '.');
        complete(true, message);
    }
    function resetSelection() {
        ++selectionVersion; selected = null; thresholdValid = false; thresholdPending = false; thresholdError = ''; requestError = ''; sending = false; operationTarget = null; requestCommandId = null;
        ['cup', 'tumbler'].forEach((type) => { ++fillVersions[type]; setFillInput(type); setFillFeedback(type, ''); });
        cards.forEach((card) => { card.classList.remove('is-selected'); const input = card.querySelector('input'); if (input) input.checked = false; });
        setButton(false); setStep(1); renderFillLocks();
    }
    function demoReset(record) {
        if (demo.pumping) demoInterrupt('Demo reset during dispensing.');
        demoClear(); Object.assign(demo, { phase: 'choose', type: null, container: false, offline: false, pumping: false, current: 0, target: 0 });
        resetSelection();
        if (record) addActivity('Demo reset', 'Simulation returned to Choose; activity history retained.');
        renderDemo();
    }
    function demoControl(action) {
        if (action === 'reset') return demoReset(true);
        if (action === 'container_on' && !demo.container) { demo.container = true; addActivity('Container detected', 'Demo container detected on the platform.'); }
        if (action === 'container_off' && demo.container) { demo.container = false; addActivity('Container removed', 'Demo container removed from the platform.'); if (demo.pumping) return demoInterrupt('The simulated container was removed before the target was reached.'); }
        if (action === 'offline' && !demo.offline) { demo.offline = true; addActivity('Device offline', 'Simulated device offline. Reset demo to reconnect.'); if (demo.pumping) return demoInterrupt('The simulated device went offline.'); }
        if (action === 'target') return demoFinish();
        if (action === 'next') {
            if (selected && !demo.container) { demo.container = true; addActivity('Container detected', 'Demo container detected using the next-step control.'); }
            else if (demo.phase === 'complete' || demo.phase === 'interrupted') return demoReset(false);
            // Detection and next-step controls never start the pump. Dispense Water is the explicit start action.
        }
        renderDemo();
    }
    function addActivity(event, details) {
        if (!demoMode) return;
        const now = new Date();
        activities.unshift({ created_at: now.toISOString(), time: now.toLocaleTimeString([], { hour12: false }), event, details, status: 'Simulated' });
        activities = activities.slice(0, HISTORY_LIMIT);
        try { localStorage.setItem(HISTORY_KEY, JSON.stringify(activities)); } catch (error) { /* Private browsers can still show in-memory demo activity. */ }
        renderActivities();
    }
    function activityTimestamp(activity) {
        const created = typeof activity.created_at === 'string' ? activity.created_at : '';
        // Database DATETIME values carry server wall time, not a browser timezone.
        const recorded = !demoMode && typeof activity.date_time === 'string' ? activity.date_time : created;
        const wall = recorded.slice(0, 19).replace(' ', 'T'), calendar = new Date(wall + 'Z');
        const validCalendar = !Number.isNaN(calendar.getTime()) && calendar.toISOString().slice(0, 19) === wall;
        if (validCalendar && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(recorded)) return { label: recorded, datetime: wall };
        if (validCalendar && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(created)) {
            const date = new Date(created);
            if (!Number.isNaN(date.getTime())) {
                if (!demoMode) return { label: created.slice(0, 19).replace('T', ' '), datetime: created };
                const pad = (value) => String(value).padStart(2, '0');
                const label = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
                    + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
                return { label, datetime: date.toISOString() };
            }
        }
        return { label: 'Date & time unavailable', datetime: null };
    }
    function renderActivities() {
        const body = el('activity-log-body');
        if (!body) return;
        body.replaceChildren();
        activities.slice(0, visibleActivities).forEach((activity) => {
            const row = document.createElement('tr');
            ['date_time', 'event', 'details', 'status'].forEach((key, index) => {
                const cell = document.createElement('td'); cell.setAttribute('data-label', ['Date & time', 'Activity', 'Details', 'Status'][index]);
                if (key === 'date_time') {
                    const timestamp = activityTimestamp(activity);
                    if (timestamp.datetime) { const time = document.createElement('time'); time.setAttribute('datetime', timestamp.datetime); time.textContent = timestamp.label; cell.appendChild(time); }
                    else cell.textContent = timestamp.label;
                }
                else if (key === 'status') { const badge = document.createElement('span'); badge.className = 'activity-status' + (demoMode ? ' is-simulated' : ''); badge.textContent = demoMode ? 'Simulated' : String(activity[key] || 'Recorded'); cell.appendChild(badge); }
                else cell.textContent = String(activity[key] || '—');
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
        if (el('activity-empty')) el('activity-empty').hidden = activities.length > 0;
        if (el('view-more-activity')) el('view-more-activity').hidden = visibleActivities >= activities.length;
    }
    function markConnectionFailed(message) {
        connectionFailed = true; setButton(false);
        renderAvailability({ tone: 'warning', label: 'STATUS UNAVAILABLE' }); renderHardwareLed(null);
        renderDeviceStatus('UNKNOWN', 'UNKNOWN', 'UNKNOWN');
        renderFillLocks();
        renderDispenseSensor(false, false);
        if (currentStep === 4) { setText('co-pump', 'UNKNOWN'); setText('co-reason', 'UNKNOWN'); }
        if (currentStep === 2) renderPlace(false, message || 'Status update failed — dispensing unavailable until fresh telemetry arrives.', false);
        if (currentStep === 3) { renderProgress(null, displayedTarget(), null, 'UNKNOWN'); setText('dispense-description', 'Device status could not be refreshed. Pump and sensor state are unknown.'); setText('dispensing-status', 'STATUS UPDATE FAILED — DEVICE STATE UNKNOWN'); }
    }
    async function pollNow() {
        if (demoMode || pollBusy) return;
        pollBusy = true;
        try {
            const data = await fetchJson();
            if (!data.success || !data.state) throw new Error(data.error || data.message || 'Status update failed.');
            connectionFailed = false; liveState = data.state;
            if (Array.isArray(data.activities)) { activities = data.activities; renderActivities(); }
            renderLive(liveState);
        } catch (error) { markConnectionFailed(error.message); }
        finally { pollBusy = false; }
    }
    async function requestDispense() {
        if (!selected || sending || currentStep !== 2 || hardwareBlockReason(liveState)) return;
        const requestedAmount = threshold();
        sending = true; renderFillLocks(); setButton(false); setText('dispense-hint', 'Sending request to dispenser…');
        try {
            const data = await post('request_dispense', { container_type: selected, threshold_ml: requestedAmount });
            if (!data.success) throw new Error(data.error || data.message || 'Dispensing request failed.');
            awaitingSession = true; requestCommandId = data.command_id; operationTarget = requestedAmount; requestError = ''; setStep(3); updateContainerCopy(); renderFillLocks(); renderProgress(null, operationTarget, null, 'UNKNOWN'); setText('dispensing-status', 'REQUEST SENT — WAITING FOR DEVICE');
        } catch (error) {
            requestError = (error.message || 'Request failed.') + ' Enter your fill amount again before retrying.';
            setButton(false); setText('dispense-hint', requestError);
        } finally { sending = false; renderFillLocks(); pollNow(); }
    }
    function startPolling() { if (demoMode || polling) return; pollNow(); polling = setInterval(pollNow, 2000); }

    cards.forEach((card) => {
        const input = card.querySelector('input');
        if (input) input.addEventListener('change', () => { if (input.checked) selectContainer(input.value || card.dataset.container); });
    });
    if (thresholdSelect) thresholdSelect.addEventListener('change', () => { if (selected && currentStep === 2 && !sending) validateThreshold(thresholdSelect.value, true); });
    ['cup', 'tumbler'].forEach((type) => { const input = el(type + '-fill-volume-input'); if (input) input.addEventListener('change', () => validateThreshold(input.value, true, type)); });
    if (button) button.addEventListener('click', () => { if (!button.disabled) { if (demoMode) demoStart(); else requestDispense(); } });
    if (el('change-container-btn')) el('change-container-btn').addEventListener('click', () => { if (!sending && !demo.pumping) { resetSelection(); const input = cards[0] && cards[0].querySelector('input'); if (input) input.focus(); } });
    [el('done-btn'), el('restart-btn')].forEach((node) => node && node.addEventListener('click', () => {
        if (demoMode) demoReset(false); else { awaitingSession = false; activeSessionId = null; resetSelection(); }
    }));
    if (demoMode) document.querySelectorAll('[data-demo]').forEach((node) => node.addEventListener('click', () => demoControl(node.dataset.demo)));
    if (el('view-more-activity')) el('view-more-activity').addEventListener('click', () => { visibleActivities += 10; renderActivities(); });
    if (el('clear-demo-history')) {
        el('clear-demo-history').hidden = !demoMode;
        if (demoMode) el('clear-demo-history').addEventListener('click', () => { activities = []; visibleActivities = 10; try { localStorage.removeItem(HISTORY_KEY); } catch (error) { /* Visible demo history is still cleared. */ } renderActivities(); });
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && polling) { clearInterval(polling); polling = null; markConnectionFailed('Refresh device status when returning to this page.'); }
        else if (!document.hidden) startPolling();
    });
    setText('activity-log-label', demoMode ? 'Demo Activity Logs — simulated activity stored in this browser only.' : 'Activity Logs — device activity recorded by the server.');
    if (demoMode) {
        try { const saved = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); if (Array.isArray(saved)) activities = saved.filter((entry) => entry && entry.status === 'Simulated' && typeof entry.event === 'string' && typeof entry.details === 'string').slice(0, HISTORY_LIMIT); } catch (error) { activities = []; }
    } else activities = Array.isArray(cfg.initialActivities) ? cfg.initialActivities : [];
    renderActivities(); renderThresholdReadouts(); ['cup', 'tumbler'].forEach(setFillInput); setStep(1); setButton(false);
    if (demoMode) renderDemo();
    else { renderAvailability(cfg.availability); renderHardwareLed(null); renderDeviceStatus('UNKNOWN', 'UNKNOWN', 'UNKNOWN'); renderFillLocks(); startPolling(); }
}());
