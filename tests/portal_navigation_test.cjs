/* Behavioral sidebar navigation checks. Run: node tests/portal_navigation_test.cjs */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/portal-navigation.js'), 'utf8');
const sectionKeys = ['dispense-workflow', 'container-settings', 'led-card', 'activity-logs', 'safety-sequence'];
const sectionTitles = {
    'dispense-workflow': 'Dispense Water', 'container-settings': 'Container Settings',
    'led-card': 'Device Status', 'activity-logs': 'Activity Logs',
    'safety-sequence': 'Safety Sequence'
};

class Node {
    constructor(dataset, id = '') {
        this.dataset = dataset;
        this.id = id;
        this._hidden = false;
        this.visibilityWrites = 0;
        this.attributes = {};
        this.listeners = {};
        this.focusCount = 0;
        this.scrollCount = 0;
        this.target = '';
    }
    get hidden() { return this._hidden; }
    set hidden(value) { this._hidden = value; this.visibilityWrites++; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
    addEventListener(name, handler) { (this.listeners[name] ||= []).push(handler); }
    closest(selector) { return selector === 'details' ? this.menu || null : null; }
    focus(options) { this.focusCount++; this.focusOptions = options; }
    scrollIntoView(options) { this.scrollCount++; this.scrollOptions = options; }
    click(extra = {}) {
        const event = {button: 0, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra};
        (this.listeners.click || []).forEach(handler => handler(event));
        return event;
    }
}

function fixture(options = {}) {
    const keys = options.keys || sectionKeys;
    const sections = keys.map(key => new Node({portalSection: key, portalTitle: sectionTitles[key]}, key));
    const pageHeading = {textContent: 'Dispense Water'};
    const base = 'http://localhost/WaterBonia/pages/dispense.php';
    let location = new URL(base + (options.hash || ''));
    const menu = {open: true};
    const links = keys.flatMap(key => [false, true].map(mobile => {
        const link = new Node({portalLink: key});
        link.href = base + '#' + key;
        if (mobile) link.menu = menu;
        return link;
    }));
    const listeners = {}, documentListeners = {};
    const history = [location.href];
    let position = 0;
    const state = {phase: 'dispensing', selected: 'cup', current: 130, target: 300, timers: [1, 2], activities: [{event: 'start'}]};
    const originalState = JSON.stringify(state);
    let forbiddenCalls = 0;
    const window = {
        get location() { return location; },
        WaterBoniaDispenser: state,
        history: {pushState(_state, _title, url) {
            location = new URL(url, location.href);
            history.splice(position + 1);
            history.push(location.href); position++;
        }},
        addEventListener(name, handler) { (listeners[name] ||= []).push(handler); }
    };
    const document = {
        readyState: options.loading ? 'loading' : 'complete',
        getElementById: id => id === 'portal-page-heading' && !options.noToolbar ? pageHeading : null,
        querySelectorAll: selector => selector === '[data-portal-section]' ? sections : selector === '[data-portal-link]' ? links : [],
        addEventListener(name, handler) { (documentListeners[name] ||= []).push(handler); }
    };
    const prohibited = () => { forbiddenCalls++; throw new Error('Navigation must leave dispenser side effects alone.'); };
    vm.runInNewContext(source, {
        window, document, URL, Map, console,
        fetch: prohibited, setTimeout: prohibited, setInterval: prohibited,
        clearTimeout: prohibited, clearInterval: prohibited, localStorage: {clear: prohibited, setItem: prohibited}
    }, {filename: 'assets/js/portal-navigation.js'});
    const dispatch = name => (listeners[name] || []).forEach(handler => handler());
    return {
        sections, links, menu, history, state, originalState, window, pageHeading,
        visible: () => sections.filter(section => !section.hidden).map(section => section.id),
        active: () => links.find(link => link.attributes['aria-current'] === 'page')?.dataset.portalLink,
        link: (key, mobile = false) => links.find(link => link.dataset.portalLink === key && !!link.menu === mobile),
        setHash(hash) { location = new URL(base + hash); dispatch('hashchange'); },
        back() { if (position) { location = new URL(history[--position]); dispatch('popstate'); } },
        forward() { if (position + 1 < history.length) { location = new URL(history[++position]); dispatch('popstate'); } },
        ready() { (documentListeners.DOMContentLoaded || []).forEach(handler => handler()); },
        forbiddenCalls: () => forbiddenCalls
    };
}

const tests = [];
function test(name, run) { tests.push({name, run}); }

test('Dispense is the only visible section by default and both navigation layouts mark it current', () => {
    const env = fixture();
    assert.deepEqual(env.visible(), ['dispense-workflow']);
    assert.equal(env.active(), 'dispense-workflow');
    assert.equal(env.links.filter(link => link.attributes['aria-current'] === 'page').length, 2);
    assert.ok(env.links.filter(link => link.attributes['aria-current']).every(link => link.dataset.portalLink === 'dispense-workflow'));
    assert.equal(env.sections.reduce((sum, section) => sum + section.focusCount, 0), 0);
});

test('Initial valid deep link shows only the requested section without stealing focus', () => {
    const env = fixture({hash: '#activity-logs'});
    assert.equal(env.active(), 'activity-logs');
    assert.deepEqual(env.visible(), ['activity-logs']);
    assert.equal(env.pageHeading.textContent, 'Activity Logs');
    assert.equal(env.link('activity-logs').attributes['aria-current'], 'page');
    assert.equal(env.sections.find(section => section.id === 'activity-logs').focusCount, 0);
    assert.equal(env.history.length, 1);
});

test('Sidebar selection shows one section, updates the toolbar and history and focuses its content', () => {
    const env = fixture();
    const event = env.link('container-settings').click();
    assert.equal(event.defaultPrevented, true);
    assert.equal(env.active(), 'container-settings');
    assert.deepEqual(env.visible(), ['container-settings']);
    assert.equal(env.pageHeading.textContent, 'Container Settings');
    const section = env.sections.find(item => item.id === 'container-settings');
    assert.equal(section.focusCount, 1);
    assert.equal(section.attributes.tabindex, '-1');
    assert.equal(section.focusOptions.preventScroll, true);
    assert.equal(env.window.location.hash, '#container-settings');
    assert.equal(env.link('dispense-workflow').attributes['aria-current'], undefined);
});

test('Mobile keyboard activation closes its menu and updates both active links', () => {
    const env = fixture();
    env.link('led-card', true).click({detail: 0});
    assert.equal(env.menu.open, false);
    assert.equal(env.active(), 'led-card');
    assert.deepEqual(env.visible(), ['led-card']);
    assert.equal(env.link('led-card').attributes['aria-current'], 'page');
    assert.equal(env.link('led-card', true).attributes['aria-current'], 'page');
});

test('Selecting the current section keeps history unchanged and focuses it', () => {
    const env = fixture({hash: '#safety-sequence'});
    env.link('safety-sequence').click();
    assert.equal(env.history.length, 1);
    assert.equal(env.active(), 'safety-sequence');
    assert.equal(env.sections.find(section => section.id === 'safety-sequence').focusCount, 1);
});

test('Back and forward restore active navigation and toolbar without reloading or extra focus', () => {
    const env = fixture();
    env.link('activity-logs').click();
    env.link('led-card').click();
    env.back(); assert.equal(env.active(), 'activity-logs');
    assert.deepEqual(env.visible(), ['activity-logs']);
    assert.equal(env.pageHeading.textContent, 'Activity Logs');
    env.back(); assert.equal(env.active(), 'dispense-workflow');
    assert.deepEqual(env.visible(), ['dispense-workflow']);
    assert.equal(env.pageHeading.textContent, 'Dispense Water');
    env.forward(); assert.equal(env.active(), 'activity-logs');
    assert.deepEqual(env.visible(), ['activity-logs']);
    assert.equal(env.pageHeading.textContent, 'Activity Logs');
    assert.equal(env.sections.find(section => section.id === 'activity-logs').focusCount, 1);
});

test('Sidebar navigation scrolls to the selected section, while initial and history changes leave scrolling to the browser', () => {
    const env = fixture({hash: '#activity-logs'});
    const activity = env.sections.find(section => section.id === 'activity-logs');
    const water = env.sections.find(section => section.id === 'container-settings');
    assert.equal(activity.scrollCount, 0);
    env.link('container-settings').click();
    assert.equal(water.scrollCount, 1);
    assert.equal(water.scrollOptions.block, 'start');
    assert.equal(water.scrollOptions.behavior, 'auto');
    env.back();
    assert.equal(env.active(), 'activity-logs');
    assert.equal(activity.scrollCount, 0);
    env.forward();
    assert.equal(water.scrollCount, 1);
    env.setHash('#led-card');
    assert.equal(env.sections.find(section => section.id === 'led-card').scrollCount, 0);
});

test('A direct hash change updates the active section and toolbar', () => {
    const env = fixture();
    env.setHash('#led-card');
    assert.equal(env.active(), 'led-card');
    assert.equal(env.pageHeading.textContent, 'Device Status');
    env.setHash('#container-settings');
    assert.equal(env.active(), 'container-settings');
    assert.equal(env.pageHeading.textContent, 'Container Settings');
});

test('Unknown, malformed, and empty hashes select Dispense without rewriting the URL', () => {
    for (const hash of ['#unknown', '#%E0%A4%A', '']) {
        const env = fixture({hash});
        assert.equal(env.active(), 'dispense-workflow');
        assert.deepEqual(env.visible(), ['dispense-workflow']);
        assert.equal(env.pageHeading.textContent, 'Dispense Water');
        assert.equal(env.window.location.hash, hash);
    }
});

test('A hardware portal cannot select a nonexistent Demo Controls section', () => {
    const env = fixture({keys: sectionKeys.filter(key => key !== 'demo-card'), hash: '#demo-card'});
    assert.equal(env.active(), 'dispense-workflow');
    assert.deepEqual(env.visible(), ['dispense-workflow']);
    assert.equal(env.links.some(link => link.attributes['aria-current'] === 'page' && link.dataset.portalLink === 'demo-card'), false);
});

test('The main landmark skip link preserves the active section, toolbar and URL', () => {
    const env = fixture({hash: '#activity-logs'});
    env.setHash('#execution-main');
    assert.equal(env.active(), 'activity-logs');
    assert.deepEqual(env.visible(), ['activity-logs']);
    assert.equal(env.pageHeading.textContent, 'Activity Logs');
    assert.equal(env.window.location.hash, '#execution-main');
    assert.equal(fixture({hash: '#execution-main'}).active(), 'dispense-workflow');
});

test('Modified clicks, downloads, and links for another tab keep browser behavior', () => {
    const env = fixture();
    const link = env.link('activity-logs');
    for (const extra of [{ctrlKey: true}, {metaKey: true}, {shiftKey: true}, {altKey: true}, {button: 1}, {defaultPrevented: true}]) {
        const event = link.click(extra);
        assert.equal(event.defaultPrevented, !!extra.defaultPrevented);
        assert.equal(env.active(), 'dispense-workflow');
    }
    link.target = '_blank'; assert.equal(link.click().defaultPrevented, false);
    link.target = ''; link.setAttribute('download', ''); assert.equal(link.click().defaultPrevented, false);
});

test('External, other-page, mismatched-key, and query-changing links are not intercepted', () => {
    const env = fixture();
    const link = env.link('activity-logs');
    for (const href of [
        'https://example.com/WaterBonia/pages/dispense.php#activity-logs',
        'http://localhost/WaterBonia/pages/other.php#activity-logs',
        'http://localhost/WaterBonia/pages/dispense.php#led-card',
        'http://localhost/WaterBonia/pages/dispense.php?mode=other#activity-logs',
        'http://['
    ]) {
        link.href = href;
        assert.equal(link.click().defaultPrevented, false);
        assert.equal(env.active(), 'dispense-workflow');
    }
});

test('Navigation leaves active dispensing state, timers, storage and network untouched', () => {
    const env = fixture();
    sectionKeys.forEach(key => {
        env.link(key).click();
        assert.deepEqual(env.visible(), [key]);
    });
    env.back(); env.forward(); env.setHash('#activity-logs');
    assert.equal(JSON.stringify(env.state), env.originalState);
    assert.equal(env.forbiddenCalls(), 0);
    assert.deepEqual(env.visible(), ['activity-logs']);
});

test('Initialization waits for markup when the script loads in the document head', () => {
    const env = fixture({loading: true, hash: '#activity-logs'});
    assert.equal(env.link('activity-logs').listeners.click, undefined);
    env.ready();
    assert.equal(env.active(), 'activity-logs');
    assert.equal(env.link('activity-logs').click().defaultPrevented, true);
});

test('An outage page without rendered sections needs no navigation controller', () => {
    const env = fixture({keys: []});
    assert.deepEqual(env.visible(), []);
    assert.equal(env.forbiddenCalls(), 0);
});

test('Section navigation remains functional when the optional toolbar heading is absent', () => {
    const env = fixture({noToolbar: true});
    env.link('safety-sequence').click();
    assert.equal(env.active(), 'safety-sequence');
    assert.deepEqual(env.visible(), ['safety-sequence']);
});

test('Every section uses its configured title in the toolbar without HTML replacement', () => {
    const env = fixture();
    for (const key of sectionKeys) {
        env.link(key).click();
        assert.equal(env.pageHeading.textContent, sectionTitles[key]);
    }
    assert.equal(Object.hasOwn(env.pageHeading, 'innerHTML'), false);
});

let failures = 0;
for (const {name, run} of tests) {
    try { run(); console.log('PASS ' + name); }
    catch (error) { failures++; console.error('FAIL ' + name + '\n' + error.stack); }
}
console.log(`${tests.length - failures}/${tests.length} portal navigation checks passed.`);
if (failures) process.exitCode = 1;
