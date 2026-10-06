// Runs public/js/invitation-public.js against a stubbed browser and checks that one failing step does not stop the
// others. Run by tests/Feature/InvitationCompatibilityTest.php (skipped when node is not installed); also runnable
// by hand: `node tests/js/invitation-boot.cjs`. Exits 1 and prints the reason on the first failed check.

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'invitation-public.js'), 'utf8');

function fail(message) {
    console.error('FAIL: ' + message);
    process.exit(1);
}

function run({ countdownThrows, galleryThrows, revealThrows }) {
    const log = [];
    const warnings = [];

    const node = () => ({
        classList: { add: (c) => log.push('add:' + c), remove() {}, contains: () => false, toggle() {} },
        setAttribute() {},
        getAttribute: () => null,
    });
    const revealNodes = [node(), node()];

    const countdownEl = {
        getAttribute() { throw new Error('old engine: countdown'); },
    };
    const galleryWrap = {
        classList: { add: (c) => log.push('wrap:' + c) },
        querySelector: (sel) => (sel === '.evt-inv-gallery-swiper' ? {} : null),
        querySelectorAll: () => [],
    };

    const root = {
        querySelector(sel) {
            if (sel === '[data-inv-countdown]' && countdownThrows) return countdownEl;
            if (sel === '[data-inv-gallery]') return galleryWrap;
            return null;
        },
        querySelectorAll(sel) {
            if (sel === '[data-wi-reveal]') {
                if (revealThrows) throw new Error('old engine: reveal');
                return revealNodes;
            }
            return [];
        },
        addEventListener: (type) => log.push('listen:' + type),
    };

    const document = {
        readyState: 'complete',
        documentElement: {
            setAttribute: (name) => log.push('ready:' + name),
            classList: { remove: (c) => log.push('rm:' + c), add() {} },
        },
        querySelectorAll: (sel) => (sel === '.evt-invitation' ? [root] : []),
        addEventListener() {},
    };

    const window = {
        console: { warn: (...args) => warnings.push(args.join(' ')) },
        matchMedia: () => ({ matches: false }),
        Swiper: galleryThrows ? function () { throw new Error('old engine: swiper'); } : function () {},
    };

    vm.runInNewContext(source, { window, document, navigator: {}, console: window.console });

    return { log, warnings };
}

// 1. The countdown and the slider both throw: the reveals still run, the page is still marked ready, and the steps
//    after the failures (image fallbacks) still run.
{
    const { log, warnings } = run({ countdownThrows: true, galleryThrows: true, revealThrows: false });

    const reveals = log.filter((entry) => entry === 'add:wi-reveal--visible').length;
    if (reveals !== 2) fail('expected both reveal sections to be revealed, got ' + reveals);

    const firstReveal = log.indexOf('add:wi-reveal--visible');
    const ready = log.indexOf('ready:data-inv-ready');
    if (ready === -1) fail('the page was never marked ready');
    if (ready < firstReveal) fail('the page was marked ready before the reveals ran');

    if (!log.includes('listen:error')) fail('image fallbacks did not run after the countdown threw');
    if (!warnings.some((w) => w.includes('countdown failed'))) fail('the countdown failure was not reported');
    if (!warnings.some((w) => w.includes('gallery failed'))) fail('the gallery failure was not reported');
}

// 2. Even if the reveal step itself throws, the page is marked ready and the later steps run.
{
    const { log, warnings } = run({ countdownThrows: false, galleryThrows: false, revealThrows: true });

    if (!log.includes('ready:data-inv-ready')) fail('a throwing reveal step stopped the page being marked ready');
    if (!log.includes('listen:error')) fail('a throwing reveal step stopped the later steps');
    if (!warnings.some((w) => w.includes('wedding reveal failed'))) fail('the reveal failure was not reported');
}

// 3. Nothing throws: everything runs once and nothing is reported.
{
    const { log, warnings } = run({ countdownThrows: false, galleryThrows: false, revealThrows: false });

    if (warnings.length !== 0) fail('unexpected warnings: ' + warnings.join(' | '));
    if (log.filter((entry) => entry === 'add:wi-reveal--visible').length !== 2) fail('reveals did not run on the happy path');
}

console.log('ok');
