/* Real hardware controller. Browser time never controls the physical relay. */
(function () {
    'use strict';
    function blockReason(state, selected, age) {
        if (!state || age > 6000) return 'Connecting — fresh device status required.';
        if (!state.can_dispense) return 'Dispensing is currently unavailable.';
        if (!state.device_online) return 'Device offline or heartbeat stale.';
        if (!state.telemetry_valid || state.sensor_status !== 'ok') return 'Container sensor unknown, stale, or unavailable.';
        if (state.has_session || state.has_pending_command || state.pump_status) return 'An operation is already pending or active.';
        if (state.safety_blocked) return state.safety_message || 'A safety condition blocks dispensing.';
        if (state.container_detected !== true) return 'Place a container on the tray.';
        if (!['cup', 'tumbler'].includes(selected)) return 'Choose Cup or Tumbler.';
        const ms = state.presets?.[selected]?.duration_ms;
        if (!Number.isInteger(ms) || ms < 1000 || ms > 30000) return 'This container needs a calibrated duration in Settings.';
        return null;
    }
    if (typeof module !== 'undefined' && module.exports) { module.exports = { blockReason }; return; }
    const cfg = window.WaterBoniaDispenser || {}, el = id => document.getElementById(id);
    const text = (id, value) => { if (el(id)) el(id).textContent = String(value); };
    const button = el('dispense-btn');
    let state = null, received = 0, selected = null, sending = false, polling = false;
    let requestKey = null, requestType = null, dismissed = null, lastError = '';
    function step(n) {
        document.querySelectorAll('.dispense-panel').forEach(p => { p.hidden = Number(p.dataset.panel) !== n; });
        document.querySelectorAll('.dispense-step').forEach(p => {
            const i = Number(p.dataset.step); p.classList.toggle('is-active', i === n); p.classList.toggle('is-done', i < n);
            if (i === n) p.setAttribute('aria-current', 'step'); else p.removeAttribute('aria-current');
        });
        document.querySelectorAll('.step-connector').forEach(p => p.classList.toggle('is-done', Number(p.dataset.step) <= n));
    }
    function history(rows) {
        const body = el('activity-log-body'); if (!body) return;
        body.replaceChildren();
        rows.slice(0, 50).forEach(row => {
            const tr = document.createElement('tr');
            ['date_time', 'event', 'details', 'status'].forEach(key => { const td = document.createElement('td'); td.textContent = String(row[key] || '—'); tr.appendChild(td); });
            body.appendChild(tr);
        });
        if (el('activity-empty')) el('activity-empty').hidden = rows.length > 0;
    }
    function render() {
        const fresh = state && Date.now() - received <= 6000, known = fresh && state.device_online && state.telemetry_valid;
        const present = known ? state.container_detected === true : null;
        const reason = blockReason(state, selected, Date.now() - received);
        if (button) button.disabled = sending || !!reason;
        text('dispense-hint', lastError || reason || 'Ready for timed dispensing. Volume is not measured.');
        text('device-status-connection', !fresh ? 'CONNECTING / UNKNOWN' : !state.device_online ? 'OFFLINE' : state.sensor_status === 'fault' ? 'ERROR' : 'ONLINE');
        text('device-status-container', present === null ? 'UNKNOWN' : present ? 'DETECTED' : 'NOT DETECTED');
        text('device-status-pump', known ? (state.pump_status ? 'ON — DEVICE REPORTED' : 'OFF — DEVICE REPORTED') : 'UNKNOWN');
        text('container-sensor-status', present === null ? 'Unknown' : present ? 'Detected' : 'Not detected');
        text('dispense-sensor-status', present === null ? 'Unknown' : present ? 'Detected' : 'Not detected');
        text('dispense-sensor-note', known ? 'Actual IR report' : 'Fresh IR report required');
        if (el('status-pill')) { el('status-pill').className = 'status-pill is-' + (!fresh ? 'warning' : !state.device_online ? 'danger' : !known ? 'warning' : 'success'); el('status-pill').title = reason || 'Fresh device reports received.'; }
        text('status-pill-label', !fresh ? 'CONNECTING' : !state.device_online ? 'DEVICE OFFLINE' : !known ? 'SENSOR UNKNOWN / ERROR' : 'DEVICE ONLINE');
        text('led-label', 'LED NOT FITTED / NOT REPORTED'); text('led-desc', 'No physical LED state is inferred.');
        ['cup', 'tumbler'].forEach(type => {
            const preset = state?.presets?.[type] || cfg.presets?.[type], nominal = preset?.nominal_target_ml ?? cfg.targets?.[type];
            text(type + '-target-volume', (nominal ?? '—') + ' mL nominal');
            text('water-' + type + '-threshold', preset?.duration_ms ? preset.duration_ms / 1000 + ' seconds configured' : 'Disabled — calibration required');
            if (el(type + '-fill-volume-input')) { el(type + '-fill-volume-input').disabled = true; el(type + '-fill-volume-input').value = nominal ?? ''; }
        });
        if (el('water-threshold-select')) { el('water-threshold-select').disabled = true; el('water-threshold-select').value = cfg.targets?.[selected] ?? ''; }
        text('place-container-name', selected || '—'); text('threshold-container-name', selected || '—'); text('threshold-value', cfg.targets?.[selected] ?? '—');
        text('place-container-target', (cfg.targets?.[selected] ?? '—') + ' mL nominal — not measured');
        text('timer-preset', state?.presets?.[selected]?.duration_ms ? 'Configured time: ' + state.presets[selected].duration_ms / 1000 + ' s.' : 'Calibration required.');
        const session = state?.session, command = state?.pending_command;
        if (session || command) {
            step(3); if (button) button.disabled = true;
            text('operation-heading', known && state.pump_status ? 'Dispensing...' : session ? 'Awaiting device result' : 'Request pending');
            const duration = session?.configured_duration_ms ?? command?.duration_ms;
            const elapsed = known && state.active_command_id && state.pump_elapsed_ms !== null ? Number(state.pump_elapsed_ms) : null;
            text('dispense-container', session?.container_type || command?.container_type || '—');
            text('target-value', duration ? duration / 1000 : '—'); text('measured-value', elapsed === null ? '—' : (elapsed / 1000).toFixed(1));
            const pct = elapsed === null || !duration ? 0 : Math.min(99, elapsed / duration * 100);
            text('progress-percent', elapsed === null ? 'Unknown' : Math.floor(pct) + '%'); if (el('progress-fill')) el('progress-fill').style.width = pct + '%';
            text('dispense-target-label', 'Nominal target: ' + (session?.target_amount_ml ?? command?.target_amount_ml ?? '—') + ' mL — not measured');
            text('dispense-description', session ? 'ESP32 controls the timer locally. Awaiting its final result.' : 'Request pending device validation.');
            text('dispensing-status', !fresh ? 'CONNECTION LOST — OPERATION OUTCOME UNKNOWN' : elapsed !== null ? 'DEVICE TIMER: about ' + Math.max(0, (duration - elapsed) / 1000).toFixed(1) + ' s remaining' : 'WAITING FOR DEVICE REPORT — NO COMPLETION ASSUMED');
            text('ro-container', session?.container_type || command?.container_type || '—'); text('ro-target', 'Nominal only');
            text('ro-pump', known ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN'); text('ro-system', 'Not reported'); return;
        }
        const request = state?.request_command, result = state?.last_session;
        if (result && request?.session_id && String(request.session_id) === String(result.id) && dismissed !== String(request.id)) {
            step(4); if (button) button.disabled = true;
            text('complete-icon', result.status === 'completed' ? '✓' : '!');
            text('complete-title', result.status === 'completed' ? 'TIMED OPERATION COMPLETED' : result.status === 'failed' ? 'OPERATION FAILED' : 'OPERATION STOPPED');
            text('complete-heading', result.status === 'completed' ? 'Configured timer completed.' : 'Dispensing stopped.');
            text('complete-detail', (result.stop_reason || result.status).replaceAll('_', ' ') + '. Actual water volume was not measured.');
            text('co-container', result.container_type); text('co-target', result.target_amount_ml + ' mL nominal');
            text('co-measured', result.pump_elapsed_ms === null ? 'Unknown' : result.pump_elapsed_ms / 1000 + ' s');
            text('co-pump', known ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN'); text('co-reason', 'Not reported'); return;
        }
        if (request && ['rejected', 'expired', 'cancelled'].includes(request.status) && dismissed !== String(request.id)) {
            step(4); if (button) button.disabled = true;
            text('complete-icon', '!'); text('complete-title', 'REQUEST ' + request.status.toUpperCase()); text('complete-heading', 'The request ended before dispensing.');
            text('complete-detail', 'No completed hardware operation was recorded. Choose Dispense Again to create a new request.');
            text('co-container', request.container_type || selected || '—'); text('co-target', 'Nominal only'); text('co-measured', 'Not started');
            text('co-pump', known ? (state.pump_status ? 'ON' : 'OFF') : 'UNKNOWN'); text('co-reason', 'Not reported'); return;
        }
        step(selected ? 2 : 1);
    }
    async function call(body) {
        const control = new AbortController(), timeout = setTimeout(() => control.abort(), 5000);
        try {
            const response = await fetch(cfg.statusUrl, { method: body ? 'POST' : 'GET', cache: 'no-store', signal: control.signal,
                headers: { 'Content-Type': 'application/json' }, body: body ? JSON.stringify({ ...body, csrf_token: cfg.csrfToken }) : undefined });
            const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.error || data.message || 'Status unavailable.'); return data;
        } finally { clearTimeout(timeout); }
    }
    async function poll() {
        if (polling || document.hidden) return; polling = true;
        try { const data = await call(); state = data.state; received = Date.now(); history(data.activities || []); }
        catch (e) { received = 0; } finally { polling = false; render(); }
    }
    document.querySelectorAll('.container-card').forEach(card => {
        const input = card.querySelector('input'); if (!input) return;
        input.addEventListener('change', () => {
            if (sending || state?.has_session || state?.has_pending_command) return;
            selected = input.value || card.dataset.container; lastError = '';
            document.querySelectorAll('.container-card').forEach(c => c.classList.toggle('is-selected', c.dataset.container === selected)); render();
        });
    });
    if (button) button.addEventListener('click', async () => {
        if (sending || blockReason(state, selected, Date.now() - received)) return;
        if (!requestKey || requestType !== selected) {
            try {
                requestKey = crypto.randomUUID ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
                requestType = selected;
            } catch (e) { lastError = 'This browser cannot create a secure request identifier. Use a supported browser.'; render(); return; }
        }
        sending = true; lastError = ''; render();
        try { await call({ action: 'request_dispense', container_type: selected, request_id: requestKey }); }
        catch (e) { lastError = e.message + ' Retry uses the same request identifier.'; }
        finally { sending = false; await poll(); render(); }
    });
    ['done-btn', 'restart-btn', 'change-container-btn'].forEach(id => el(id)?.addEventListener('click', () => {
        if (sending || state?.has_session || state?.has_pending_command) return;
        dismissed = state?.request_command ? String(state.request_command.id) : null; selected = null; requestKey = null; lastError = '';
        document.querySelectorAll('.container-card').forEach(c => { c.classList.remove('is-selected'); const input = c.querySelector('input'); if (input) input.checked = false; }); render();
    }));
    document.addEventListener('visibilitychange', () => { received = 0; render(); if (!document.hidden) poll(); });
    setInterval(() => { render(); poll(); }, 2000); render(); poll();
}());
