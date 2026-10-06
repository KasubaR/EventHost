// Checks the media rule in public/js/invitation-public.js: when a background video, the YouTube player and music may start by
// themselves, and what is done instead. Runs the real script against stubbed browsers. Run by
// tests/Feature/InvitationCompatibilityTest.php (skipped when node is not installed); also runnable by hand:
// `node tests/js/invitation-media.cjs`. Exits 1 and prints the reason on the first failed check.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'invitation-public.js'), 'utf8');

function fail(message) {
    console.error('FAIL: ' + message);
    process.exit(1);
}

const FB_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 [FBAN/FBIOS;FBAV/410.0]';
const PLAIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

/**
 * @param {object} browser {connection, userAgent, pointerFine, wide, matchMedia (false removes it)}
 * @param {object} page    {audio: bool, calendar: bool}
 */
function run(browser, page = {}) {
    const events = { audio: [], windowListeners: [], hintInserted: false };

    class FakeAudio {
        constructor(src) {
            events.audio.push(this);
            this.constructedWith = src;
            this.preload = undefined;
            this.played = 0;
        }
        addEventListener() {}
        play() { this.played++; return Promise.resolve(); }
        pause() {}
    }

    const audioButton = {
        getAttribute: () => '/storage/song.mp3',
        parentNode: null,
        querySelector: () => null,
        addEventListener() {},
        contains: () => false,
    };
    const calendar = {
        querySelector: () => (events.hintInserted ? {} : null),
        insertBefore: () => { events.hintInserted = true; },
        firstChild: null,
    };

    const root = {
        getAttribute: () => null,
        querySelector(sel) {
            if (sel === '[data-inv-audio-play]' && page.audio) return audioButton;
            if (sel === '.evt-calendar-actions' && page.calendar) return calendar;
            return null;
        },
        querySelectorAll: () => [],
        addEventListener() {},
    };

    const document = {
        readyState: 'complete',
        documentElement: { setAttribute() {}, classList: { remove() {}, add() {} } },
        querySelectorAll: (sel) => (sel === '.evt-invitation' ? [root] : []),
        createElement: () => ({ style: {}, classList: { add() {} }, setAttribute() {} }),
        addEventListener() {},
    };

    const window = {
        console: { warn() {} },
        addEventListener: (name) => events.windowListeners.push(name),
        removeEventListener() {},
    };
    if (browser.matchMedia !== false) {
        window.matchMedia = (query) => ({
            matches: query.includes('pointer: fine') ? !!browser.pointerFine
                : query.includes('min-width: 900px') ? !!browser.wide
                    : false,
        });
    }

    const navigator = { userAgent: browser.userAgent || PLAIN_UA };
    if (browser.connection) navigator.connection = browser.connection;

    vm.runInNewContext(source, { window, document, navigator, Audio: FakeAudio, console: window.console });

    return { api: window.EventHostInvitation, events };
}

function expect(label, browser, expected) {
    const { api } = run(browser);
    const got = api.mayStartMedia();
    if (got !== expected) fail(label + ': mayStartMedia() was ' + got + ', expected ' + expected);
}

// ── the rule ──
// A measured connection decides on its own, whatever the device.
expect('4g on a phone', { connection: { effectiveType: '4g' }, pointerFine: false, wide: false }, true);
expect('3g on a phone', { connection: { effectiveType: '3g' }, pointerFine: false, wide: false }, true);
expect('2g on a desktop', { connection: { effectiveType: '2g' }, pointerFine: true, wide: true }, false);
expect('slow-2g', { connection: { effectiveType: 'slow-2g' }, pointerFine: true, wide: true }, false);
expect('Save-Data on 4g', { connection: { effectiveType: '4g', saveData: true }, pointerFine: true, wide: true }, false);
expect('4g inside Facebook', { connection: { effectiveType: '4g' }, userAgent: FB_UA, pointerFine: false, wide: false }, true);

// An unmeasurable connection: only a desktop-class device in an ordinary browser starts on its own.
expect('unknown connection, desktop', { pointerFine: true, wide: true }, true);
expect('unknown connection, phone (iOS/WhatsApp/Firefox)', { pointerFine: false, wide: false }, false);
expect('unknown connection, wide touch tablet', { pointerFine: false, wide: true }, false);
expect('unknown connection, narrow window with a mouse', { pointerFine: true, wide: false }, false);
expect('unknown connection, desktop-class but in Facebook', { pointerFine: true, wide: true, userAgent: FB_UA }, false);
expect('unknown connection, no matchMedia', { matchMedia: false }, false);

// ── the music ──
{
    const { events } = run({ connection: { effectiveType: '4g' } }, { audio: true });
    const audio = events.audio[0];
    if (!audio) fail('no audio object was created');
    if (audio.constructedWith !== undefined) fail('the audio must be created without a source, so nothing is fetched before the preload is set');
    if (audio.preload !== 'auto') fail('music should preload on a good connection, got ' + audio.preload);
    if (audio.played < 1) fail('music should be tried on a good connection');
}
{
    const { events } = run({ pointerFine: false, wide: false }, { audio: true });
    const audio = events.audio[0];
    if (audio.preload !== 'none') fail('music must not preload on a phone with an unmeasurable connection, got ' + audio.preload);
    if (audio.played !== 0) fail('music must not autoplay on a phone with an unmeasurable connection');
    if (events.windowListeners.length !== 0) fail('no start-on-first-tap listener should be added when media may not start by itself');
}
{
    const { events } = run({ connection: { effectiveType: '2g' } }, { audio: true });
    if (events.audio[0].preload !== 'none' || events.audio[0].played !== 0) fail('music must wait for a tap on 2g');
}

// ── the in-app hint ──
{
    const { events } = run({ userAgent: FB_UA }, { calendar: true });
    if (!events.hintInserted) fail('a Facebook in-app browser should get the hint above the calendar links');
}
{
    const { events } = run({ userAgent: PLAIN_UA }, { calendar: true });
    if (events.hintInserted) fail('an ordinary browser must not get the hint');
}

console.log('ok');
