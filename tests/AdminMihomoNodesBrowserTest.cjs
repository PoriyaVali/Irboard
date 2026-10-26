'use strict';
// Real Chromium checks of both prebuilt admin bundles; no npm dependencies.
const { test, before, after, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, chrome, profile, cdp, origin, state;
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

async function openPage(lang = 'en', bundle = 'umi.js', auth = 'fixture-admin-token') {
    const { targetId } = await cdp.call('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.call('Target.attachToTarget', { targetId, flatten: true });
    const page = {
        async evaluate(expression) {
            const result = await cdp.call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        },
        async wait(expression) {
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) { if (await this.evaluate(`Boolean(${expression})`)) return; await delay(30); }
            const diagnostic = await this.evaluate('({url:location.href,text:document.body.innerText.slice(0,900),containers:Array.from(document.querySelectorAll("main,#main-container,[class*=layout-content]")).map(e=>({tag:e.tagName,id:e.id,cls:e.className})),tables:document.querySelectorAll(".ant-table").length})');
            throw new Error('Browser condition timed out: ' + expression + ' ' + JSON.stringify(diagnostic));
        },
        async click(selector) { await this.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); },
        async set(name, value) {
            await this.evaluate(`(()=>{var e=document.querySelector('#irboard-mihomo-nodes [name="${name}"]'); e.value=${JSON.stringify(value)};e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
        },
        async close() { await cdp.call('Target.closeTarget', { targetId }); pages.delete(page); }
    };
    pages.add(page);
    await cdp.call('Page.enable', {}, sessionId);
    await cdp.call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 950, deviceScaleFactor: 1, mobile: false }, sessionId);
    await cdp.call('Page.navigate', { url: `${origin}/secure-fixture?lang=${lang}&bundle=${bundle}&token=${encodeURIComponent(auth)}#/server/manage` }, sessionId);
    await page.wait('window.fixtureReady');
    if (auth) await page.wait('document.querySelector("#irboard-mihomo-nodes") && document.querySelector("#irboard-mihomo-nodes").getAttribute("aria-busy")==="false"');
    return page;
}
function freshState() {
    const protocols = {};
    for (const type of ['ssr', 'socks5', 'http', 'snell', 'wireguard', 'shadowquic', 'gost-relay', 'ssh', 'mieru', 'sudoku', 'masque', 'openvpn', 'tailscale', 'zerotier', 'easytier']) {
        protocols[type] = { label: type, example: { server: '192.0.2.10', port: 443, password: '', udp: true } };
    }
    return { protocols, nodes: [], requests: [], fail: false, forbidden: false };
}
before(async () => {
    state = freshState();
    server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/assets/admin/')) {
            const file = path.resolve(root, '.' + url.pathname.replace('/assets/', '/public/assets/'));
            if (!file.startsWith(path.join(root, 'public', 'assets') + path.sep) || !fs.existsSync(file)) { res.statusCode = 404; res.end(); return; }
            res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript'); res.end(fs.readFileSync(file)); return;
        }
        if (url.pathname.startsWith('/api/')) {
            res.setHeader('Content-Type', 'application/json');
            if (url.pathname.includes('/server/mihomo/')) {
                let raw = ''; for await (const part of req) raw += part;
                const action = url.pathname.split('/').pop(), payload = raw ? JSON.parse(raw) : undefined;
                state.requests.push({ action, payload, auth: req.headers.authorization });
                if (state.forbidden || req.headers.authorization !== 'fixture-admin-token') { res.statusCode = 403; res.end(JSON.stringify({ message: 'Admin access required' })); return; }
                if (action === 'fetch') { res.end(JSON.stringify({ data: { version: '1.19.33', protocols: state.protocols, nodes: state.nodes } })); return; }
                if (state.fail) { res.statusCode = 422; res.end(JSON.stringify({ message: 'Invalid settings', errors: { 'outbound_settings.password': ['Required credential'] } })); return; }
                const index = payload.id ? state.nodes.findIndex(row => row.id === payload.id) : -1;
                if (action === 'save') {
                    const row = { ...payload, id: payload.id || state.nodes.length + 1 };
                    if (index >= 0) state.nodes[index] = row; else state.nodes.push(row);
                }
                if (action === 'update') state.nodes[index].show = payload.show;
                if (action === 'drop') state.nodes.splice(index, 1);
                if (action === 'copy') state.nodes.push({ ...state.nodes[index], id: state.nodes.length + 1, name: state.nodes[index].name + ' (copy)', show: false });
                res.end(JSON.stringify({ data: true })); return;
            }
            const user = { email: 'fixture@example.invalid', is_admin: 1, avatar_url: '', balance: 0, commission_balance: 0 };
            res.end(JSON.stringify({ data: /\/user\/(info|checkLogin)$/.test(url.pathname) ? user :
                url.pathname.endsWith('/server/group/fetch') ? [{ id: 1, name: 'Group <b>one</b>' }] : [] })); return;
        }
        const bundle = url.searchParams.get('bundle') === 'umi-fa.js' ? 'umi-fa.js' : 'umi.js';
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/admin/components.chunk.css"><link rel="stylesheet" href="/assets/admin/umi.css"><link rel="stylesheet" href="/assets/admin/mihomo-nodes.css"></head><body><div id="root"></div><script>localStorage.setItem('authorization',${JSON.stringify(url.searchParams.get('token'))});localStorage.setItem('umi_locale',${JSON.stringify(url.searchParams.get('lang') === 'fa' ? 'fa-IR' : 'en-US')});window.routerBase='/';window.settings={secure_path:'fixture-admin',title:'Irboard fixture',theme:{sidebar:'light',header:'light',color:'blue'},version:'test',background_url:'',logo:''};window.confirm=()=>true;window.fixtureReady=true;</script><script src="/assets/admin/vendors.async.js"></script><script src="/assets/admin/components.async.js"></script><script src="/assets/admin/${bundle}"></script><script src="/assets/admin/mihomo-nodes.js"></script></body></html>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const executable = [process.env.CHROME_BIN, '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean).find(file => fs.existsSync(file));
    assert.ok(executable, 'Chromium is required; set CHROME_BIN');
    profile = fs.mkdtempSync(path.join(os.tmpdir(), 'irboard-mihomo-browser-'));
    chrome = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--disable-extensions', '--disable-sync', '--no-sandbox', '--user-data-dir=' + profile, 'about:blank'], { stdio: 'ignore' });
    const activePort = path.join(profile, 'DevToolsActivePort'), deadline = Date.now() + 15000;
    while (!fs.existsSync(activePort) && Date.now() < deadline) await delay(50);
    assert.ok(fs.existsSync(activePort), 'Chromium debugging started');
    const [port, endpoint] = fs.readFileSync(activePort, 'utf8').trim().split(/\r?\n/);
    cdp = new CDP(`ws://127.0.0.1:${port}${endpoint}`); await cdp.ready;
});
beforeEach(() => { state = freshState(); });
afterEach(async () => { for (const page of Array.from(pages)) await page.close(); });
after(async () => {
    if (cdp) { try { await cdp.call('Browser.close'); } catch (_) {} cdp.ws.close(); }
    if (chrome) chrome.kill();
    if (server) await new Promise(resolve => server.close(resolve));
    if (profile) fs.rmSync(profile, { recursive: true, force: true });
});
async function draft(page, name = 'Fictional node') {
    await page.click('#irboard-mihomo-nodes [data-action="add"]');
    await page.set('name', name);
    await page.evaluate(`(()=>{var s=document.querySelector('#irboard-mihomo-nodes [name="group_id"]');s.options[0].selected=true;s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
}
async function idle(page) { await page.wait('document.querySelector("#irboard-mihomo-nodes").getAttribute("aria-busy")==="false"'); }

test('both real bundles mount the editor beside native nodes in English and Persian', async () => {
    for (const [lang, bundle] of [['en', 'umi.js'], ['fa', 'umi-fa.js']]) {
        const page = await openPage(lang, bundle);
        assert.equal(await page.evaluate('document.querySelectorAll("#irboard-mihomo-nodes").length'), 1);
        assert.equal(await page.evaluate('document.querySelector("#irboard-mihomo-nodes").dir'), lang === 'fa' ? 'rtl' : 'ltr');
        assert.equal(await page.evaluate('!!document.querySelector("#main-container .ant-table")'), true);
        await page.click('#irboard-mihomo-nodes [data-action="add"]');
        assert.equal(await page.evaluate('document.querySelector("#irboard-mihomo-nodes [name=protocol]").options.length'), 15);
        assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes b")'), false);
        await page.close();
    }
});

test('create, edit, hide, copy hidden and delete preserve typed nested JSON', async () => {
    const page = await openPage();
    await draft(page, '<img src=x onerror="window.pwned=1">');
    const settings = { server: '192.0.2.10', port: 443, password: ' secret\n ', udp: false, 'padding-min': 0, obfs: { hosts: ['a', 'b'], tls: false } };
    await page.set('outbound_settings', JSON.stringify(settings, null, 2));
    await page.click('#irboard-mihomo-nodes [data-action="save"]'); await idle(page);
    assert.deepEqual(state.nodes[0].outbound_settings, settings);
    assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes img") || !!window.pwned'), false);
    await page.click('#irboard-mihomo-nodes [data-action="edit"]');
    assert.deepEqual(JSON.parse(await page.evaluate('document.querySelector("#irboard-mihomo-nodes textarea").value')), settings);
    await page.set('sort', '8'); await page.click('#irboard-mihomo-nodes [data-action="save"]'); await idle(page);
    assert.equal(state.nodes[0].sort, 8);
    await page.click('#irboard-mihomo-nodes [data-action="toggle"]'); await idle(page);
    assert.equal(state.nodes[0].show, true);
    await page.click('#irboard-mihomo-nodes [data-action="copy"]'); await idle(page);
    assert.equal(state.nodes[1].show, false);
    await page.click('#irboard-mihomo-nodes [data-action="drop"]'); await idle(page);
    assert.equal(state.nodes.length, 1);
});

test('invalid JSON and server validation failures retain the unsaved draft', async () => {
    const page = await openPage(); await draft(page);
    await page.set('outbound_settings', '{broken');
    await page.click('#irboard-mihomo-nodes [data-action="save"]');
    assert.equal(state.requests.filter(r => r.action === 'save').length, 0);
    assert.equal(await page.evaluate('document.querySelector("#irboard-mihomo-nodes textarea").value'), '{broken');
    state.fail = true;
    const json = '{"server":"192.0.2.1","port":443,"password":"retained"}';
    await page.set('outbound_settings', json);
    await page.click('#irboard-mihomo-nodes [data-action="save"]'); await idle(page);
    assert.equal(await page.evaluate('document.querySelector("#irboard-mihomo-nodes textarea").value'), json);
    assert.match(await page.evaluate('document.querySelector("#irboard-mihomo-nodes [role=alert]").textContent'), /Required credential/);
});

test('leaving the route or changing account clears profile credentials', async () => {
    const page = await openPage(); await draft(page); await page.set('outbound_settings', '{"password":"private-draft"}');
    await page.evaluate('location.hash="/plan"'); await page.wait('!document.querySelector("#irboard-mihomo-nodes")');
    await page.evaluate('location.hash="/server/manage"'); await page.wait('document.querySelector("#irboard-mihomo-nodes")'); await idle(page);
    assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes textarea")'), false);
    await draft(page); await page.set('outbound_settings', '{"password":"private-draft"}');
    await page.evaluate('localStorage.setItem("authorization","other-account")');
    await page.wait('document.querySelector("#irboard-mihomo-nodes [role=alert]")');
    assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes textarea")'), false);
    assert.equal(await page.evaluate('document.querySelector("#irboard-mihomo-nodes").textContent.includes("private-draft")'), false);
});

test('logged out pages never fetch external profiles', async () => {
    const page = await openPage('en', 'umi.js', ''); await delay(650);
    assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes")'), false);
    assert.equal(state.requests.length, 0);
});
