'use strict';

// Dependency-free actual Chromium interaction tests. Run: node --test tests/AdminTailoredPricingBrowserTest.cjs
// CHROME_BIN can select an installed Chrome/Edge/Chromium binary. No browser download or npm install is required.
const { test, before, after, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { spawn, execFileSync } = require('node:child_process');
const { createFixture, hash } = require('./fixtures/tailored-admin.cjs');

const root = path.resolve(__dirname, '..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function until(condition) {
    const deadline = Date.now() + 7000;
    while (Date.now() < deadline) { if (condition()) return; await delay(20); }
    throw new Error('Fixture server condition timed out');
}
let server, chrome, profile, cdp, origin, state;
let targetCounter = 0;
const pages = new Set();

class CDP {
    constructor(url) {
        this.ws = new WebSocket(url); this.next = 0; this.pending = new Map();
        this.ready = new Promise((resolve, reject) => { this.ws.addEventListener('open', resolve, { once: true }); this.ws.addEventListener('error', reject, { once: true }); });
        this.ws.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (!message.id) return;
            const pending = this.pending.get(message.id); if (!pending) return;
            this.pending.delete(message.id); clearTimeout(pending.timeout);
            if (message.error) pending.reject(new Error(JSON.stringify(message.error))); else pending.resolve(message.result);
        });
    }
    async call(method, params = {}, sessionId) {
        await this.ready;
        const id = ++this.next;
        const result = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => { this.pending.delete(id); reject(new Error('CDP timed out: ' + method)); }, 15000);
            this.pending.set(id, { resolve, reject, timeout });
        });
        this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        return result;
    }
}

async function openPage(language = 'en', route = '/tailored-pricing', token = 'test-admin', options = {}) {
    const { targetId } = await cdp.call('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.call('Target.attachToTarget', { targetId, flatten: true });
    await cdp.call('Runtime.enable', {}, sessionId);
    await cdp.call('Page.enable', {}, sessionId);
    const viewport = options.viewport || {width: 1440, height: 1000};
    await cdp.call('Emulation.setDeviceMetricsOverride', {...viewport, deviceScaleFactor: 1, mobile: viewport.width <= 768}, sessionId);
    const page = {
        targetId, sessionId,
        async evaluate(expression) {
            const result = await cdp.call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.text + ': ' + JSON.stringify(result.exceptionDetails.exception));
            return result.result.value;
        },
        async wait(expression, description = expression) {
            const deadline = Date.now() + 7000;
            while (Date.now() < deadline) { if (await this.evaluate(`Boolean(${expression})`)) return; await delay(30); }
            throw new Error('Browser condition timed out: ' + description);
        },
        async click(selector) { await this.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); },
        async selectOption(selector, value) {
            const list = await this.evaluate(`document.querySelector(${JSON.stringify(selector)}).getAttribute('aria-controls')`);
            await this.click(selector);
            await this.wait(`document.getElementById(${JSON.stringify(list)}) && document.querySelector(${JSON.stringify(selector)}).getAttribute('aria-expanded')==='true'`);
            await this.evaluate(`(()=>{const item=Array.from(document.getElementById(${JSON.stringify(list)}).querySelectorAll('[data-tailored-option]')).find(n=>n.dataset.tailoredOption===${JSON.stringify(value)});if(!item)throw new Error('Missing option '+${JSON.stringify(value)});item.click();})()`);
            await this.wait(`document.querySelector(${JSON.stringify(selector)}).getAttribute('aria-expanded')==='false'`);
        },
        async select(id, value) { await this.selectOption('[data-classification="' + id + '"]', value); },
        async quantity(key, value) { await this.evaluate(`(()=>{const input=document.querySelector('[data-quantity="${key}"]');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,${JSON.stringify(String(value))});input.dispatchEvent(new Event('input',{bubbles:true}));})()`); },
        async slide(key, value) { await this.evaluate(`(()=>{const input=document.querySelector('[data-slider="${key}"]');Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(input,${JSON.stringify(String(value))});input.dispatchEvent(new Event('input',{bubbles:true}));})()`); },
        async ref(id, period) { await this.click(`[data-reference="${id}:${period}"]`); },
        async preview() { const count = state.requests.length; await this.click('[data-action="preview"]'); await this.wait(`!document.querySelector('[data-action="preview"]').disabled`); assert.ok(state.requests.length > count, 'preview reached server'); },
        async close() { await cdp.call('Target.closeTarget', { targetId }); pages.delete(page); }
    };
    await cdp.call('Page.navigate', { url: `${origin}/secure-fixture?lang=${language}&token=${encodeURIComponent(token)}&id=${++targetCounter}&full=${options.fullBundle ? '1' : '0'}&bundle=${options.bundle || 'umi-fa.js'}#${route}` }, sessionId);
    pages.add(page);
    await page.wait('window.fixtureReady === true');
    if (route === '/tailored-pricing' && token) await page.wait('document.querySelector("#doctor-tailored-pricing") && !document.querySelector("#doctor-tailored-pricing").textContent.includes("Loading pricing settings") && !document.querySelector("#doctor-tailored-pricing").textContent.includes("در حال دریافت تنظیمات قیمت")');
    return page;
}

before(async () => {
    state = createFixture();
    server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/assets/admin/')) {
            const override = process.env.TAILORED_BUNDLE_DIR && ['/assets/admin/umi.js', '/assets/admin/umi-fa.js'].includes(url.pathname);
            const file = override ? path.resolve(process.env.TAILORED_BUNDLE_DIR, path.basename(url.pathname)) : path.resolve(root, 'public', '.' + url.pathname);
            if ((!override && !file.startsWith(path.resolve(root, 'public', 'assets', 'admin') + path.sep)) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.statusCode = 404; res.end(); return; }
            res.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript; charset=utf-8' : url.pathname.endsWith('.css') ? 'text/css; charset=utf-8' : 'application/octet-stream');
            res.end(fs.readFileSync(file)); return;
        }
        if (url.pathname.startsWith('/api/v1/fixture-admin/plan/custom/')) {
            let body = ''; for await (const part of req) body += part;
            const action = url.pathname.split('/').at(-1);
            const payload = body ? JSON.parse(body) : null;
            state.requests.push({ action, body: payload, authorization: req.headers.authorization });
            res.setHeader('Content-Type', 'application/json');
            if (state.mode === 'forbidden') { res.statusCode = 403; res.end(JSON.stringify({ message: 'forbidden' })); return; }
            if (state.mode === 'malformed') { res.end(JSON.stringify({ data: { capable: true, configuration: 'unsupported' } })); return; }
            if (state.mode === 'incapable') { res.end(JSON.stringify({ data: { capable: false, configuration: { enabled: false, classifications: [], references: [] }, candidates: [], validation: { valid: false, errors: [{ code: 'schema_unavailable' }] }, preview: { enabled: false } } })); return; }
            if (state.mode === 'hold-fetch' && action === 'fetch') { state.hold = () => res.end(JSON.stringify(state.envelope())); return; }
            if (state.mode === 'hold-preview' && action === 'preview') { state.hold = () => res.end(JSON.stringify(state.envelope(payload.configuration))); return; }
            if (action !== 'fetch' && (state.mode === 'conflict' || payload.settings_revision !== state.settings || payload.source_revision !== state.source)) {
                res.statusCode = 409; res.end(JSON.stringify({ code: 'custom_settings_stale' })); return;
            }
            const response = state.envelope(payload ? payload.configuration : state.configuration);
            if (action === 'save') {
                if (!response.data.validation.valid) { res.statusCode = 422; res.end(JSON.stringify({ code: 'custom_settings_invalid', ...response })); return; }
                state.configuration = payload.configuration; state.settings = hash(JSON.stringify(payload.configuration));
            }
            res.end(JSON.stringify(action === 'save' ? state.envelope() : response)); return;
        }
        if (url.pathname.startsWith('/api/')) {
            res.setHeader('Content-Type', 'application/json');
            const user = { email: 'fixture-admin@example.invalid', is_admin: 1, avatar_url: '', balance: 0, commission_balance: 0 };
            res.end(JSON.stringify({ data: /\/user\/(info|checkLogin)$/.test(url.pathname) ? user : [] })); return;
        }
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        const language = url.searchParams.get('lang') || 'en';
        const token = url.searchParams.get('token') || '';
        const bundle = url.searchParams.get('bundle') === 'umi.js' ? 'umi.js' : 'umi-fa.js';
        res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/admin/components.chunk.css"><link rel="stylesheet" href="/assets/admin/umi.css"><link rel="stylesheet" href="/assets/admin/tailored-pricing.css"></head><body><div id="root"></div><script>localStorage.setItem('authorization',${JSON.stringify(token)});localStorage.setItem('umi_locale',${JSON.stringify(language === 'fa' ? 'fa-IR' : 'en-US')});window.routerBase='/';window.settings={secure_path:'fixture-admin',title:'Doctor Mobile',theme:{sidebar:'light',header:'light',color:'blue'},version:'test',background_url:'',logo:''};window.confirm=()=>true;window.fixtureReady=true;</script><script src="/assets/admin/vendors.async.js"></script><script src="/assets/admin/components.async.js"></script><script src="/assets/admin/${bundle}"></script></body></html>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const candidates = [process.env.CHROME_BIN, process.env.CHROME_PATH, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean);
    const executable = candidates.find(file => fs.existsSync(file));
    assert.ok(executable, 'An installed Chromium browser is required; set CHROME_BIN');
    profile = fs.mkdtempSync(path.join(os.tmpdir(), 'doctor-tailored-browser-'));
    chrome = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--disable-extensions', '--disable-sync', '--no-sandbox', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
    const activePort = path.join(profile, 'DevToolsActivePort');
    const deadline = Date.now() + 15000;
    while (!fs.existsSync(activePort) && Date.now() < deadline) await delay(50);
    assert.ok(fs.existsSync(activePort), 'Chromium remote debugging started');
    const [port, endpoint] = fs.readFileSync(activePort, 'utf8').trim().split(/\r?\n/);
    cdp = new CDP(`ws://127.0.0.1:${port}${endpoint}`); await cdp.ready;
});

beforeEach(() => { state = createFixture(); });
afterEach(async () => { for (const page of Array.from(pages)) await page.close(); });
after(async () => {
    if (cdp) { try { await cdp.call('Browser.close'); } catch (_) {} cdp.ws.close(); }
    if (chrome) chrome.kill();
    if (server) await new Promise(resolve => server.close(resolve));
    if (profile) {
        const resolved = path.resolve(profile), temp = path.resolve(os.tmpdir());
        assert.equal(path.dirname(resolved), temp); assert.ok(path.basename(resolved).startsWith('doctor-tailored-browser-'));
        for (let tries = 0; tries < 20; tries++) { try { fs.rmSync(resolved, { recursive: true, force: true }); break; } catch (_) { await delay(100); } }
    }
});

test('bundle patch is idempotent, ordered after subscriptions, and preserves custom bytes', () => {
    const python = process.env.PYTHON_BIN || (process.platform === 'win32' ? 'python' : 'python3');
    const script = path.join(root, 'scripts', 'patch_admin_tailored_pricing.py');
    const code = [
        'import runpy,sys,pathlib',
        'm=runpy.run_path(sys.argv[1])',
        'patch=m["patch_bytes"]; remove=m["remove_owned"]; span=m["object_span"]',
        'for name in ("umi.js","umi-fa.js"):',
        ' raw=pathlib.Path(sys.argv[2],"public","assets","admin",name).read_bytes()',
        ' assert patch(raw)==raw, "tracked bundle must match maintained patcher"',
        ' original=remove(raw.decode("utf-8")).encode("utf-8")',
        ' custom=b"/* operator-specific prefix */\\r\\n"+original+b"\\n;window.operatorCustomPreserved=true;\\r\\n"',
        ' patched=patch(custom)',
        ' assert patch(patched)==patched, "patch must be idempotent"',
        ' assert remove(patched.decode("utf-8")).encode("utf-8")==custom, "all unowned bytes must be preserved"',
        ' source=patched.decode("utf-8")',
        ' assert source[span(source,"href")[1]:].startswith(", /*__DTP_MENU_START__*/"), "menu must follow subscription entry immediately"',
        ' try: patch(original+b"\\nvar duplicate={href: \\\"/plan\\\"};")',
        ' except m["PatchError"]: pass',
        ' else: raise AssertionError("ambiguous anchors must fail closed")',
        'print("both bundles: idempotence, exact preservation, menu order, ambiguous-anchor rejection PASS")'
    ].join('\n');
    const output = execFileSync(python, ['-c', code, script, root], { encoding: 'utf8', windowsHide: true });
    assert.match(output, /PASS/);
});

test('ordinary subscription route is unchanged and makes no tailored API request', async () => {
    const page = await openPage('en', '/plan'); await delay(600);
    await page.wait('document.querySelector("#main-container .ant-table")');
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-pricing")'), false);
    assert.equal(state.requests.length, 0);
});

for (const [bundle, language, label] of [['umi-fa.js', 'fa', 'دست‌دوز'], ['umi.js', 'en', 'Tailored']]) {
    test(`${bundle}: actual SPA sidebar opens the new tab on the first click and preserves subscriptions`, async () => {
        const page = await openPage(language, '/plan', 'test-admin', { fullBundle: true, bundle });
        await page.wait('document.querySelector("#sidebar .nav-main-link-name") && document.querySelector("#main-container .ant-table")', 'actual admin sidebar and plan table');
        const labels = await page.evaluate('Array.from(document.querySelectorAll("#sidebar .nav-main-link-name")).map(n=>n.textContent)');
        const planIndex = labels.findIndex(value => value.includes('مدیریت اشتراک'));
        assert.ok(planIndex >= 0, 'existing subscription entry is present'); assert.equal(labels[planIndex + 1], label);
        assert.equal(state.requests.length, 0, 'new editor never prefetches from subscription tab');
        await page.evaluate(`Array.from(document.querySelectorAll('#sidebar .nav-main-link-name')).find(n=>n.textContent===${JSON.stringify(label)}).closest('a').click()`);
        await page.wait('document.querySelector("[data-classification]")', 'new editor appears on first click');
        assert.equal(await page.evaluate('location.hash'), '#/tailored-pricing');
        assert.equal(await page.evaluate('Array.from(document.querySelectorAll("#main-container .ant-table")).every(table=>!!table.closest("[data-tailored-native]"))'), true, 'the route renders its own native pricing table');
        assert.equal(state.requests.length, 1);
        await delay(550); assert.equal(state.requests.length, 1, 'React render does not trigger repeated fetches');
        if (process.env.TAILORED_SCREENSHOT_DIR) {
            await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.ref(1, 'quarter_price');
            await page.select(2, 'finite_total'); await page.ref(2, 'month_price'); await page.select(3, 'unlimited_fup'); await page.preview();
            fs.mkdirSync(process.env.TAILORED_SCREENSHOT_DIR, { recursive: true });
            await page.evaluate('window.scrollTo(0,0)');
            const metrics = await cdp.call('Page.getLayoutMetrics', {}, page.sessionId);
            const screenshot = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: 1440, height: metrics.cssContentSize.height, scale: 1 } }, page.sessionId);
            fs.writeFileSync(path.join(process.env.TAILORED_SCREENSHOT_DIR, `admin-tailored-tab-${language}.png`), Buffer.from(screenshot.data, 'base64'));
        }
        const finalRequests = state.requests.length;
        await page.evaluate(`Array.from(document.querySelectorAll('#sidebar .nav-main-link-name')).find(n=>n.textContent.includes('مدیریت اشتراک')).closest('a').click()`);
        await page.wait('document.querySelector("#main-container .ant-table") && !document.querySelector("#doctor-tailored-pricing")');
        assert.equal(await page.evaluate('location.hash'), '#/plan');
        assert.equal(state.requests.length, finalRequests, 'returning to subscription management does not load tailored settings');
    });
}

test('the Umi route owns native React controls and escapes unsafe tariff names', async () => {
    const page = await openPage();
    assert.equal(await page.evaluate('document.querySelectorAll("#doctor-tailored-pricing").length'), 1);
    assert.equal(await page.evaluate('!!document.querySelector("#main-container [data-tailored-native].block.block-rounded")'), true);
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-host") || !!window.__doctorTailoredAdmin'), false);
    assert.equal(await page.evaluate('document.querySelectorAll("[data-tailored-native] select,[data-tailored-native] .dtp-button").length'), 0);
    assert.equal(await page.evaluate('!!document.querySelector("[data-classification]").closest(".ant-select") && document.querySelector("[data-enabled]").classList.contains("ant-switch") && document.querySelector("[data-action=save]").classList.contains("ant-btn")'), true);
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-pricing img") || !!window.pwned'), false);
    assert.equal(state.requests.length, 1); assert.equal(state.requests[0].authorization, 'test-admin');
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled'), true);
    await page.close();
});

test('explicit tariff-period selection excludes unlimited and monthly-reset tariffs; preview never saves', async () => {
    const page = await openPage();
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:month_price\\"]").disabled'), true);
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price');
    await page.select(3, 'unlimited_fup'); await page.select(4, 'finite_total');
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"3:month_price\\"]").disabled'), true);
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"4:month_price\\"]").disabled'), true);
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:quarter_price\\"]").getAttribute("aria-checked")==="true"'), false);
    await page.preview();
    assert.deepEqual(state.requests.at(-1).body.configuration.references, [{ plan_id: 1, period: 'month_price' }]);
    assert.equal(state.requests.filter(r => r.action === 'save').length, 0); assert.equal(state.configuration.enabled, false);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-chart").length'), 2);
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled'), false);
    assert.match(await page.evaluate('document.querySelector(".dtp-model-state").textContent'), /Draft/);
    await page.close();
});

test('save requires a preview of the exact draft and uses optimistic source/settings revisions', async () => {
    const page = await openPage();
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    await page.click('[data-enabled]');
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled'), true);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-chart").length'), 0);
    await page.preview(); await page.click('[data-action="save"]');
    await page.wait('document.querySelector(".dtp-success")');
    assert.equal(state.configuration.enabled, true);
    const saves = state.requests.filter(r => r.action === 'save'); assert.equal(saves.length, 1);
    assert.equal(saves[0].body.source_revision, hash('source-0')); assert.equal(saves[0].body.settings_revision, hash('settings-0'));
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled'), true);
    assert.equal(await page.evaluate('document.querySelector(".dtp-model-state").textContent'), 'Saved model');
    await page.close();
});

test('interactive volume/time curves use server base, display comma Toman and correct reference slices', async () => {
    const page = await openPage();
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    assert.match(await page.evaluate('document.querySelector(".dtp-price").textContent'), /\$1\.4900.*298,000/);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-reference").length'), 2);
    await page.slide('volume', 200);
    assert.match(await page.evaluate('document.querySelector(".dtp-price").textContent'), /200 GB.*\$3\.0625.*612,000/);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-reference").length'), 1);
    await page.selectOption('[data-currency]', 'toman');
    assert.match(await page.evaluate('document.querySelector(".dtp-chart").textContent'), /Toman/);
    assert.ok(await page.evaluate('Array.from(document.querySelectorAll(".dtp-chart text")).every(n=>n.getBBox().width>0 && getComputedStyle(n).fontFamily.includes("Tahoma"))'));
    assert.equal(state.requests.length, 2, 'local chart exploration makes no new API call');
    await page.close();
});

test('incompatible access cohorts show a concrete error and cannot be saved', async () => {
    const page = await openPage();
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price');
    await page.select(5, 'finite_total'); await page.ref(5, 'month_price'); await page.preview();
    assert.match(await page.evaluate('document.querySelector(".dtp-errors").textContent'), /identical access group/);
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled'), true);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-chart").length'), 0);
    await page.close();
});

test('conflicts retain the draft and block preview/save until explicit reload', async () => {
    const page = await openPage();
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    state.mode = 'conflict'; await page.click('[data-action="save"]');
    await page.wait('document.querySelector(".dtp-error") && document.querySelector(".dtp-error").textContent.includes("changed elsewhere")');
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:month_price\\"]").getAttribute("aria-checked")==="true"'), true);
    assert.equal(await page.evaluate('document.querySelector("[data-action=save]").disabled && document.querySelector("[data-action=preview]").disabled'), true);
    await delay(650); assert.equal(state.requests.filter(r => r.action === 'save').length, 1, 'no automatic mutation retry');
    await page.click('.dtp-actions button:last-of-type');
    assert.match(await page.evaluate('document.querySelector(".dtp-error").textContent'), /changed elsewhere/);
    assert.equal(await page.evaluate('document.querySelector("[data-action=preview]").disabled'), true);
    state.mode = ''; await page.click('.dtp-toolbar button');
    await page.wait('!document.querySelector(".dtp-error")');
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:month_price\\"]").getAttribute("aria-checked")==="true"'), false);
    await page.close();
});

test('native controlled edits preserve the draft across page updates without extra fetches', async () => {
    const page = await openPage(); await page.select(1, 'finite_total'); await page.ref(1, 'month_price');
    await page.click('[data-enabled]');
    await page.select(2, 'finite_total');
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:month_price\\"]").getAttribute("aria-checked")==="true"'), true);
    assert.equal(state.requests.length, 1);
    await page.close();
});

test('route transitions discard stale in-flight previews and remount only on the exact plan route', async () => {
    const page = await openPage(); await page.select(1, 'finite_total'); await page.ref(1, 'month_price');
    state.mode = 'hold-preview'; await page.click('[data-action="preview"]');
    await page.wait('document.querySelector("[data-action=preview]").disabled');
    await until(() => typeof state.hold === 'function');
    await page.evaluate('location.hash="/tailored-pricing-other"'); await page.wait('!document.querySelector("#doctor-tailored-pricing")');
    state.hold(); state.mode = ''; await delay(100);
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-pricing")'), false);
    await page.evaluate('location.hash="/tailored-pricing"'); await page.wait('document.querySelector("[data-classification]")');
    assert.equal(await page.evaluate('document.querySelector("[data-reference=\\"1:month_price\\"]").getAttribute("aria-checked")==="true"'), false);
    assert.equal(state.requests.filter(r => r.action === 'save').length, 0);
    await page.close();
});

test('changed authorization never submits the previous admin draft and drops the old response', async () => {
    const page = await openPage(); await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    await page.evaluate('localStorage.setItem("authorization","new-admin");document.querySelector("[data-action=save]").click()');
    await page.wait('document.querySelector("[data-classification] .ant-select-selection-selected-value") && document.querySelector("[data-classification] .ant-select-selection-selected-value").textContent.includes("Unclassified")');
    assert.equal(state.requests.filter(r => r.action === 'save').length, 0);
    assert.equal(state.requests.at(-1).authorization, 'new-admin');
    state.mode = 'hold-preview'; await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.click('[data-action="preview"]');
    await page.wait('document.querySelector("[data-action=preview]").disabled');
    await until(() => typeof state.hold === 'function');
    await page.evaluate('localStorage.removeItem("authorization")'); await page.wait('!document.querySelector("#doctor-tailored-pricing")');
    state.hold(); await delay(100);
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-pricing")'), false);
    await page.close();
});

test('403 loads no configuration and causes no automatic retry', async () => {
    state.mode = 'forbidden'; const page = await openPage(); await page.wait('document.querySelector(".dtp-error")');
    assert.match(await page.evaluate('document.querySelector(".dtp-error").textContent'), /Admin access is required/);
    assert.equal(await page.evaluate('document.querySelectorAll("[data-classification],.dtp-chart").length'), 0);
    await delay(650); assert.equal(state.requests.length, 1);
    await page.close();
});

test('malformed pricing response fails closed without retry or mutation controls', async () => {
    state.mode = 'malformed'; const page = await openPage();
    assert.match(await page.evaluate('document.querySelector(".dtp-error").textContent'), /unsupported pricing response/);
    assert.equal(await page.evaluate('document.querySelectorAll("[data-action=save],.dtp-chart").length'), 0);
    await delay(600); assert.equal(state.requests.length, 1);
});

test('missing admin authorization causes no pricing request even on the dedicated route', async () => {
    const page = await openPage('en', '/tailored-pricing', ''); await delay(600);
    assert.equal(await page.evaluate('!!document.querySelector("#doctor-tailored-pricing")'), false);
    assert.equal(state.requests.length, 0);
});

test('schema-not-ready response shows the concrete capability warning and cannot preview or save', async () => {
    state.mode = 'incapable'; const page = await openPage();
    assert.match(await page.evaluate('document.querySelector("#doctor-tailored-pricing").textContent'), /database capability is unavailable/);
    assert.equal(await page.evaluate('document.querySelector("[data-action=preview]").disabled && document.querySelector("[data-action=save]").disabled'), true);
    assert.equal(state.requests.length, 1);
});

test('rounded-zero Toman curve remains finite for a valid low-price source and one-GB slice', async () => {
    state.exchangeRate = 60000;
    state.candidates = [{ plan_id: 1, plan_name: 'Finite low-price tariff', volume_gb: 1024, quota_type: 'unknown', cohort: { group_id: 1, device_limit: 3, speed_limit: 100, reset_traffic_method: 2 }, prices: { year_price: 0.10 } }];
    const page = await openPage(); await page.select(1, 'finite_total'); await page.ref(1, 'year_price'); await page.preview();
    await page.quantity('volume', 1); await page.selectOption('[data-currency]', 'toman');
    assert.equal(await page.evaluate('Array.from(document.querySelectorAll(".dtp-chart *")).some(n=>Array.from(n.attributes).some(a=>/NaN|Infinity/.test(a.value)))'), false);
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-line").length'), 2);
});

test('admin can remove a deleted selected tariff and save the repaired disabled configuration', async () => {
    state.configuration.references = [{ plan_id: 99, period: 'month_price' }];
    const page = await openPage();
    assert.match(await page.evaluate('document.querySelector(".dtp-errors").textContent'), /no longer exists/);
    await page.click('[data-remove-reference="99:month_price"]'); await page.preview();
    await page.click('[data-action="save"]'); await page.wait('document.querySelector(".dtp-success")');
    assert.deepEqual(state.configuration.references, []);
});

test('Persian UI uses readable SVG text, Persian integer inputs and saved/draft chart labeling', async () => {
    state.candidates[0].plan_name = '۳۵ گیگ / Standard';
    const page = await openPage('fa'); await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.ref(1, 'quarter_price');
    await page.select(2, 'finite_total'); await page.ref(2, 'month_price'); await page.select(3, 'unlimited_fup'); await page.preview();
    assert.equal(await page.evaluate('document.querySelector("#doctor-tailored-pricing").dir'), 'rtl');
    assert.match(await page.evaluate('document.querySelector("#doctor-tailored-pricing h2").textContent'), /تعرفه دست‌دوز/);
    await page.quantity('volume', '۲۰۰');
    assert.match(await page.evaluate('document.querySelector(".dtp-price").textContent'), /200 GB.*612,000/);
    assert.ok(await page.evaluate('Array.from(document.querySelectorAll(".dtp-chart text")).every(n=>n.getBBox().width>0)'));
    if (process.env.TAILORED_SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.TAILORED_SCREENSHOT_DIR, { recursive: true });
        await page.evaluate('window.scrollTo(0,0)');
        const metrics = await cdp.call('Page.getLayoutMetrics', {}, page.sessionId);
        const screenshot = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: 1440, height: metrics.cssContentSize.height, scale: 1 } }, page.sessionId);
        fs.writeFileSync(path.join(process.env.TAILORED_SCREENSHOT_DIR, 'admin-tailored-fa.png'), Buffer.from(screenshot.data, 'base64'));
    }
    await page.close();
});

test('English full editor can be captured as an actual browser preview', async () => {
    state.candidates[0].plan_name = '۳۵ گیگ / Standard';
    const page = await openPage('en'); await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.ref(1, 'quarter_price');
    await page.select(2, 'finite_total'); await page.ref(2, 'month_price'); await page.select(3, 'unlimited_fup'); await page.preview();
    assert.equal(await page.evaluate('document.querySelectorAll(".dtp-fit tbody tr").length'), 3);
    if (process.env.TAILORED_SCREENSHOT_DIR) {
        fs.mkdirSync(process.env.TAILORED_SCREENSHOT_DIR, { recursive: true });
        await page.evaluate('window.scrollTo(0,0)');
        const metrics = await cdp.call('Page.getLayoutMetrics', {}, page.sessionId);
        const screenshot = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: 1440, height: metrics.cssContentSize.height, scale: 1 } }, page.sessionId);
        fs.writeFileSync(path.join(process.env.TAILORED_SCREENSHOT_DIR, 'admin-tailored-en.png'), Buffer.from(screenshot.data, 'base64'));
    }
    await page.close();
});

test('invalid and fractional quantities keep the last valid price and show native input guidance', async () => {
    const page = await openPage('fa'); await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    const price = await page.evaluate('document.querySelector(".dtp-price").textContent');
    for (const invalid of ['۰', '۱۰۲۵', '2.5', '']) {
        await page.quantity('volume', invalid);
        assert.equal(await page.evaluate('document.querySelector("[data-quantity=volume]").getAttribute("aria-invalid")'), 'true');
        assert.equal(await page.evaluate('document.querySelector(".dtp-price").textContent'), price);
        assert.match(await page.evaluate('document.querySelector(".dtp-controls [role=alert]").textContent'), /۱۰۲۴/);
    }
    await page.quantity('volume', '٢٠٠');
    assert.equal(await page.evaluate('document.querySelector("[data-quantity=volume]").getAttribute("aria-invalid")'), 'false');
    assert.match(await page.evaluate('document.querySelector(".dtp-price").textContent'), /200 GB.*612,000/);
    assert.equal(state.requests.length, 2, 'quantity exploration never changes the saved prices');
});

test('Persian tailored page fits mobile, with native scrollable tables and guided quantity controls', async () => {
    const page = await openPage('fa', '/tailored-pricing', 'test-admin', {viewport: {width: 390, height: 844}});
    await page.select(1, 'finite_total'); await page.ref(1, 'month_price'); await page.preview();
    const geometry = await page.evaluate('(()=>{const root=document.querySelector("[data-tailored-native]"),box=root.getBoundingClientRect();return {width:innerWidth,scroll:document.documentElement.scrollWidth,left:box.left,right:box.right,table:!!root.querySelector(".ant-table-scroll"),figures:Array.from(root.querySelectorAll(".dtp-chart")).map(e=>e.getBoundingClientRect().width),inputs:Array.from(root.querySelectorAll("[data-quantity]")).map(e=>({native:e.classList.contains("ant-input"),guide:!!document.getElementById(e.getAttribute("aria-describedby"))}))};})()');
    assert.ok(geometry.scroll <= geometry.width + 2, JSON.stringify(geometry));
    assert.ok(geometry.left >= -2 && geometry.right <= geometry.width + 2, JSON.stringify(geometry));
    assert.ok(geometry.table && geometry.figures.length === 2 && geometry.figures.every(width => width > 100 && width < 390), JSON.stringify(geometry));
    assert.ok(geometry.inputs.length === 2 && geometry.inputs.every(input => input.native && input.guide));
    if (process.env.TAILORED_SCREENSHOT_DIR) {
        await page.evaluate('document.querySelector(".dtp-model").scrollIntoView({block:"start"})');
        const screenshot = await cdp.call('Page.captureScreenshot', {format:'png', captureBeyondViewport:false}, page.sessionId);
        fs.writeFileSync(path.join(process.env.TAILORED_SCREENSHOT_DIR, 'admin-tailored-mobile-fa.png'), Buffer.from(screenshot.data, 'base64'));
    }
});
