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

const types = ['ssr', 'socks5', 'http', 'snell', 'wireguard', 'shadowquic', 'gostrelay', 'ssh', 'mieru', 'sudoku', 'masque', 'openvpn', 'tailscale', 'zerotier', 'easytier'];
const formSelector = '[data-mihomo-form]';
function fieldSelector(name) { return formSelector + ' [data-mihomo-field=' + JSON.stringify(name) + ']'; }
async function chooseSelect(page, name, value) {
    const field = fieldSelector(name);
    if (value === '' || value == null) {
        const cleared = await page.evaluate(`(()=>{const field=document.querySelector(${JSON.stringify(field)}),clear=field.querySelector('.ant-select-selection__clear');if(!clear)return false;clear.click();return true;})()`);
        if (cleared) return;
    }
    await page.click(field + ' .ant-select-selection');
    await page.wait('Array.from(document.querySelectorAll(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item")).some(e=>e.getBoundingClientRect().height>0)');
    await page.evaluate(`(()=>{const value=${JSON.stringify(String(value))},items=Array.from(document.querySelectorAll('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item'));const item=items.find(item=>{const marked=item.matches('[data-mihomo-option]')?item:item.querySelector('[data-mihomo-option]');return marked&&marked.getAttribute('data-mihomo-option')===value;})||items.find(item=>item.getAttribute('data-value')===value||item.getAttribute('title')===value||item.textContent.trim().toLowerCase()===value.toLowerCase())||items.find(item=>value===''&&/No parent|بدون والد/.test(item.textContent));if(!item)throw new Error('Missing option '+value+' for '+${JSON.stringify(name)});item.click();})()`);
}
async function advanced(page) {
    if (!await page.evaluate('document.querySelector("[data-mihomo-advanced]").getAttribute("aria-expanded")==="true"')) await page.click('[data-mihomo-advanced]');
    await page.wait('document.querySelector("[data-mihomo-advanced]").getAttribute("aria-expanded")==="true"');
}

async function openPage(lang = 'en', bundle = 'umi.js', auth = 'fixture-admin-token', viewport = { width: 1440, height: 1100 }) {
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
            const selector = fieldSelector(name);
            const kind = await this.evaluate(`(()=>{const field=document.querySelector(${JSON.stringify(selector)});if(!field)throw new Error('Missing field '+${JSON.stringify(name)});const toggle=field.matches('.ant-switch')?field:field.querySelector('.ant-switch');if(toggle){const desired=${JSON.stringify(value)}===true||${JSON.stringify(value)}==='true';if((toggle.getAttribute('aria-checked')==='true')!==desired)toggle.click();return 'switch';}const native=field.matches('input,textarea,select')?field:field.querySelector('input:not([type=hidden]),textarea,select');if(field.querySelector('.ant-select'))return 'select';if(!native)throw new Error('Missing input '+${JSON.stringify(name)});const proto=native.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:native.tagName==='SELECT'?HTMLSelectElement.prototype:HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value').set.call(native,${JSON.stringify(String(value))});native.dispatchEvent(new Event(native.tagName==='SELECT'?'change':'input',{bubbles:true}));native.dispatchEvent(new Event('blur',{bubbles:true}));return 'input';})()`);
            if (kind === 'select') await chooseSelect(this, name, value);
        },
        async fieldValue(name) {
            return this.evaluate(`(()=>{const field=document.querySelector(${JSON.stringify(fieldSelector(name))});if(!field)return null;const toggle=field.querySelector('.ant-switch');if(toggle)return toggle.getAttribute('aria-checked')==='true';const selected=field.querySelector('.ant-select-selection-selected-value');if(selected)return selected.textContent.trim();const input=field.matches('input,textarea,select')?field:field.querySelector('input:not([type=hidden]),textarea,select');return input?input.value:field.textContent;})()`);
        },
        async toggle(name, checked) { await this.set(name, checked); },
        async screenshot(filename) {
            const result = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
            fs.writeFileSync(filename, Buffer.from(result.data, 'base64'));
        },
        async close() { await cdp.call('Target.closeTarget', { targetId }); pages.delete(page); }
    };
    pages.add(page);
    await cdp.call('Page.enable', {}, sessionId);
    await cdp.call('Emulation.setDeviceMetricsOverride', { ...viewport, deviceScaleFactor: 1, mobile: viewport.width <= 768 }, sessionId);
    await cdp.call('Page.navigate', { url: `${origin}/secure-fixture?lang=${lang}&bundle=${bundle}&token=${encodeURIComponent(auth)}#/server/manage` }, sessionId);
    await page.wait('window.fixtureReady && document.querySelector("#main-container .ant-table")');
    if (state.nodes.length) await page.wait('document.querySelectorAll("#main-container tr.ant-table-row").length>=' + Math.min(10, state.nodes.length));
    return page;
}
function freshState() {
    return { protocols: JSON.parse(fs.readFileSync(path.join(__dirname, 'Fixtures/MihomoCatalog.json'), 'utf8')), nodes: [], requests: [], fail: false, forbidden: false };
}
function nativeNode(row) {
    return { host: '', port: null, server_port: null, group_id: ['1'], route_id: [], tags: [], rate: 1, sort: 0, show: 0, parent_id: null,
        available_status: null, online: null, load_status: null, last_check_at: null, last_push_at: null, ...row };
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
            const type = url.pathname.split('/').slice(-2)[0];
            const catalog = url.pathname.endsWith('/server/protocols');
            const specific = types.includes(type);
            const sorting = url.pathname.endsWith('/server/manage/sort');
            if (specific || sorting || catalog) {
                let raw = ''; for await (const part of req) raw += part;
                const action = sorting ? 'sort' : catalog ? 'fetch' : url.pathname.split('/').pop(), payload = raw ? JSON.parse(raw) : undefined;
                state.requests.push({ action, type, payload, auth: req.headers.authorization });
                if (state.forbidden || req.headers.authorization !== 'fixture-admin-token') { res.statusCode = 403; res.end(JSON.stringify({ message: 'Admin access required' })); return; }
                if (action === 'fetch') { res.end(JSON.stringify({ data: { version: '1.19.33', protocols: state.protocols,  } })); return; }
                if (state.fail) { res.statusCode = 422; res.end(JSON.stringify({ message: 'Invalid settings', errors: { 'password': ['Required credential'] } })); return; }
                const index = payload.id ? state.nodes.findIndex(row => row.id === payload.id && row.type === type) : -1;
                if (action === 'save') {
                    const row = nativeNode({ ...payload, type, id: payload.id || Math.max(0, ...state.nodes.filter(row => row.type === type).map(row => row.id)) + 1 });
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
        res.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no"><link rel="stylesheet" href="/assets/admin/components.chunk.css"><link rel="stylesheet" href="/assets/admin/umi.css"></head><body><div id="root"></div><script>localStorage.setItem('authorization',${JSON.stringify(url.searchParams.get('token'))});localStorage.setItem('umi_locale',${JSON.stringify(url.searchParams.get('lang') === 'fa' ? 'fa-IR' : 'en-US')});window.routerBase='/';window.settings={secure_path:'fixture-admin',title:'Irboard fixture',theme:{sidebar:'light',header:'light',color:'blue'},version:'test',background_url:'',logo:''};window.confirm=()=>true;window.fixtureReady=true;</script><script src="/assets/admin/vendors.async.js"></script><script src="/assets/admin/components.async.js"></script><script src="/assets/admin/${bundle}"></script></body></html>`);
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
    // Browser.close replies before Chromium finishes writing its profile.
    // Observe exit before deleting it, including the signal/exit-already case.
    const stopped = chrome && new Promise(resolve => {
        if (chrome.exitCode !== null || chrome.signalCode !== null) resolve();
        else chrome.once('exit', resolve);
    });
    if (cdp) { try { await cdp.call('Browser.close'); } catch (_) {} cdp.ws.close(); }
    if (chrome) chrome.kill();
    if (stopped) await stopped;
    if (server) await new Promise(resolve => server.close(resolve));
    if (profile) await fs.promises.rm(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
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
    await page.wait('document.querySelector(' + JSON.stringify('[data-mihomo-form="' + protocol + '"]') + ') && document.querySelector("[data-mihomo-save]") && !document.querySelector("[data-mihomo-save]").disabled');
    await page.wait('(()=>{const form=document.querySelector("[data-mihomo-form]"),panel=form && form.closest(".ant-drawer-content-wrapper");if(!panel)return false;const box=panel.getBoundingClientRect();return box.width>=Math.min(innerWidth,450)-2 && box.width<=Math.min(innerWidth,550)+2 && box.left>=-2 && box.right<=innerWidth+2;})()');
}
async function closeForm(page) {
    await page.evaluate('document.querySelector("[data-mihomo-form]").closest(".ant-drawer").querySelector(".ant-drawer-close").click()');
    await page.wait('!document.querySelector("[data-mihomo-form]")');
    await page.wait('Array.from(document.querySelectorAll(".ant-drawer-mask")).every(mask=>{const style=getComputedStyle(mask),box=mask.getBoundingClientRect();return style.display==="none" || style.visibility==="hidden" || style.pointerEvents==="none" || box.width===0 || box.height===0;})');
}
async function selectGroup(page) {
    await page.click(fieldSelector('group_id') + ' .ant-select-selection');
    await page.wait('document.querySelector(".ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item")');
    await page.click('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-dropdown-menu-item');
}
async function draft(page, protocol = 'socks5', name = 'Fictional node') {
    await openForm(page, protocol); await page.set('name', name); await selectGroup(page);
    if (!['tailscale', 'zerotier', 'easytier'].includes(protocol)) {
        await page.set('host', 'new.example.test'); await page.set('port', '1443');
        if (state.protocols[protocol].managed_server) await page.set('server_port', '2443');
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
            assert.ok(await page.evaluate('Array.from(document.querySelectorAll("[data-mihomo-form] [name]")).some(e=>!["name","rate","host","port","server_port","parent_id","sort","show"].includes(e.name))'), type + ' has ordinary protocol fields');
            if (!['tailscale', 'zerotier', 'easytier'].includes(type)) {
                assert.ok(await page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=host]"))'));
                // New drafts of V2bX-capable types start managed; other types never run a V2bX listener.
                assert.equal(await page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=server_port]"))'), Boolean(state.protocols[type].managed_server), type + ' service port');
            }
            assert.equal(await page.evaluate('document.querySelector("[data-mihomo-advanced]").getAttribute("aria-expanded")'), 'false');
            const controls = await page.evaluate('(()=>{const form=document.querySelector("[data-mihomo-form]");return {plain:Array.from(form.querySelectorAll("input:not([type=hidden]),textarea")).filter(e=>!e.closest(".ant-select")&&!e.classList.contains("ant-input")).map(e=>e.name),help:Array.from(form.querySelectorAll("[data-mihomo-field]")).filter(e=>e.getBoundingClientRect().height>0).map(e=>({field:e.dataset.mihomoField,label:(e.querySelector("label")||{}).textContent||"",help:(e.querySelector("[data-mihomo-help]")||{}).textContent||""})),sections:Array.from(form.querySelectorAll("[data-mihomo-section]")).filter(e=>e.getBoundingClientRect().height>0).map(e=>e.textContent.trim()),footer:!!document.querySelector(".ant-drawer-open .v2board-drawer-action .ant-btn-primary[data-mihomo-save]")};})()');
            assert.deepEqual(controls.plain, [], type + ' uses the bundled AntD inputs');
            assert.ok(controls.help.length >= 7, type + ' exposes ordinary field guidance');
            for (const field of controls.help) {
                assert.ok(field.label.trim().length > 0, type + '/' + field.field + ' has a label');
                assert.ok(field.help.trim().length >= 18, type + '/' + field.field + ' explains what to enter or how the option works');
            }
            assert.ok(controls.sections.length >= 2, type + ' separates ordinary connection and protocol settings');
            assert.ok(controls.footer, type + ' uses the original drawer action footer');
            if (!['tailscale', 'zerotier', 'easytier'].includes(type)) assert.equal(await page.fieldValue('host'), '', type + ' never prefills the fixture server');
            assert.equal(await page.fieldValue('rate'), '1');
            const secrets = await page.evaluate('Array.from(document.querySelectorAll("[data-mihomo-form] input,[data-mihomo-form] textarea")).filter(e=>/^(username|password|private_key|pre_shared_key|psk|key|auth_key|network_secret|identity_secret)$/.test(e.name)).map(e=>({name:e.name,value:e.value}))');
            for (const field of secrets) assert.ok(field.value === '' || field.value === '{{uuid}}', type + '/' + field.name + ' has no fictional saved credential');
            if (lang === 'fa' && type === 'http' && process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-managed-http-fa.png'));
            await advanced(page);
            const advancedHelp = await page.evaluate('Array.from(document.querySelectorAll("[data-mihomo-form] [data-mihomo-field]")).filter(e=>e.getBoundingClientRect().height>0).map(e=>({field:e.dataset.mihomoField,help:(e.querySelector("[data-mihomo-help]")||{}).textContent||""}))');
            for (const field of advancedHelp) assert.ok(field.help.trim().length >= 18, type + '/' + field.field + ' advanced guidance');
            if (lang === 'fa' && type === 'ssh' && process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-node-form.png'));
            await closeForm(page);
        }
        assert.match(await page.evaluate('document.querySelector("[data-mihomo-add-menu]").closest("#main-container").textContent'), /مدیریت|نود|ترتیب/);
        await page.close();
    }
});

test('ordinary fields create and edit a node in the same native table and keep typed advanced options', async () => {
    const page = await openPage(); const name = '<img src=x onerror="window.pwned=1">';
    await draft(page, 'http', name);
    await page.toggle('managed', false);
    await page.set('username', 'fixture-user'); await page.set('password', ' secret ');
    await page.set('tls', 'false'); await page.set('sort', '8');
    await advanced(page);
    const headers = { 'X-Test': ' value ' };
    await page.set('headers', JSON.stringify(headers));
    await page.click('[data-mihomo-save]'); await saved(page, 1);
    const request = state.requests.find(request => request.action === 'save'), payload = request.payload;
    assert.equal(request.type, 'http');
    assert.equal(payload.type, undefined); assert.equal(payload.outbound_settings, undefined);
    assert.equal(payload.host, 'new.example.test'); assert.equal(payload.port, 1443); assert.equal(payload.server_port, 2443); assert.equal(payload.rate, 1.5);
    assert.equal(payload.password, ' secret '); assert.equal(payload.tls, false); assert.deepEqual(payload.headers, headers);
    assert.deepEqual(payload.group_id, ['1']);
    assert.equal(await page.evaluate('!!document.querySelector("#main-container img[src=x]") || !!window.pwned'), false);
    assert.match(await page.evaluate('document.querySelector("#main-container .ant-table").textContent'), /HTTP/);
    await page.click('#main-container [data-mihomo-edit="1"]');
    await page.wait('document.querySelector("[data-mihomo-form] [name=rate]")');
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=rate]").value'), '1.5');
    // An external service has no V2bX listener; its stored value is kept untouched.
    assert.equal(await page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=server_port]"))'), false);
    await page.set('name', 'Edited native node');
    await page.click('[data-mihomo-save]'); await saved(page, 1);
    assert.equal(state.nodes[0].server_port, 2443);
    await page.wait('document.querySelector("#main-container .ant-table").textContent.includes("Edited native node")');
    assert.match(await page.evaluate('document.querySelector("#main-container .ant-table").textContent'), /Edited native node/);
});

test('native visibility, copy, delete and mixed ordering controls work for new protocols', async () => {
    state.nodes = [nativeNode({ id: 1, name: 'existing SS node', type: 'shadowsocks', host: 'native.example.test', port: 8443, group_id: ['1'], show: 1 }),
        nativeNode({ id: 1, name: 'New SOCKS node', type: 'socks5', host: 'node.example.test', port: 1080, server_port: 2080, group_id: ['1'], show: 0, sort: 1, username: 'fixture', password: 'fixture', udp: true })];
    const page = await openPage();
    await page.evaluate('Array.from(document.querySelectorAll("#main-container tr.ant-table-row")).find(row=>row.textContent.includes("New SOCKS node")).querySelector(".ant-switch").click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container tr.ant-table-row")).some(row=>row.textContent.includes("New SOCKS node") && row.querySelector(".ant-switch-checked"))');
    assert.equal(state.nodes.find(row => row.type === 'socks5').show, 1);
    await rowAction(page, 'New SOCKS node', 'کپی');
    await page.wait('document.querySelector("#main-container .ant-table").textContent.includes("(copy)")');
    const copy = state.nodes.find(row => row.name.endsWith('(copy)'));
    assert.equal(copy.show, 0); assert.equal(copy.server_port, 2080);
    await page.evaluate('Array.from(document.querySelectorAll("#main-container button")).find(button=>button.textContent.includes("ویرایش ترتیب")).click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container button")).some(button=>button.textContent.includes("ذخیره‌ی ترتیب"))');
    await page.evaluate('Array.from(document.querySelectorAll("#main-container button")).find(button=>button.textContent.includes("ذخیره‌ی ترتیب")).click()');
    await page.wait('Array.from(document.querySelectorAll("#main-container button")).some(button=>button.textContent.includes("ویرایش ترتیب"))');
    const sort = state.requests.find(request => request.action === 'sort');
    assert.ok(sort && sort.payload.socks5 && sort.payload.shadowsocks, 'native sorting sends all node types');
    await rowAction(page, copy.name, 'حذف');
    await page.wait('!document.querySelector("#main-container .ant-table").textContent.includes("(copy)")');
    assert.equal(state.nodes.filter(row => row.type === 'socks5').length, 1);
});

test('invalid advanced JSON and rejected saves retain ordinary form values', async () => {
    const page = await openPage(); await draft(page, 'http');
    await page.toggle('managed', false);
    await page.set('password', 'retained-password');
    await advanced(page);
    await page.set('headers', '{broken');
    await page.click('[data-mihomo-save]');
    assert.equal(state.requests.filter(request => request.action === 'save').length, 0);
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=headers]").value'), '{broken');
    state.fail = true;
    await page.set('headers', '{"X-Test":"retained value"}');
    await page.click('[data-mihomo-save]');
    await page.wait('document.querySelector(".ant-drawer-open [role=alert]") && !document.querySelector("[data-mihomo-save]").disabled');
    assert.match(await page.evaluate('document.querySelector(".ant-drawer-open [role=alert]").textContent'), /Required credential/);
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=name]").value'), 'Fictional node');
    assert.equal(await page.evaluate('document.querySelector("[data-mihomo-form] [name=port]").value'), '1443');
});

test('route and account changes discard a drawer draft and stale credentials', async () => {
    const page = await openPage(); await draft(page); await page.set('password', 'private-draft');
    await page.evaluate('location.hash="/plan"'); await page.wait('!document.querySelector("[data-mihomo-form]")');
    await page.evaluate('location.hash="/server/manage"'); await page.wait('document.querySelector("[data-mihomo-add-menu]")');
    assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-form]")'), false);
    await draft(page); await page.set('password', 'private-draft');
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


test('parent choices stay within one protocol and optional booleans preserve their default', async () => {
    state.nodes = [
        nativeNode({id:1, type:'http', name:'HTTP root', host:'root.test', port:443}),
        nativeNode({id:2, type:'http', name:'HTTP child', parent_id:1, host:'child.test', port:443}),
        nativeNode({id:3, type:'http', name:'HTTP grandchild', parent_id:2, host:'grandchild.test', port:443}),
        nativeNode({id:4, type:'socks5', name:'Other protocol', host:'other.test', port:1080})
    ];
    const page = await openPage();
    await page.click('#main-container [data-mihomo-edit="2"]');
    await page.wait('document.querySelector("[data-mihomo-form] [data-mihomo-field=parent_id]")');
    await page.click(fieldSelector('parent_id') + ' .ant-select-selection');
    assert.deepEqual(await page.evaluate('Array.from(document.querySelectorAll(".ant-select-dropdown:not(.ant-select-dropdown-hidden) [data-mihomo-option]")).map(e=>e.getAttribute("data-mihomo-option"))'), ['', '1']);
    await page.evaluate('document.querySelector("[data-mihomo-form] [name=name]").focus()');
    assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-form] [name=password]")'), false);
    await page.set('parent_id', '');
    await page.wait('document.querySelector("[data-mihomo-form] [name=password]")');
    assert.match(await page.fieldValue('tls'), /Protocol default|پیش‌فرض/);
    await page.set('tls', 'false');
    await page.click('[data-mihomo-save]');
    await saved(page, 4);
    const request = state.requests.find(r=>r.action==='save');
    assert.equal(request.type, 'http');assert.equal(request.payload.id, 2);assert.equal(request.payload.parent_id, null);assert.equal(request.payload.tls, false);
});

test('the V2bX listening port is offered only for a managed root node, and SSH explains host verification', async () => {
    const page = await openPage();
    const listening = () => page.evaluate('Boolean(document.querySelector("[data-mihomo-form] [name=server_port]"))');
    await openForm(page, 'http');
    assert.equal(await listening(), true);
    await page.toggle('managed', false);
    assert.equal(await listening(), false, 'an external service has no V2bX listener');
    await page.toggle('managed', true);
    assert.equal(await listening(), true);
    await closeForm(page);
    await openForm(page, 'ssh');
    assert.equal(await listening(), false);
    assert.match(await page.evaluate('document.querySelector("[data-mihomo-section=security]").textContent'), /SSH server verification/);
    assert.match(await page.evaluate('document.querySelector("[data-mihomo-field=host_key] textarea").placeholder'), /^\["ssh-ed25519 /);
});

test('managed server mode is available only for the four implemented server protocols', async () => {
    const page = await openPage();
    for (const type of ['http','socks5','mieru','shadowquic']) {
        await draft(page,type,'Managed '+type);
        assert.equal(await page.fieldValue('managed'),true);
        assert.match(await page.evaluate('document.querySelector("[data-mihomo-form]").textContent'), /\{\{uuid\}\}/);
        await page.set('username','{{uuid}}');await page.set('password','{{uuid}}');
        const expectedCount = state.nodes.length + 1;
        await page.click('[data-mihomo-save]');await saved(page,expectedCount);
        const request=state.requests.filter(r=>r.action==='save').at(-1);
        assert.equal(request.payload.managed,true);assert.equal(request.payload.username,'{{uuid}}');
    }
    await openForm(page,'wireguard');
    assert.equal(await page.evaluate('!!document.querySelector("[data-mihomo-field=managed]")'),false);
});


test('required native fields show actionable inline errors before sending a save', async () => {
    const page = await openPage();
    await openForm(page, 'http');
    await page.click('[data-mihomo-save]');
    await page.wait('document.querySelector("[data-mihomo-form] .has-error, [data-mihomo-form] [data-mihomo-error]")');
    assert.equal(state.requests.filter(request => request.action === 'save').length, 0);
    const errors = await page.evaluate('document.querySelector("[data-mihomo-form]").textContent');
    assert.match(errors, /required|enter|select/i);
    await page.set('name', 'Validated node');
    await page.set('host', 'node.example.test');
    await page.set('port', '1443');
    await page.set('server_port', '2443');
    await page.set('rate', '1');
    await page.click('[data-mihomo-save]');
    assert.equal(state.requests.filter(request => request.action === 'save').length, 0, 'an access group must be chosen');
    await selectGroup(page);
    await page.click('[data-mihomo-save]');
    await saved(page, 1);
    assert.equal(state.requests.filter(request => request.action === 'save').length, 1);
});

test('editing an external node preserves explicit false, null and user-selected protocol settings', async () => {
    state.nodes = [nativeNode({ id: 9, name: 'Existing external HTTP', type: 'http', host: 'external.example.test', port: 8080, server_port: 8080,
        managed: false, tls: null, skip_cert_verify: false, udp: false, username: 'external-user', password: 'retained-secret', headers: { 'X-Original': 'preserved' } })];
    const page = await openPage();
    await page.click('#main-container [data-mihomo-edit="9"]');
    await page.wait('document.querySelector("[data-mihomo-form] [data-mihomo-field=managed]")');
    assert.equal(await page.fieldValue('managed'), false, 'new managed default is not applied to saved external nodes');
    assert.match(await page.fieldValue('tls'), /Protocol default|پیش‌فرض/);
    assert.equal(await page.fieldValue('username'), 'external-user');
    await page.set('name', 'Renamed external HTTP');
    await page.click('[data-mihomo-save]');
    await saved(page, 1);
    const payload = state.requests.find(request => request.action === 'save').payload;
    assert.equal(payload.managed, false);
    assert.equal(payload.tls, null);
    assert.equal(payload.skip_cert_verify, false);
    assert.equal(payload.username, 'external-user');
    assert.equal(payload.password, 'retained-secret');
    assert.deepEqual(payload.headers, { 'X-Original': 'preserved' });
});

test('editing a WireGuard peer list preserves its saved optional single-peer endpoint', async () => {
    const peers = [{server: 'peer.example.test', port: 51820, 'public-key': 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'allowed-ips': ['0.0.0.0/0']}];
    state.nodes = [nativeNode({id: 12, type: 'wireguard', name: 'Peer node', host: 'retained.example.test', port: 51820, server_port: 51820,
        private_key: 'YmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmI=', ip: '10.0.0.2', peers: peers})];
    const page = await openPage();
    await page.click('#main-container [data-mihomo-edit="12"]');
    await page.wait('document.querySelector("[data-mihomo-form] [name=name]")');
    await page.set('name', 'Renamed peer node');
    await page.set('peers', JSON.stringify(peers, null, 2));
    await page.click('[data-mihomo-save]'); await saved(page, 1);
    const payload = state.requests.find(request => request.action === 'save').payload;
    assert.equal(payload.host, 'retained.example.test'); assert.equal(payload.port, 51820);
    assert.deepEqual(payload.peers, peers);
});

test('mobile Persian forms fit the viewport and retain the native drawer actions', async () => {
    const page = await openPage('fa', 'umi-fa.js', 'fixture-admin-token', { width: 390, height: 844 });
    await openForm(page, 'http');
    const geometry = await page.evaluate('(()=>{const form=document.querySelector("[data-mihomo-form]"),panel=form.closest(".ant-drawer-content-wrapper"),box=panel.getBoundingClientRect(),action=document.querySelector(".ant-drawer-open .v2board-drawer-action"),save=document.querySelector("[data-mihomo-save]");return {width:box.width,left:box.left,right:box.right,scroll:form.scrollWidth,client:form.clientWidth,actions:action?Array.from(action.querySelectorAll("button")).map(b=>({text:b.textContent,native:b.classList.contains("ant-btn")})):[],save:save.getBoundingClientRect().toJSON(),drawer:{scroll:form.closest(".ant-drawer-body").scrollWidth,client:form.closest(".ant-drawer-body").clientWidth},offenders:Array.from(form.querySelectorAll("*")).map(e=>({tag:e.tagName,cls:e.className,box:e.getBoundingClientRect().toJSON()})).filter(e=>e.box.height>0&&(e.box.right>innerWidth+2||e.box.left < -2)).slice(0,8)};})()');
    assert.ok(geometry.left >= -2 && geometry.right <= 392, JSON.stringify(geometry));
    assert.ok(geometry.width >= 388 && geometry.width <= 392, JSON.stringify(geometry));
    if (process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-node-mobile-fa.png'));
    assert.ok(geometry.drawer.scroll <= geometry.drawer.client + 2, 'drawer content has no horizontal overflow: '+JSON.stringify(geometry));
    assert.ok(geometry.actions.length >= 2 && geometry.actions.every(action => action.native));
    assert.ok(geometry.save.bottom <= 846 && geometry.save.top >= 0, 'save remains visible at the bottom of the mobile drawer');
    await advanced(page);
    assert.ok(await page.evaluate('document.querySelector("[data-mihomo-form]").closest(".ant-drawer-body").scrollWidth<=document.querySelector("[data-mihomo-form]").closest(".ant-drawer-body").clientWidth+2'));
    if (process.env.MIHOMO_SCREENSHOT_DIR) await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-node-mobile-fa.png'));
});

test('managed forms reject shared fixed credentials before sending them to the server', async () => {
    const page = await openPage();
    await draft(page, 'socks5', 'Managed credentials');
    assert.equal(await page.fieldValue('managed'), true);
    await page.set('username', 'shared-user');
    await page.set('password', 'shared-password');
    await page.click('[data-mihomo-save]');
    await page.wait('document.querySelector("[data-mihomo-form] .has-error, [data-mihomo-form] [data-mihomo-error]")');
    assert.equal(state.requests.filter(request => request.action === 'save').length, 0);
    assert.match(await page.evaluate('document.querySelector("[data-mihomo-form]").textContent'), /\{\{uuid\}\}/);
    await page.set('username', '');
    await page.set('password', '');
    await page.click('[data-mihomo-save]');
    await saved(page, 1);
    const payload = state.requests.find(request => request.action === 'save').payload;
    assert.equal(payload.managed, true);
    assert.ok(payload.username == null || payload.username === '');
    assert.ok(payload.password == null || payload.password === '');
});

test('the ten original protocol drawers keep their native controls and explain their fields', async () => {
    const protocols = [
        ['v2node', 'V2node'], ['shadowsocks', 'Shadowsocks'], ['vmess', 'VMess'],
        ['trojan', 'Trojan'], ['hysteria', 'Hysteria'], ['tuic', 'Tuic'],
        ['vless', 'VLess'], ['anytls', 'AnyTLS'], ['mdns', 'MDNS'], ['trusttunnel', 'TrustTunnel']
    ];
    for (const [lang, bundle] of [['en', 'umi.js'], ['fa', 'umi-fa.js']]) {
        const page = await openPage(lang, bundle);
        for (const [protocol, label] of protocols) {
            await addMenu(page);
            await page.evaluate(`(()=>{const dropdown=document.querySelector('.ant-dropdown:not(.ant-dropdown-hidden) [data-mihomo-add]').closest('.ant-dropdown'),item=Array.from(dropdown.querySelectorAll('li')).find(item=>!item.querySelector('[data-mihomo-add]')&&item.textContent.trim().toLowerCase().includes(${JSON.stringify(label.toLowerCase())}));if(!item)throw new Error('Missing original protocol '+${JSON.stringify(protocol)});const trigger=item.querySelector('a');trigger.scrollIntoView({block:'nearest'});trigger.click();})()`);
            const hook = '.ant-drawer-open [data-native-node-intro="' + protocol + '"]';
            await page.wait('document.querySelector(' + JSON.stringify(hook) + ')');
            const guidance = await page.evaluate(`(()=>{const intro=document.querySelector(${JSON.stringify(hook)}),drawer=intro.closest('.ant-drawer');return {intro:intro.textContent,help:Array.from(drawer.querySelectorAll('[data-native-node-help]')).filter(e=>e.getBoundingClientRect().height>0).map(e=>({field:e.dataset.nativeNodeHelp,text:e.textContent})),groups:Array.from(drawer.querySelectorAll('.form-group')).filter(e=>e.getBoundingClientRect().height>0&&e.querySelector('input.ant-input,textarea.ant-input,.ant-select,.ant-switch')).map(e=>({label:(e.querySelector('label')||{}).textContent||'',help:!!e.querySelector('[data-native-node-help]')})),inputs:Array.from(drawer.querySelectorAll('input.ant-input,textarea.ant-input')).filter(e=>e.getBoundingClientRect().height>0).map(e=>({label:e.getAttribute('aria-label'),help:e.getAttribute('aria-describedby')})),footer:!!drawer.querySelector('.v2board-drawer-action .ant-btn-primary')};})()`);
            assert.ok(guidance.intro.trim().length >= 30, protocol + ' explains the protocol');
            assert.ok(guidance.help.length >= 5, protocol + ' has guidance for the native fields');
            for (const field of guidance.help) assert.ok(field.text.trim().length >= 18, protocol + '/' + field.field + ' guidance');
            for (const group of guidance.groups) assert.ok(group.help, protocol + '/' + group.label + ' has field guidance');
            for (const input of guidance.inputs) assert.ok(input.help, protocol + ' inputs associate visible guidance for assistive technology');
            assert.ok(guidance.footer, protocol + ' keeps the native fixed action footer');
            if (process.env.MIHOMO_SCREENSHOT_DIR && lang === 'fa' && protocol === 'vless') await page.screenshot(path.join(process.env.MIHOMO_SCREENSHOT_DIR, 'native-original-vless-fa.png'));
            await page.evaluate('document.querySelector(' + JSON.stringify(hook) + ').closest(".ant-drawer").querySelector(".ant-drawer-close").click()');
            await page.wait('!document.querySelector(' + JSON.stringify(hook) + ')');
        }
        await page.close();
    }
});
