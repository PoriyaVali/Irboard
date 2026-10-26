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

const types = ['ssr', 'socks5', 'http', 'snell', 'wireguard', 'shadowquic', 'gost-relay', 'ssh', 'mieru', 'sudoku', 'masque', 'openvpn', 'tailscale', 'zerotier', 'easytier'];
const formSelector = '[data-mihomo-form]';
async function openPage(lang = 'en', bundle = 'umi.js', auth = 'fixture-admin-token') {
    const { targetId } = await cdp.call('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.call('Target.attachToTarget', { targetId, flatten: true });
    const page = {
        sessionId,
        async evaluate(expression) {
            const result = await cdp.call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
            if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        },
        async wait(expression) {
            const deadline = Date.now() + 10000;
            while (Date.now() < deadline) { if (await this.evaluate(`Boolean(${expression})`)) return; await delay(30); }
            const diagnostic = await this.evaluate('({url:location.href,text:document.body.innerText.slice(-1500),forms:document.querySelectorAll("[data-mihomo-form]").length,tables:document.querySelectorAll(".ant-table").length})');
            throw new Error('Browser condition timed out: ' + expression + ' ' + JSON.stringify(diagnostic));
        },
        async click(selector) { await this.evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); },
        async set(name, value) {
            const selector = formSelector + ' [name="' + name + '"]';
            await this.evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw new Error('Missing field '+${JSON.stringify(name)});const proto=e.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:e.tagName==='SELECT'?HTMLSelectElement.prototype:HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value').set.call(e,${JSON.stringify(String(value))});e.dispatchEvent(new Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}));})()`);
        },
        async screenshot(filename) {
            const result = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
            fs.writeFileSync(filename, Buffer.from(result.data, 'base64'));
        },
        async close() { await cdp.call('Target.closeTarget', { targetId }); pages.delete(page); }
    };
    pages.add(page);
    await cdp.call('Page.enable', {}, sessionId);
    await cdp.call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false }, sessionId);
    await cdp.call('Page.navigate', { url: `${origin}/secure-fixture?lang=${lang}&bundle=${bundle}&token=${encodeURIComponent(auth)}#/server/manage` }, sessionId);
    await page.wait('window.fixtureReady && document.querySelector("#main-container .ant-table")');
    if (state.nodes.length) await page.wait('document.querySelectorAll("#main-container tr.ant-table-row").length>=' + Math.min(10, state.nodes.length));
    return page;
}
function freshState() {
    const endpoint = { server: '192.0.2.10', port: 443 };
    const examples = {
        ssr: { ...endpoint, cipher: 'chacha20-ietf', password: '', protocol: 'auth_sha1_v4', obfs: 'tls1.2_ticket_auth', udp: true },
        socks5: { ...endpoint, username: '', password: '', udp: true },
        http: { ...endpoint, username: '', password: '', tls: true },
        snell: { ...endpoint, psk: '', version: 4, udp: true },
        wireguard: { ...endpoint, port: 51820, ip: '10.0.0.2', 'private-key': '', 'public-key': '', udp: true },
        shadowquic: { ...endpoint, username: '', password: '', sni: 'node.example.com' },
        'gost-relay': { ...endpoint, username: '', password: '', udp: true },
        ssh: { ...endpoint, port: 22, username: '', password: '' },
        mieru: { ...endpoint, transport: 'TCP', username: '', password: '', udp: true },
        sudoku: { ...endpoint, key: '', 'aead-method': 'chacha20-poly1305', 'padding-min': 2, 'padding-max': 7, 'table-type': 'prefer_ascii' },
        masque: { ...endpoint, 'private-key': '', 'public-key': '', ip: '10.0.0.2', udp: true },
        openvpn: { ...endpoint, port: 1194, proto: 'udp', ca: '', username: '', password: '', udp: true },
        tailscale: { hostname: 'irboard-{{user_id}}', 'auth-key': '', 'exit-node': 'auto:any', udp: true },
        zerotier: { network: '', udp: true },
        easytier: { 'network-name': '', 'network-secret': '', peers: ['tcp://node.example.com:11010'], udp: true }
    };
    const protocols = Object.fromEntries(types.map(type => [type, { label: type, example: examples[type] }]));
    return { protocols, nodes: [], requests: [], fail: false, forbidden: false };
}
function nativeNode(row) {
    const settings = row.outbound_settings || {};
    return { ...row, type: row.type || 'mihomo', host: row.host || settings.server || '', port: row.port || settings.port || 0,
        server_port: row.server_port || row.port || settings.port || 0, group_id: row.group_id || ['1'], route_id: row.route_id || [],
        tags: row.tags || [], rate: row.rate || 1, sort: row.sort || 0, show: Number(row.show || 0), parent_id: null,
        available_status: null, online: null, load_status: null, last_check_at: null, last_push_at: null };
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
            const specific = url.pathname.includes('/server/mihomo/');
            const sorting = url.pathname.endsWith('/server/manage/sort');
            if (specific || sorting) {
                let raw = ''; for await (const part of req) raw += part;
                const action = sorting ? 'sort' : url.pathname.split('/').pop(), payload = raw ? JSON.parse(raw) : undefined;
                state.requests.push({ action, payload, auth: req.headers.authorization });
                if (state.forbidden || req.headers.authorization !== 'fixture-admin-token') { res.statusCode = 403; res.end(JSON.stringify({ message: 'Admin access required' })); return; }
                if (action === 'fetch') { res.end(JSON.stringify({ data: { version: '1.19.33', protocols: state.protocols, nodes: state.nodes.filter(row => row.type === 'mihomo') } })); return; }
                if (state.fail) { res.statusCode = 422; res.end(JSON.stringify({ message: 'Invalid settings', errors: { 'outbound_settings.password': ['Required credential'] } })); return; }
                const index = payload.id ? state.nodes.findIndex(row => row.id === payload.id && row.type === 'mihomo') : -1;
                if (action === 'save') {
                    const row = nativeNode({ ...payload, id: payload.id || Math.max(0, ...state.nodes.filter(row => row.type === 'mihomo').map(row => row.id)) + 1 });
                    if (row.host) row.outbound_settings.server = row.host;
                    if (row.port) row.outbound_settings.port = Number(row.port);
                    if (index >= 0) state.nodes[index] = row; else state.nodes.push(row);
                }
                if (action === 'update') state.nodes[index].show = Number(payload.show);
                if (action === 'drop') state.nodes.splice(index, 1);
                if (action === 'copy') state.nodes.push({ ...state.nodes[index], id: Math.max(...state.nodes.map(row => row.id)) + 1, name: state.nodes[index].name + ' (copy)', show: 0 });
                if (action === 'sort') {
                    for (const row of state.nodes) if (payload[row.type] && payload[row.type][row.id] !== undefined) row.sort = payload[row.type][row.id];
                    state.nodes.sort((a, b) => a.sort - b.sort);
                }
                res.end(JSON.stringify({ data: true })); return;
            }
            const user = { email: 'fixture@example.invalid', is_admin: 1, avatar_url: '', balance: 0, commission_balance: 0 };
            const data = /\/user\/(info|checkLogin)$/.test(url.pathname) ? user :
                url.pathname.endsWith('/server/group/fetch') ? [{ id: 1, name: 'Group <b>one</b>' }] :
                url.pathname.endsWith('/server/route/fetch') ? [] :
                url.pathname.endsWith('/server/manage/getNodes') ? state.nodes : [];
            res.end(JSON.stringify({ data })); return;
        }
        const bundle = url.searchParams.get('bundle') === 'umi-fa.js' ? 'umi-fa.js' : 'umi.js';
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/admin/components.chunk.css"><link rel="stylesheet" href="/assets/admin/umi.css"></head><body><div id="root"></div><script>localStorage.setItem('authorization',${JSON.stringify(url.searchParams.get('token'))});localStorage.setItem('umi_locale',${JSON.stringify(url.searchParams.get('lang') === 'fa' ? 'fa-IR' : 'en-US')});window.routerBase='/';window.settings={secure_path:'fixture-admin',title:'Irboard fixture',theme:{sidebar:'light',header:'light',color:'blue'},version:'test',background_url:'',logo:''};window.confirm=()=>true;window.fixtureReady=true;</script><script src="/assets/admin/vendors.async.js"></script><script src="/assets/admin/components.async.js"></script><script src="/assets/admin/${bundle}"></script></body></html>`);
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
async function addMenu(page) {
    const box = await page.evaluate('(()=>{const b=document.querySelector("[data-mihomo-add-menu]").getBoundingClientRect();return {x:b.x+b.width/2,y:b.y+b.height/2};})()');
    await cdp.call('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 10, y: 10 }, page.sessionId);
    await cdp.call('Input.dispatchMouseEvent', { type: 'mouseMoved', x: box.x, y: box.y }, page.sessionId);
    await page.wait('(()=>{const item=document.querySelector(".ant-dropdown:not(.ant-dropdown-hidden) [data-mihomo-add]");if(!item)return false;const menu=item.closest(".ant-dropdown"),box=menu.getBoundingClientRect();return box.width>100 && box.height>100 && Number(getComputedStyle(menu).opacity)>=.99;})()');
}
async function openForm(page, protocol) {
    await addMenu(page);
    await page.evaluate('document.querySelector(' + JSON.stringify('[data-mihomo-add="' + protocol + '"]').toString() + ').scrollIntoView({block:"nearest"})');
    await page.click('.ant-dropdown:not(.ant-dropdown-hidden) [data-mihomo-add="' + protocol + '"]');
    await page.wait('document.querySelector(' + JSON.stringify('[data-mihomo-form="' + protocol + '"] [data-mihomo-save]') + ') && !document.querySelector(' + JSON.stringify('[data-mihomo-form="' + protocol + '"] [data-mihomo-save]') + ').disabled');
    await page.wait('(()=>{const form=document.querySelector("[data-mihomo-form]"),panel=form && form.closest(".ant-drawer-content-wrapper");if(!panel)return false;const box=panel.getBoundingClientRect();return box.width>=innerWidth*.75 && box.left<=innerWidth*.21 && box.right<=innerWidth+2;})()');
}
async function closeForm(page) {
    await page.evaluate('document.querySelector("[data-mihomo-form]").closest(".ant-drawer").querySelector(".ant-drawer-close").click()');
    await page.wait('!document.querySelector("[data-mihomo-form]")');
    await page.wait('Array.from(document.querySelectorAll(".ant-drawer-mask")).every(mask=>{const style=getComputedStyle(mask),box=mask.getBoundingClientRect();return style.display==="none" || style.visibility==="hidden" || style.pointerEvents==="none" || box.width===0 || box.height===0;})');
}
async function selectGroup(page) {
    await page.click('[data-mihomo-form] .ant-select .ant-select-selection');
    await page.wait('document.querySelector(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item")');
    await page.click('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item');
}
async function draft(page, protocol = 'socks5', name = 'Fictional node') {
    await openForm(page, protocol); await page.set('name', name); await selectGroup(page);
    if (!['tailscale', 'zerotier', 'easytier'].includes(protocol)) {
        await page.set('host', 'new.example.test'); await page.set('port', '1443'); await page.set('server_port', '2443');
    }
    await page.set('rate', '1.5');
}
async function saved(page, count) {
    await page.wait('!document.querySelector("[data-mihomo-form]") && document.querySelectorAll("#main-container tr.ant-table-row").length>=' + count);
}
async function rowAction(page, name, action) {
    await page.evaluate(`(()=>{const row=Array.from(document.querySelectorAll('#main-container tr.ant-table-row')).find(row=>row.textContent.includes(${JSON.stringify(name)}));if(!row)throw new Error('Missing native row');row.querySelector('a.ant-dropdown-trigger').click();})()`);
    await page.wait('document.querySelector(".ant-dropdown:not(.ant-dropdown-hidden)")');
    await page.evaluate(`(()=>{const menu=Array.from(document.querySelectorAll('.ant-dropdown [data-mihomo-edit]')).find(e=>e.getBoundingClientRect().y>=0).closest('ul');const item=Array.from(menu.querySelectorAll('li')).find(item=>item.textContent.trim()===${JSON.stringify(action)});if(!item)throw new Error('Missing native action');item.click();})()`);
}

test('all fifteen protocol choices open normal forms from the existing + menu in both bundles', async () => {
    for (const [lang, bundle] of [['en', 'umi.js'], ['fa', 'umi-fa.js']]) {
        const page = await openPage(lang, bundle);
        assert.equal(await page.evaluate('document.querySelectorAll("#main-container .ant-table").length'), 1);
        assert.equal(await page.evaluate('!!document.querySelector("#irboard-mihomo-nodes")'), false);
        await addMenu(page);
        if (lang === 'fa' && process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-add-menu.png'));
        assert.deepEqual(await page.evaluate('Array.from(document.querySelectorAll(".ant-dropdown:not(.ant-dropdown-hidden) [data-mihomo-add]")).map(e=>e.dataset.mihomoAdd)'), types);
        assert.match(await page.evaluate('document.querySelector("[data-mihomo-add]").closest(".ant-dropdown").textContent'), /Shadowsocks/);
        for (const type of types) {
            await openForm(page, type);
            assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form]").dataset.mihomoForm'), type);
            assert.ok(await page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=name]") && document.querySelector("[data-mihomo-form] [name=rate]") && document.querySelector("[data-mihomo-form] .ant-select"))'));
            assert.ok(await page.evaluate('Array.from(document.querySelectorAll("[data-mihomo-form] [name]")).some(e=>e.name.startsWith("outbound."))'), type + ' has ordinary protocol fields');
            if (!['tailscale', 'zerotier', 'easytier'].includes(type)) assert.ok(await page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=host]") && document.querySelector("[data-mihomo-form] [name=server_port]"))'));
            assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] details").open'), false);
            assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-form] b")'), false);
            if (lang === 'fa' && type === 'ssh' && process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-node-form.png'));
            await closeForm(page);
        }
        assert.match(await page.evaluate('document.querySelector("[data-mihomo-add-menu]").closest("#main-container").textContent'), /مدیریت|نود|ترتیب/);
        await page.close();
    }
});

test('ordinary fields create and edit a node in the same native table and keep typed advanced options', async () => {
    const page = await openPage(); const name = '<img src=x onerror="window.pwned=1">';
    await draft(page, 'socks5', name);
    await page.set('outbound.username', 'fixture-user'); await page.set('outbound.password', ' secret ');
    await page.click('[data-mihomo-form] [name="outbound.udp"]');
    await page.set('sort', '8');
    const advanced = { server: 'old.example.test', port: 443, username: 'fixture-user', password: ' secret ', udp: false,
        smux: { enabled: false, 'max-connections': 4 }, headers: { 'X-Test': ['a', 'b'] } };
    await page.click('[data-mihomo-form] details summary');
    await page.set('outbound_settings', JSON.stringify(advanced, null, 2));
    await page.click('[data-mihomo-save]'); await saved(page, 1);
    const payload = state.requests.find(request => request.action === 'save').payload;
    assert.equal(payload.host, 'new.example.test'); assert.equal(payload.port, 1443); assert.equal(payload.server_port, 2443); assert.equal(payload.rate, 1.5);
    assert.equal(payload.outbound_settings.password, ' secret '); assert.equal(payload.outbound_settings.udp, false);
    assert.deepEqual(payload.outbound_settings.smux, advanced.smux); assert.deepEqual(payload.outbound_settings.headers, advanced.headers);
    assert.deepEqual(payload.group_id, ['1']);
    assert.equal(await page.evaluate('!!document.querySelector("#main-container img[src=x]") || !!window.pwned'), false);
    assert.match(await page.evaluate('document.querySelector("#main-container .ant-table").textContent'), /SOCKS5/);
    await page.click('#main-container [data-mihomo-edit="1"]');
    await page.wait('document.querySelector("[data-mihomo-form] [name=server_port]")');
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=server_port]").value'), '2443');
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=rate]").value'), '1.5');
    await page.set('name', 'Edited native node'); await page.set('server_port', '3443');
    await page.click('[data-mihomo-save]'); await saved(page, 1);
    assert.equal(state.nodes[0].server_port, 3443);
    await page.wait('document.querySelector("#main-container .ant-table").textContent.includes("Edited native node")');
    assert.match(await page.evaluate('document.querySelector("#main-container .ant-table").textContent'), /Edited native node/);
});

test('native visibility, copy, delete and mixed ordering controls work for new protocols', async () => {
    state.nodes = [nativeNode({ id: 1, name: 'existing SS node', type: 'shadowsocks', host: 'native.example.test', port: 8443, group_id: ['1'], show: 1 }),
        nativeNode({ id: 1, name: 'New SOCKS node', protocol: 'socks5', host: 'node.example.test', port: 1080, server_port: 2080, group_id: ['1'], show: 0, sort: 1, outbound_settings: { server: 'node.example.test', port: 1080, username: 'fixture', password: 'fixture', udp: true } })];
    const page = await openPage();
    await page.evaluate('Array.from(document.querySelectorAll("#main-container tr.ant-table-row")).find(row=>row.textContent.includes("New SOCKS node")).querySelector(".ant-switch").click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container tr.ant-table-row")).some(row=>row.textContent.includes("New SOCKS node") && row.querySelector(".ant-switch-checked"))');
    assert.equal(state.nodes.find(row => row.type === 'mihomo').show, 1);
    await rowAction(page, 'New SOCKS node', 'کپی');
    await page.wait('document.querySelector("#main-container .ant-table").textContent.includes("(copy)")');
    const copy = state.nodes.find(row => row.name.endsWith('(copy)'));
    assert.equal(copy.show, 0); assert.equal(copy.server_port, 2080);
    await page.evaluate('Array.from(document.querySelectorAll("#main-container button")).find(button=>button.textContent.includes("ویرایش ترتیب")).click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container button")).some(button=>button.textContent.includes("ذخیره‌ی ترتیب"))');
    await page.evaluate('Array.from(document.querySelectorAll("#main-container button")).find(button=>button.textContent.includes("ذخیره‌ی ترتیب")).click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container button")).some(button=>button.textContent.includes("ویرایش ترتیب"))');
    const sort = state.requests.find(request => request.action === 'sort');
    assert.ok(sort && sort.payload.mihomo && sort.payload.shadowsocks, 'native sorting sends all node types');
    await rowAction(page, copy.name, 'حذف');
    await page.wait('!document.querySelector("#main-container .ant-table").textContent.includes("(copy)")');
    assert.equal(state.nodes.filter(row => row.type === 'mihomo').length, 1);
});

test('invalid advanced JSON and rejected saves retain ordinary form values', async () => {
    const page = await openPage(); await draft(page);
    await page.set('outbound.password', 'retained-password');
    await page.click('[data-mihomo-form] details summary');
    await page.set('outbound_settings', '{broken');
    await page.click('[data-mihomo-save]');
    assert.equal(state.requests.filter(request => request.action === 'save').length, 0);
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=outbound_settings]").value'), '{broken');
    state.fail = true;
    await page.set('outbound_settings', '{"username":"fixture","password":"retained-password","udp":true}');
    await page.click('[data-mihomo-save]');
    await page.wait('document.querySelector(".ant-drawer-open [role=alert]") && !document.querySelector("[data-mihomo-save]").disabled');
    assert.match(await page.evaluate('document.querySelector(".ant-drawer-open [role=alert]").textContent'), /Required credential/);
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=name]").value'), 'Fictional node');
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=server_port]").value'), '2443');
});

test('route and account changes discard a drawer draft and stale credentials', async () => {
    const page = await openPage(); await draft(page); await page.set('outbound.password', 'private-draft');
    await page.evaluate('location.hash="/plan"'); await page.wait('!document.querySelector("[data-mihomo-form]")');
    await page.evaluate('location.hash="/server/manage"'); await page.wait('document.querySelector("[data-mihomo-add-menu]")');
    assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-form]")'), false);
    await draft(page); await page.set('outbound.password', 'private-draft');
    const saves = state.requests.filter(request => request.action === 'save').length;
    await page.evaluate('localStorage.setItem("authorization","another-account")');
    await page.wait('!document.querySelector("[data-mihomo-form]")');
    assert.equal(state.requests.filter(request => request.action === 'save').length, saves);
    assert.equal(await page.evaluate('document.body.textContent.includes("private-draft")'), false);
});

test('logged out pages do not fetch protocol settings without opening a valid drawer', async () => {
    const page = await openPage('en', 'umi.js', ''); await delay(350);
    assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-form]")'), false);
    assert.equal(state.requests.filter(request => request.action === 'fetch').length, 0);
});
