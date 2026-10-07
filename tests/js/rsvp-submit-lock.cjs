// Runs public/js/rsvp-form.js against a stubbed DOM and checks the submit lock: the first submit goes through and
// disables the button, a second is prevented, and pageshow from the back/forward cache unlocks it. Run by
// tests/Feature/RsvpSubmitLockJsTest.php (skipped without node); by hand: `node tests/js/rsvp-submit-lock.cjs`.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'rsvp-form.js'), 'utf8');

function fail(message) {
    console.error('FAIL: ' + message);
    process.exit(1);
}

const button = {
    disabled: false,
    innerHTML: 'Send my response',
    attrs: {},
    parentNode: { insertBefore() {} },
    setAttribute(k, v) { this.attrs[k] = v; },
    removeAttribute(k) { delete this.attrs[k]; },
    set textContent(v) { this.innerHTML = v; },
};

const listeners = {};
const form = {
    hasAttribute: () => false,
    checkValidity: () => true,
    querySelector: (sel) => (sel === 'button[type="submit"]' ? button : null),
    addEventListener(type, fn) { listeners[type] = fn; },
};

const winListeners = {};
let domReady;
const sandbox = {
    document: {
        addEventListener(type, fn) { if (type === 'DOMContentLoaded') domReady = fn; },
        querySelectorAll: () => [form],
        createElement: () => ({ setAttribute() {} }),
    },
    window: { addEventListener(type, fn) { winListeners[type] = fn; } },
    setTimeout: (fn) => { fn.__queued = true; pending.push(fn); return pending.length; },
    clearTimeout() {},
};
const pending = [];

vm.runInNewContext(source, sandbox);
domReady();

const first = { prevented: false, preventDefault() { this.prevented = true; } };
listeners.submit(first);
if (first.prevented) fail('first submit must go through');

pending[0](); // the zero-delay timer that disables the button
if (!button.disabled) fail('button should be disabled after the first submit');

const second = { prevented: false, preventDefault() { this.prevented = true; } };
listeners.submit(second);
if (!second.prevented) fail('second submit must be prevented');

winListeners.pageshow({ persisted: true });
if (button.disabled) fail('pageshow from bfcache should unlock the button');

const third = { prevented: false, preventDefault() { this.prevented = true; } };
listeners.submit(third);
if (third.prevented) fail('submit after unlock must go through');

console.log('ok');
