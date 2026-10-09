/* Behavioral portal regression tests. No browser, device, or database writes.
 * Run: node tests/portal_controller_test.cjs
 * This DOM fixture checks controller behavior; it does not verify visual layout.
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/dispenser.js'), 'utf8');

class Node {
    constructor(tag = 'div', id = '') {
        this.tagName = tag.toUpperCase(); this.id = id; this.dataset = {}; this.style = {};
        this.children = []; this.listeners = {}; this.attributes = {}; this.textContent = '';
        this.hidden = false; this.disabled = false; this.checked = false; this.value = '';
        this.classes = new Set();
        this.classList = {
            add: (...names) => names.forEach(name => this.classes.add(name)),
            remove: (...names) => names.forEach(name => this.classes.delete(name)),
            contains: name => this.classes.has(name),
            toggle: (name, force) => {
                const active = force === undefined ? !this.classes.has(name) : !!force;
                if (active) this.classes.add(name); else this.classes.delete(name);
                return active;
            }
        };
    }
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
    get className() { return [...this.classes].join(' '); }
    set innerHTML(value) { this.htmlWasAssigned = true; this.children = []; this.textContent = String(value); }
    get innerHTML() { return this.textContent; }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    removeChild(child) { this.children = this.children.filter(item => item !== child); }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, handler) { (this.listeners[name] ||= []).push(handler); }
    dispatch(name) { (this.listeners[name] || []).forEach(handler => handler({target: this, currentTarget: this, preventDefault() {}})); }
    click() { if (!this.disabled) this.dispatch('click'); }
    focus() { this.focused = true; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    querySelectorAll(selector) {
        const matches = node => selector.startsWith('.') ? node.classes.has(selector.slice(1)) : selector === 'input' ? node.tagName === 'INPUT' : selector === 'option' ? node.tagName === 'OPTION' : false;
        const result = [];
        const visit = node => node.children.forEach(child => { if (matches(child)) result.push(child); visit(child); });
        visit(this); return result;
    }
}

const defaultConfig = {
    demoMode: true, targets: {cup: 250, tumbler: 500},
    thresholds: {
        cup: {options: [], default: 250, min: 1, max: 2000},
        tumbler: {options: [], default: 500, min: 1, max: 2000}
    },
    selectedThresholds: {cup: 250, tumbler: 500},
    statusUrl: '/WaterBonia/pages/dispenser-status.php', csrfToken: 'test-csrf',
    availability: {tone: 'success', label: 'SYSTEM AVAILABLE'}, initialActivities: []
};

function freshHardware(overrides = {}) {
    return Object.assign({
        device_online: true, container_detected: true, pump_status: false,
        telemetry_valid: true, safety_blocked: false, safety_message: '',
        has_session: false, has_pending_command: false,
        availability: {tone: 'success', label: 'DEVICE ONLINE'},
        led: {hex: '#06b6d4', label: 'CYAN', description: 'Reported by physical device.', confirmed: true},
        activities: []
    }, overrides);
}

function fixture(overrides = {}, storage = new Map()) {
    const config = Object.assign(structuredClone(defaultConfig), overrides);
    const ids = new Map();
    const idNames = [
        'dispense-steps', 'dispense-btn', 'place-container-name', 'place-container-target', 'place-description',
        'dispense-container', 'dispense-target-label', 'target-value', 'led-dot', 'led-label', 'led-desc',
        'progress-fill', 'measured-value', 'progress-percent', 'ro-container', 'ro-target', 'ro-pump', 'ro-system',
        'place-indicator', 'container-sensor-status', 'place-heading', 'dispense-hint', 'complete-title',
        'complete-heading', 'complete-detail', 'complete-icon', 'co-container', 'co-target', 'co-measured',
        'co-pump', 'co-reason', 'complete-note', 'done-btn', 'restart-btn', 'change-container-btn', 'status-pill',
        'status-pill-label', 'water-threshold-select', 'threshold-container-name', 'threshold-value',
        'activity-log-body', 'activity-empty', 'activity-log-label',
        'clear-demo-history', 'view-more-activity', 'low-water-banner', 'dispensing-status', 'dispense-description',
        'cup-fill-volume-input', 'tumbler-fill-volume-input', 'cup-fill-volume-feedback', 'tumbler-fill-volume-feedback',
        'cup-target-volume', 'tumbler-target-volume',
        'device-status-connection', 'device-status-container', 'device-status-pump',
        'water-cup-threshold', 'water-tumbler-threshold'
    ];
    idNames.forEach(id => ids.set(id, new Node(id === 'water-threshold-select' || id.endsWith('-fill-volume-input') ? 'input' : 'div', id)));
    const panels = [1, 2, 3, 4].map(step => { const node = new Node(); node.dataset.panel = String(step); node.className = 'dispense-panel'; return node; });
    for (let step = 1; step <= 4; step++) {
        const node = new Node(); node.dataset.step = String(step); node.className = 'dispense-step'; ids.get('dispense-steps').appendChild(node);
        if (step > 1) { const connector = new Node(); connector.dataset.step = String(step); connector.className = 'step-connector'; ids.get('dispense-steps').appendChild(connector); }
    }
    const cards = ['cup', 'tumbler'].map(type => {
        const node = new Node('label'); node.dataset.container = type; node.className = 'container-card';
        const input = new Node('input'); input.value = type; node.appendChild(input); return node;
    });
    const controls = ['container_on', 'container_off', 'next', 'target', 'offline', 'reset'].map(action => {
        const node = new Node('button'); node.dataset.demo = action; return node;
    });
    const documentListeners = {};
    const document = {
        hidden: false, getElementById: id => ids.get(id) || null,
        querySelectorAll: selector => selector === '.dispense-panel' ? panels : selector === '.container-card' ? cards : selector === '[data-demo]' ? controls : [],
        createElement: tag => new Node(tag),
        addEventListener(name, handler) { (documentListeners[name] ||= []).push(handler); }
    };
    let now = 0, timerId = 0, state = freshHardware(), failNextGet = false, deferNextThreshold = false, heldThreshold = null;
    const timers = new Map(), requests = [];
    const schedule = (fn, delay, interval = false) => { const id = ++timerId; timers.set(id, {fn, due: now + Number(delay || 0), delay: Number(delay || 0), interval}); return id; };
    const localStorage = {getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, String(value)), removeItem: key => storage.delete(key)};
    const fetch = async (url, options = {}) => {
        const body = options.body ? JSON.parse(options.body) : null;
        requests.push({url, method: options.method || 'GET', body});
        if (!body && failNextGet) { failNextGet = false; throw new Error('Connection lost'); }
        let response;
        if (!body) response = {success: true, state, activities: state.activities || []};
        else if (body.action === 'select_threshold') {
            if (deferNextThreshold) {
                deferNextThreshold = false;
                await new Promise(resolve => { heldThreshold = resolve; });
            }
            const rule = config.thresholds[body.container_type], threshold = Number(body.threshold_ml);
            const valid = rule && Number.isSafeInteger(threshold) && threshold > 0 && threshold >= rule.min && threshold <= rule.max;
            response = valid ? {success: true, threshold_ml: threshold, target_amount_ml: threshold} : {success: false, error: 'Fill amount must be a whole number within the permitted range.'};
        } else if (body.action === 'request_dispense') response = {success: true, message: 'Queued.', command_id: 77};
        else response = {success: true};
        return {ok: response.success, json: async () => response};
    };
    const sandbox = {
        window: {WaterBoniaDispenser: config, localStorage}, document, localStorage, fetch, console, AbortController,
        setTimeout: (fn, delay) => schedule(fn, delay), clearTimeout: id => timers.delete(id),
        setInterval: (fn, delay) => schedule(fn, delay, true), clearInterval: id => timers.delete(id),
        Option: function(text, value) { const option = new Node('option'); option.textContent = text; option.value = String(value); return option; }
    };
    vm.runInNewContext(source, sandbox, {filename: 'assets/js/dispenser.js'});
    const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
    return {
        ids, cards, controls, requests, storage, config, document, flush,
        text: id => ids.get(id).textContent,
        step: () => Number(panels.find(panel => !panel.hidden)?.dataset.panel),
        async select(type) {
            const card = cards.find(card => card.dataset.container === type), input = card.querySelector('input');
            cards.forEach(item => item.querySelector('input').checked = item === card);
            input.dispatch('change'); card.click(); await flush();
        },
        async threshold(value) { ids.get('water-threshold-select').value = String(value); ids.get('water-threshold-select').dispatch('change'); await flush(); },
        async control(action) { controls.find(control => control.dataset.demo === action).click(); await flush(); },
        async click(id) { ids.get(id).click(); await flush(); },
        async setting(type, value) { const input = ids.get(type + '-fill-volume-input'); input.value = String(value); input.dispatch('change'); await flush(); },
        async state(next) { state = next; await this.advance(2100); },
        async failPoll() { failNextGet = true; await this.advance(2100); },
        deferThreshold() { deferNextThreshold = true; },
        async resolveThreshold() { if (heldThreshold) heldThreshold(); heldThreshold = null; await flush(); },
        async advance(ms) {
            const end = now + ms;
            while (true) {
                const ready = [...timers.entries()].filter(([, timer]) => timer.due <= end).sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
                if (!ready) break;
                const [id, timer] = ready; now = timer.due; timers.delete(id);
                if (timer.interval) timers.set(id, {...timer, due: now + timer.delay});
                timer.fn(); await flush();
            }
            now = end; await flush();
        }
    };
}

function activities(env) {
    const raw = env.storage.get('waterbonia.demo.activities.v2');
    return raw ? JSON.parse(raw) : [];
}
function hasEvent(env, regex) { return activities(env).some(item => regex.test(item.event || item.event_type || '')); }

const tests = [];
function test(name, run) { tests.push({name, run}); }

for (const [type, amount] of [['cup', 250], ['tumbler', 500]]) {
    test(`${type}: explicit start, automatic 100% completion, pump off, Done/Dispense Again`, async () => {
        const env = fixture(); await env.flush();
        assert.equal(env.step(), 1); await env.select(type); assert.equal(env.step(), 2);
        assert.match(env.text('place-container-target'), new RegExp(String(amount)));
        assert.equal(env.ids.get('dispense-btn').disabled, true);
        await env.control('container_on'); assert.equal(env.ids.get('dispense-btn').disabled, false);
        assert.equal(env.step(), 2, 'Detection alone cannot start pump');
        await env.click('dispense-btn'); assert.equal(env.step(), 3); assert.equal(env.text('ro-pump'), 'ON');
        await env.advance(6000); assert.equal(env.step(), 4); assert.equal(env.text('progress-percent'), '100%');
        assert.equal(env.text('ro-pump'), 'OFF'); assert.equal(env.text('co-pump'), 'OFF');
        assert.match(env.text('co-measured'), new RegExp(String(amount)));
        assert.ok(hasEvent(env, /container selected/i)); assert.ok(hasEvent(env, /container detected/i));
        assert.ok(hasEvent(env, /dispensing started/i)); assert.ok(hasEvent(env, /dispensing completed/i));
        assert.ok(activities(env).every(item => item.status === 'Simulated' && item.created_at));
        assert.equal(env.requests.some(request => request.body?.action === 'request_dispense'), false);
        await env.click('done-btn'); assert.equal(env.step(), 1);
        await env.select(type); await env.control('container_on'); await env.click('dispense-btn');
        await env.control('target'); await env.click('restart-btn'); assert.equal(env.step(), 1);
    });
}

test('custom fill cutoff replaces the nominal default, including a smaller cup', async () => {
    const env = fixture(); await env.select('cup'); await env.threshold(150); await env.control('container_on');
    assert.equal(env.ids.get('dispense-btn').disabled, false);
    assert.equal(env.text('place-container-target'), 'Target: 150 mL');
    assert.equal(env.text('cup-target-volume'), '150 mL');
    assert.ok(hasEvent(env, /fill amount selected/i));
    await env.click('dispense-btn'); await env.advance(6000);
    assert.equal(env.step(), 4); assert.equal(env.text('co-target'), '150 mL'); assert.equal(env.text('co-measured'), '150 mL');
    assert.equal(env.text('progress-percent'), '100%'); assert.equal(env.text('co-pump'), 'OFF');
});

test('server validation rejects invalid, fractional, empty, and out-of-range fill amounts', async () => {
    const env = fixture(); await env.select('cup'); await env.control('container_on');
    for (const value of [-1, 'abc', 0, '', 1.5, 2001]) {
        await env.threshold(value);
        assert.equal(env.ids.get('dispense-btn').disabled, true, 'Invalid fill amount must not authorize start');
        assert.match(env.text('dispense-hint'), /whole number|range/);
    }
    for (const value of [1, 150, 275, 2000]) {
        await env.threshold(value); assert.equal(env.ids.get('dispense-btn').disabled, false);
        assert.equal(env.text('place-container-target'), 'Target: ' + value + ' mL');
    }
});

for (const amount of [1, 2000]) {
    test('configured boundary ' + amount + ' mL reaches its cutoff without reporting completion early', async () => {
        const env = fixture(); await env.select('cup'); await env.threshold(amount); await env.control('container_on'); await env.click('dispense-btn');
        await env.advance(2700); assert.equal(env.text('progress-percent'), '60%'); assert.equal(env.text('ro-pump'), 'ON');
        assert.equal(Number(env.text('measured-value')) < amount, true);
        await env.advance(3300); assert.equal(env.text('progress-percent'), '100%'); assert.equal(env.text('co-measured'), amount + ' mL');
        assert.equal(env.text('co-pump'), 'OFF');
    });
}

for (const [action, message] of [['container_off', /removed/i], ['offline', /offline/i]]) {
    test(`active dispensing safely interrupts on ${action}`, async () => {
        const env = fixture(); await env.select('tumbler'); await env.control('container_on'); await env.click('dispense-btn');
        await env.advance(1800); await env.control(action); assert.equal(env.step(), 4);
        assert.match(env.text('complete-title'), /interrupted/i); assert.match(env.text('complete-detail'), message);
        assert.equal(env.text('co-pump'), 'OFF'); assert.equal(env.text('ro-pump'), 'OFF');
        assert.ok(hasEvent(env, /dispensing interrupted/i));
        await env.advance(6000); assert.match(env.text('complete-title'), /interrupted/i);
    });
}

test('offline before starting disables pump and demo Next never starts dispensing', async () => {
    const env = fixture(); await env.select('cup'); await env.control('next'); await env.control('next');
    assert.equal(env.step(), 2); assert.equal(env.text('ro-pump') === 'ON', false);
    await env.control('offline'); assert.equal(env.ids.get('dispense-btn').disabled, true); assert.ok(hasEvent(env, /device offline/i));
});

test('demo history persists across refresh, Reset preserves it, Clear affects only demo storage', async () => {
    const storage = new Map([['real-history-sentinel', 'unchanged']]);
    const env = fixture({}, storage); await env.select('cup'); await env.threshold(300); await env.control('container_on');
    const before = activities(env).length; assert.ok(before >= 3);
    const refresh = fixture({}, storage); await refresh.flush(); assert.equal(activities(refresh).length, before);
    assert.ok(refresh.ids.get('activity-log-body').children.length > 0);
    await refresh.control('reset'); assert.ok(activities(refresh).length >= before);
    assert.equal(storage.get('real-history-sentinel'), 'unchanged');
    await refresh.click('clear-demo-history'); assert.equal(activities(refresh).length, 0);
    assert.equal(storage.get('real-history-sentinel'), 'unchanged');
    assert.equal(refresh.requests.some(request => /delete|clear|reset/.test(request.body?.action || '')), false);
});

test('saved demo activity displays its original date and time across refresh', async () => {
    const created = new Date(2024, 11, 24, 10, 3, 4).toISOString();
    const entry = {created_at: created, time: '10:03:04', event: 'Container selected', details: 'Saved demo event.', status: 'Simulated'};
    const storage = new Map([['waterbonia.demo.activities.v2', JSON.stringify([entry])]]);
    for (let refresh = 0; refresh < 2; refresh++) {
        const env = fixture({}, storage); await env.flush();
        const cell = env.ids.get('activity-log-body').children[0].children[0], time = cell.children[0];
        assert.equal(cell.attributes['data-label'], 'Date & time');
        assert.equal(time.tagName, 'TIME'); assert.equal(time.textContent, '2024-12-24 10:03:04');
        assert.equal(time.attributes.datetime, created);
        assert.equal(activities(env)[0].created_at, created, 'Rendering retains the stored event timestamp');
    }
});

test('legacy demo activity without a timestamp never invents a date', async () => {
    const entries = [undefined, 'not-a-date', '2026-02-31T09:45:00Z', '2026-02-31 09:45:00'].map(created_at => ({created_at, time: '10:03:04', event: 'Legacy demo', details: 'Saved event.', status: 'Simulated'}));
    const storage = new Map([['waterbonia.demo.activities.v2', JSON.stringify(entries)]]);
    const env = fixture({}, storage); await env.flush();
    for (const row of env.ids.get('activity-log-body').children) {
        assert.equal(row.children[0].textContent, 'Date & time unavailable');
        assert.equal(row.children[0].children.length, 0);
    }
});

const blockedHardware = [
    ['stale telemetry', freshHardware({telemetry_valid: false}), /stale|unavailable/i],
    ['sensor error', freshHardware({safety_blocked: true, safety_message: 'Sensor error reported by device.'}), /sensor|error/i],
    ['device offline', freshHardware({device_online: false}), /offline/i],
    ['container absent', freshHardware({container_detected: false}), /container/i],
    ['active pump', freshHardware({pump_status: true}), /pump|active|dispens/i],
    ['pending request', freshHardware({has_pending_command: true}), null]
];
for (const [name, state, message] of blockedHardware) {
    test('hardware: ' + name + ' blocks dispensing', async () => {
        const env = fixture({demoMode: false}); await env.flush(); await env.state(state); await env.select('cup');
        assert.equal(env.ids.get('dispense-btn').disabled, true);
        if (message && env.step() === 2) assert.match(env.text('dispense-hint'), message);
        await env.click('dispense-btn'); assert.equal(env.requests.some(request => request.body?.action === 'request_dispense'), false);
        assert.equal(activities(env).length, 0);
    });
}

test('hardware dispensing needs no water-level sample, percentage, reservoir estimate, or reservoir calibration', async () => {
    const env = fixture({demoMode: false}); await env.flush(); await env.select('cup'); await env.threshold(150);
    assert.equal(env.ids.get('dispense-btn').disabled, false); await env.click('dispense-btn');
    const request = env.requests.find(request => request.body?.action === 'request_dispense');
    assert.equal(request.body.threshold_ml, 150);
    assert.equal(Object.hasOwn(freshHardware(), 'water_level'), false);
    assert.equal(Object.hasOwn(freshHardware(), 'reservoir'), false);
});

test('hardware: legitimate state authorizes explicit request with selected server-validated threshold', async () => {
    const env = fixture({demoMode: false}); await env.flush(); await env.select('tumbler'); await env.threshold(600);
    assert.equal(env.ids.get('dispense-btn').disabled, false);
    assert.equal(env.requests.some(request => request.body?.action === 'request_dispense'), false);
    await env.click('dispense-btn');
    const request = env.requests.find(request => request.body?.action === 'request_dispense');
    assert.equal(request.body.container_type, 'tumbler'); assert.equal(request.body.threshold_ml, 600);
    assert.equal(request.body.csrf_token, 'test-csrf');
});

test('configured per-container ranges and saved fill amounts govern completion', async () => {
    const env = fixture({targets: {cup: 375, tumbler: 850}, selectedThresholds: {cup: 400, tumbler: 900},
        thresholds: {cup: {default: 375, min: 100, max: 500}, tumbler: {default: 850, min: 600, max: 1000}}});
    await env.select('cup'); await env.control('container_on'); await env.threshold(50);
    assert.equal(env.ids.get('dispense-btn').disabled, true);
    await env.threshold(400); await env.click('dispense-btn'); await env.advance(6000);
    assert.equal(env.text('co-target'), '400 mL'); assert.equal(env.text('co-measured'), '400 mL');
    assert.equal(env.ids.get('cup-fill-volume-input').min, '100'); assert.equal(env.ids.get('cup-fill-volume-input').max, '500');
});

test('hardware: failed status refresh invalidates ready state until fresh telemetry returns', async () => {
    const env = fixture({demoMode: false}); await env.flush(); await env.select('cup');
    assert.equal(env.ids.get('dispense-btn').disabled, false); await env.failPoll();
    assert.equal(env.ids.get('dispense-btn').disabled, true); assert.equal(env.text('device-status-connection'), 'UNKNOWN');
    assert.match(env.text('led-label'), /unavailable/i); await env.click('dispense-btn');
    assert.equal(env.requests.some(request => request.body?.action === 'request_dispense'), false);
    await env.advance(2100); assert.equal(env.ids.get('dispense-btn').disabled, false);
});

test('hardware: activity uses server values and ignores local simulated history', async () => {
    const demoStorage = new Map([['waterbonia.demo.activities.v2', JSON.stringify([{event: 'Demo sentinel', details: 'SIMULATED HISTORY', status: 'Simulated'}])]]);
    const env = fixture({demoMode: false}, demoStorage); await env.flush();
    const entry = {created_at: '2026-10-09 09:41:05', date_time: '2026-10-09 09:41:05', time: '09:41:05', event: 'Container detected', details: 'Physical device record.', status: 'Real'};
    await env.state(freshHardware({activities: [entry]}));
    const body = env.ids.get('activity-log-body'), row = body.children[0];
    assert.equal(body.children.length, 1); assert.equal(row.children[0].children[0].textContent, '2026-10-09 09:41:05');
    assert.equal(row.children[0].children[0].attributes.datetime, '2026-10-09T09:41:05', 'Naive database time keeps the recorded server wall time');
    assert.equal(row.children[1].textContent, 'Container detected'); assert.equal(row.children[2].textContent, 'Physical device record.');
    assert.equal(row.children[3].children[0].textContent, 'Real'); assert.equal(env.ids.get('clear-demo-history').hidden, true);
    assert.match(demoStorage.get('waterbonia.demo.activities.v2'), /Demo sentinel/);
    await env.state(freshHardware({activities: []})); assert.equal(env.ids.get('activity-empty').hidden, false);
});

test('hardware activity can display an older API created_at without converting server time', async () => {
    const env = fixture({demoMode: false}); await env.flush();
    await env.state(freshHardware({activities: [{created_at: '2025-01-01 23:59:59', time: '23:59:59', event: 'Device connected', details: 'Recorded event.', status: 'Real'}]}));
    const time = env.ids.get('activity-log-body').children[0].children[0].children[0];
    assert.equal(time.textContent, '2025-01-01 23:59:59'); assert.equal(time.attributes.datetime, '2025-01-01T23:59:59');
});

for (const [status, measured] of [['interrupted', 100], ['completed', null]]) {
    test(`hardware: recorded ${status} result preserves measured volume ${measured}`, async () => {
        const env = fixture({demoMode: false}); await env.flush(); await env.select('cup');
        await env.state(freshHardware({has_session: true, pump_status: true,
            session: {id: 8, status: 'started', container_type: 'cup', target_amount_ml: 250, measured_amount_ml: 100}}));
        assert.equal(env.step(), 3);
        await env.state(freshHardware({last_session: {id: 8, status, container_type: 'cup', target_amount_ml: 250, measured_amount_ml: measured, stop_reason: status === 'interrupted' ? 'Container removed' : 'Target reached'}}));
        assert.equal(env.step(), 4); assert.equal(env.text('co-pump'), 'OFF');
        assert.match(env.text('complete-title'), status === 'interrupted' ? /interrupted/i : /complete/i);
        if (measured === null) assert.match(env.text('co-measured'), /unavailable/i);
        else assert.equal(env.text('co-measured'), '100 mL');
        assert.equal(activities(env).length, 0);
        await env.advance(2100); assert.equal(env.step(), 4, 'Result remains visible until Done');
        await env.click('done-btn'); assert.equal(env.step(), 1); await env.advance(2100); assert.equal(env.step(), 1);
    });
}

test('hardware: an old unrelated recorded session cannot fabricate request completion', async () => {
    const env = fixture({demoMode: false}); await env.flush();
    const last = {id: 2, status: 'completed', container_type: 'cup', target_amount_ml: 250, measured_amount_ml: 250};
    await env.state(freshHardware({last_session: last})); await env.select('cup'); assert.equal(env.step(), 2);
    await env.click('dispense-btn'); assert.equal(env.step(), 3); await env.state(freshHardware({last_session: last}));
    assert.equal(env.step(), 3, 'Old completed session must never finish a new request');
});

test('late threshold validation cannot overwrite the newer container selection', async () => {
    const env = fixture(); env.deferThreshold(); await env.select('cup');
    assert.equal(env.ids.get('dispense-btn').disabled, true); await env.select('tumbler');
    await env.control('container_on'); assert.equal(env.ids.get('dispense-btn').disabled, false);
    await env.resolveThreshold(); assert.match(env.text('place-container-name'), /tumbler/i);
    assert.match(env.text('threshold-value'), /500/); assert.equal(env.ids.get('dispense-btn').disabled, false);
    await env.click('dispense-btn'); await env.advance(6000); assert.match(env.text('co-target'), /500/);
});

test('activity event/details render as literal text even when supplied markup', async () => {
    const malicious = '<img src=x onerror=alert(1)>', event = '<script>bad()</script>';
    const storage = new Map([['waterbonia.demo.activities.v2', JSON.stringify([{created_at: '2026-10-09T01:00:00Z', time: '09:00:00', event, details: malicious, status: 'Simulated'}])]]);
    const env = fixture({}, storage); await env.flush();
    const row = env.ids.get('activity-log-body').children[0];
    assert.equal(row.children[1].textContent, event); assert.equal(row.children[2].textContent, malicious);
    assert.equal(row.children[1].htmlWasAssigned, undefined); assert.equal(row.children[2].htmlWasAssigned, undefined);
    assert.equal(row.children[2].children.length, 0);
});

for (const status of ['expired', 'rejected', 'cancelled']) {
    test(`hardware: matching ${status} command unlocks waiting flow without claiming dispensed water`, async () => {
        const env = fixture({demoMode: false}); await env.flush(); await env.select('cup'); await env.click('dispense-btn');
        assert.equal(env.step(), 3);
        await env.state(freshHardware({request_command: {id: 77, status, container_type: 'cup', target_amount_ml: 250, threshold_ml: 250, session_id: null, message: 'Request ' + status}}));
        assert.equal(env.step(), 4); assert.match(env.text('complete-title'), new RegExp(status, 'i'));
        assert.match(env.text('co-measured'), /unavailable/i); assert.equal(env.ids.get('dispense-btn').disabled, true);
        await env.click('done-btn'); assert.equal(env.step(), 1);
    });
}

test('hardware: unconfirmed or offline telemetry displays unknown pump and LED', async () => {
    const env = fixture({demoMode: false}); await env.flush();
    for (const availability of [{telemetry_valid: false}, {device_online: false}]) {
        await env.state(freshHardware({...availability, has_session: true, pump_status: true,
            session: {id: 8, status: 'started', container_type: 'cup', target_amount_ml: 250, measured_amount_ml: 100}}));
        assert.equal(env.text('ro-pump'), 'UNKNOWN'); assert.equal(env.text('ro-system'), 'UNKNOWN');
        assert.match(env.text('led-label'), /unavailable/i);
    }
});

test('container settings independently save Cup 150 mL and Tumbler 650 mL, then dispense to their cutoffs', async () => {
    const env = fixture(); await env.flush();
    assert.equal(env.text('device-status-connection'), 'SIMULATED ONLINE');
    assert.equal(env.text('device-status-container'), 'SIMULATED NOT DETECTED');
    assert.equal(env.text('device-status-pump'), 'SIMULATED OFF');
    await env.setting('cup', 150); await env.setting('tumbler', 650);
    assert.equal(env.text('cup-target-volume'), '150 mL'); assert.equal(env.text('tumbler-target-volume'), '650 mL');
    assert.equal(env.text('water-cup-threshold'), '150 mL'); assert.equal(env.text('water-tumbler-threshold'), '650 mL');
    for (const [type, amount] of [['cup', 150], ['tumbler', 650]]) {
        await env.select(type); await env.control('container_on'); await env.click('dispense-btn');
        assert.equal(env.text('device-status-pump'), 'SIMULATED ON');
        await env.advance(6000);
        assert.equal(env.text('co-target'), amount + ' mL'); assert.equal(env.text('co-measured'), amount + ' mL');
        assert.equal(env.text('progress-percent'), '100%'); assert.equal(env.text('device-status-pump'), 'SIMULATED OFF');
        await env.click('done-btn');
    }
    assert.equal(activities(env).some(entry => /reservoir|water.level|low.water|minimum/i.test(entry.event + ' ' + entry.details)), false);
});

test('container settings show validation feedback and reject changes during active dispensing', async () => {
    const env = fixture(); await env.flush();
    await env.setting('cup', 0);
    assert.equal(env.ids.get('cup-fill-volume-feedback').hidden, false);
    assert.match(env.text('cup-fill-volume-feedback'), /whole number|range/);
    assert.equal(env.text('cup-target-volume'), '250 mL');
    await env.setting('cup', 150); assert.match(env.text('cup-fill-volume-feedback'), /150 mL/);
    await env.select('cup'); await env.control('container_on'); await env.click('dispense-btn');
    const requests = env.requests.length;
    assert.equal(env.ids.get('cup-fill-volume-input').disabled, true); assert.equal(env.ids.get('tumbler-fill-volume-input').disabled, true);
    await env.setting('cup', 800); await env.setting('tumbler', 900);
    assert.equal(env.requests.length, requests);
    assert.equal(env.text('cup-target-volume'), '150 mL'); assert.equal(env.text('tumbler-target-volume'), '500 mL');
    await env.advance(6000); assert.equal(env.text('co-target'), '150 mL'); assert.equal(env.text('co-measured'), '150 mL');
    assert.equal(env.ids.get('cup-fill-volume-input').disabled, false);
    await env.control('reset'); assert.equal(env.text('device-status-pump'), 'SIMULATED OFF');
    assert.equal(env.ids.get('cup-fill-volume-input').value, '150');
});

test('latest validation for a container wins and an older response cannot overwrite it', async () => {
    const env = fixture(); await env.select('cup'); env.deferThreshold(); await env.threshold(150);
    assert.equal(env.ids.get('dispense-btn').disabled, true);
    await env.threshold(300); await env.resolveThreshold(); await env.control('container_on');
    assert.equal(env.text('place-container-target'), 'Target: 300 mL');
    await env.click('dispense-btn'); await env.advance(6000);
    assert.equal(env.text('co-target'), '300 mL');
});

test('a validation response arriving during dispensing cannot change the active target', async () => {
    const env = fixture(); env.deferThreshold(); await env.setting('tumbler', 650);
    await env.select('cup'); await env.control('container_on'); await env.click('dispense-btn');
    await env.resolveThreshold(); assert.equal(env.text('target-value'), '250'); assert.equal(env.text('tumbler-target-volume'), '500 mL');
    await env.advance(6000); assert.equal(env.text('co-target'), '250 mL'); assert.equal(env.text('co-measured'), '250 mL');
});

test('hardware command and session targets remain authoritative while preferences change', async () => {
    const env = fixture({demoMode: false}); await env.flush(); await env.select('cup'); await env.threshold(150); await env.click('dispense-btn');
    await env.state(freshHardware({has_pending_command: true, pending_command: {id: 77, container_type: 'cup', target_amount_ml: 150}}));
    assert.equal(env.text('ro-target'), '150 mL'); assert.equal(env.ids.get('cup-fill-volume-input').disabled, true);
    await env.setting('cup', 800); assert.equal(env.text('cup-target-volume'), '150 mL');
    await env.state(freshHardware({has_session: true, pump_status: true, session: {id: 88, container_type: 'cup', target_amount_ml: 175, measured_amount_ml: 80}}));
    assert.equal(env.text('target-value'), '175'); assert.equal(env.text('ro-target'), '175 mL');
    await env.failPoll(); assert.equal(env.text('ro-target'), '175 mL'); assert.equal(env.text('ro-pump'), 'UNKNOWN');
    await env.state(freshHardware({last_session: {id: 88, status: 'completed', container_type: 'cup', target_amount_ml: 175, measured_amount_ml: 175}}));
    assert.equal(env.text('co-target'), '175 mL'); assert.equal(env.text('co-measured'), '175 mL');
    await env.setting('cup', 800); assert.equal(env.text('co-target'), '175 mL'); assert.equal(env.text('target-value'), '175');
    assert.equal(env.text('cup-target-volume'), '800 mL');
});

test('hardware status invalidates unavailable telemetry and connection without water-level assumptions', async () => {
    const env = fixture({demoMode: false});
    assert.equal(env.text('device-status-connection'), 'UNKNOWN'); await env.flush();
    assert.equal(env.text('device-status-connection'), 'ONLINE'); assert.equal(env.text('device-status-container'), 'DETECTED'); assert.equal(env.text('device-status-pump'), 'OFF');
    await env.state(freshHardware({telemetry_valid: false}));
    assert.equal(env.text('device-status-container'), 'UNKNOWN'); assert.equal(env.text('device-status-pump'), 'UNKNOWN');
    await env.state(freshHardware({device_online: false})); assert.equal(env.text('device-status-connection'), 'OFFLINE');
    await env.failPoll();
    for (const name of ['connection', 'container', 'pump']) assert.equal(env.text('device-status-' + name), 'UNKNOWN');
});

test('new demo history ignores and preserves the previous reservoir simulation archive', async () => {
    const legacy = JSON.stringify([{event: 'Reservoir simulated', details: 'Old water-level simulation.', status: 'Simulated'}]);
    const storage = new Map([['waterbonia.demo.activities.v1', legacy]]);
    const env = fixture({}, storage); await env.flush();
    assert.equal(activities(env).length, 0); assert.equal(env.ids.get('activity-log-body').children.length, 0);
    await env.select('cup'); assert.ok(activities(env).length > 0);
    await env.click('clear-demo-history'); assert.equal(activities(env).length, 0);
    assert.equal(storage.get('waterbonia.demo.activities.v1'), legacy);
});

(async () => {
    let failed = 0;
    for (const {name, run} of tests) {
        try { await run(); console.log(`PASS ${name}`); }
        catch (error) { failed++; console.error(`FAIL ${name}\n${error.stack}`); }
    }
    console.log(`\n${tests.length - failed}/${tests.length} controller regression tests passed.`);
    process.exitCode = failed ? 1 : 0;
})();
