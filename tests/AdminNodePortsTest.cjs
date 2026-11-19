'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../scripts/admin_mihomo_form.js'), 'utf8')
    .split('/*__NODE_PORT_SOURCE_START__*/')[1].split('/*__NODE_PORT_SOURCE_END__*/')[0];

function fixture() {
    const storage = { token: 'admin-one', scope: '/secure#/server/manage', locale: 'en' };
    let now = Date.parse('2026-11-16T09:00:00Z'), nextTimer = 1;
    const timers = new Map(), requests = [];
    class Clock extends Date { static now() { return now; } }
    class Component { constructor(props) { this.props = props; } setState(fn) { this.state = { ...this.state, ...(typeof fn === 'function' ? fn(this.state) : fn) }; } }
    const context = vm.createContext({ Date: Clock, AbortController, window: { settings: { secure_path: 'fixture-admin' } },
        imnToken: () => storage.token, imnScope: () => storage.scope, imnText: (fa, en) => storage.locale === 'fa' ? fa : en,
        y: { a: { Component, createElement: (tag, props, ...children) => ({ tag, props, children }) } },
        setInterval: fn => { const id = nextTimer++; timers.set(id, fn); return id; }, clearInterval: id => timers.delete(id),
        setTimeout: fn => { const id = nextTimer++; timers.set(id, fn); return id; }, clearTimeout: id => timers.delete(id),
        fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve })) });
    vm.runInContext(source, context);
    const row = (type = 'vless', id = 7, fields = {}) => ({ type, id, host: 'node.example.test', port: 443, server_port: 2053, ...fields });
    const report = ports => ({ ok: true, updated_at: new Date(now).toISOString(), ports });
    const entry = (ref = 'vless#7', proto = 'tcp', allowed = true, port = '2053') => ({ nodes: [ref], proto, allowed, port });
    return { context, storage, timers, requests, row, report, entry, now: () => now, advance: ms => { now += ms; },
        result: (node, data, nodes = []) => context.imnPortResult(node, nodes, data, now),
        respond: async (request, data, ok = true) => { request.resolve({ ok, json: async () => ({ data }) }); await new Promise(setImmediate); } };
}

test('port status uses exact protocol/id and service port, not an unrelated open port', () => {
    const f = fixture(), node = f.row();
    assert.equal(f.result(node, f.report([f.entry()])).state, 'open');
    assert.equal(f.result(node, f.report([f.entry('trojan#7')])).state, 'unknown');
    assert.equal(f.result(node, f.report([f.entry('vless#7', 'tcp', true, '443')])).state, 'unknown');
    assert.equal(f.result(node, f.report([f.entry('vless#7', 'tcp', false)])).state, 'closed');
    assert.match(f.result(node, f.report([f.entry()])).detail, /2053/);
});

test('both transport rules are needed; one blocked transport is red and a missing one is unknown', () => {
    const f = fixture(), node = f.row('shadowsocks');
    const tcp = f.entry('shadowsocks#7'), udp = f.entry('shadowsocks#7', 'udp');
    assert.equal(f.result(node, f.report([tcp, udp])).state, 'open');
    assert.equal(f.result(node, f.report([tcp])).state, 'unknown');
    assert.equal(f.result(node, f.report([tcp, { ...udp, allowed: false }])).state, 'closed');
    assert.equal(f.result(node, f.report([tcp, { ...udp, allowed: 1 }])).state, 'unknown');
});

test('UDP-only transports and managed Mieru ranges use their real listener requirements', () => {
    const f = fixture();
    assert.equal(f.result(f.row('hysteria'), f.report([f.entry('hysteria#7', 'udp')])).state, 'open');
    assert.equal(f.result(f.row('vless', 7, { network: 'kcp' }), f.report([f.entry()])).state, 'unknown');
    assert.equal(f.result(f.row('vless', 7, { network: 'kcp' }), f.report([f.entry('vless#7', 'udp')])).state, 'open');
    const mieru = f.row('mieru', 7, { managed: true, transport: 'UDP', port_range: '2000 - 2010' });
    assert.equal(f.result(mieru, f.report([f.entry('mieru#7', 'udp', true, '2000-2010')])).state, 'open');
    assert.equal(f.result(f.row('snell', 7, { managed: false }), f.report([f.entry('snell#7')])).state, 'unknown');
});

test('stale, failed, unmeasured and future reports never claim an open or closed port', () => {
    const f = fixture(), node = f.row(), report = f.report([f.entry()]);
    for (const data of [null, {}, { ...report, ok: false }, { ...report, ports: [] },
        { ...report, updated_at: '2026-11-16 08:56:59+0000' }, { ...report, updated_at: '2026-11-16 09:01:00+0000' },
        { ...report, updated_at: '2026-11-16 09:00:00' }]) {
        assert.equal(f.result(node, data).state, 'unknown');
    }
    assert.equal(f.result(node, { ...report, updated_at: '2026-11-16 09:00:00+0000' }).state, 'open');
    assert.equal(f.result(node, { ...report, updated_at: '2026-11-16 12:30:00+0330' }).state, 'open');
    assert.equal(f.result(node, { ...report, updated_at: '2026-11-16 12:30:00+03:30' }).state, 'open');
});

test('parent listeners are inherited safely; edits, missing parents and cycles invalidate the badge', () => {
    const f = fixture(), parent = f.row(), child = f.row('vless', 8, { parent_id: 7, server_port: 8443 });
    assert.equal(f.result(child, f.report([f.entry()]), [parent, child]).state, 'open');
    assert.match(f.result(child, f.report([f.entry()]), [parent, child]).detail, /parent node listener/);
    assert.equal(f.result(child, f.report([f.entry()]), []).state, 'unknown');
    assert.equal(f.result(parent, f.report([f.entry()]), [{ ...parent, parent_id: 7 }]).state, 'open');
    assert.equal(f.result({ ...parent, parent_id: 7 }, f.report([f.entry()]), [{ ...parent, parent_id: 7 }]).state, 'unknown');
    assert.equal(f.result({ ...parent, updated_at: f.now() / 1000 + 1 }, f.report([f.entry()])).state, 'unknown');
    assert.equal(f.result(f.row('vless', 7, { server_port: 0, port: 443 }), f.report([f.entry()])).state, 'unknown');
});

test('many visible badges share one authenticated read and release all work on unmount', async () => {
    const f = fixture(), store = f.context.imnPortStore;
    const stop = Array.from({ length: 50 }, () => store.subscribe(() => {}));
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].url, '/api/v1/fixture-admin/system/getNodePorts');
    assert.equal(f.requests[0].options.headers.authorization, 'admin-one');
    await f.respond(f.requests[0], f.report([f.entry()]));
    assert.equal(store.snapshot().ok, true);
    stop.forEach(fn => fn());
    assert.equal(store.snapshot(), null);
    assert.equal(f.timers.size, 0);
});

test('logout and navigation discard late responses and the prior session snapshot', async () => {
    const f = fixture(), store = f.context.imnPortStore;
    const stop = store.subscribe(() => {}), old = f.requests[0];
    f.storage.token = 'admin-two'; store.tick();
    assert.equal(old.options.signal.aborted, true);
    assert.equal(store.snapshot(), null);
    await f.respond(old, f.report([f.entry()]));
    assert.equal(store.snapshot(), null);
    await f.respond(f.requests[1], f.report([f.entry('vless#7', 'tcp', false)]));
    assert.equal(store.snapshot().ports[0].allowed, false);
    f.storage.scope = '/secure#/dashboard'; store.tick();
    assert.equal(store.snapshot(), null);
    f.storage.token = ''; store.tick();
    const count = f.requests.length; f.advance(60000); store.tick();
    assert.equal(f.requests.length, count);
    stop();
});

test('a failed refresh clears previous green evidence rather than displaying cached success', async () => {
    const f = fixture(), store = f.context.imnPortStore;
    const stop = store.subscribe(() => {});
    await f.respond(f.requests[0], f.report([f.entry()]));
    assert.equal(f.result(f.row(), store.snapshot()).state, 'open');
    f.advance(20001); store.tick();
    await f.respond(f.requests[1], { message: 'Forbidden' }, false);
    assert.equal(store.snapshot(), null);
    assert.equal(f.result(f.row(), store.snapshot()).state, 'unknown');
    stop();
});

test('the column follows Address and badge text accompanies the green/red light in both locales', () => {
    const f = fixture(), columns = [{ key: 'name' }, { key: 'host' }, { key: 'online' }];
    const output = f.context.imnPortColumns(columns, []);
    assert.equal(output[2].key, 'node_port_status');
    assert.equal(columns.length, 3);
    f.context.imnPortStore.session = f.context.imnPortStore.identity();
    f.context.imnPortStore.report = f.report([f.entry()]);
    const Badge = vm.runInContext('ImnNodePortBadge', f.context);
    let badge = new Badge({ row: f.row(), nodes: [] }).render();
    assert.equal(badge.props['data-node-port-state'], 'open');
    assert.equal(badge.props['aria-label'], 'Port status: Open');
    assert.equal(badge.children[0].props.style.backgroundColor, '#52c41a');
    f.storage.locale = 'fa'; f.context.imnPortStore.report = f.report([f.entry('vless#7', 'tcp', false)]);
    badge = new Badge({ row: f.row(), nodes: [], caption: true }).render();
    assert.equal(badge.props['aria-label'], 'وضعیت پورت: بسته');
    assert.equal(badge.children[0].props.style.backgroundColor, '#f5222d');
});

function endpointReport(f, results, local = {}) {
    return { ...f.report([]), ...local, reachability: { ok: true, updated_at: new Date(f.now()).toISOString(), results } };
}
function endpoint(row, state = 'open', reason = 'connected') {
    return { node: row.type + '#' + row.id, host: row.host, port: String(row.port), transport: 'tcp', state, reason, updated_at: row.updated_at || 0 };
}
test('empty local firewall results no longer make every remote node unknown', () => {
    const f = fixture(), open = f.row(), closed = f.row('anytls', 4, { host: 'other.example.test', port: 2087 });
    const report = endpointReport(f, [endpoint(open), endpoint(closed, 'closed', 'refused')]);
    assert.equal(f.result(open, report).state, 'open');
    assert.equal(f.result(open, report).label, 'Open (TCP)');
    assert.equal(f.result(closed, report).state, 'closed');
    assert.equal(f.result(open, { ...report, ok: false }).state, 'open', 'firewall failure is independent of observations');
    assert.match(f.result(open, report).detail, /node\.example\.test:443/);
    assert.doesNotMatch(f.result(open, report).detail, /2053/);
});
test('remote relay status measures its own connection address instead of its parent service', () => {
    const f = fixture(), parent = f.row(), child = f.row('vless', 9, { host: 'relay.example.test', port: 8443, parent_id: 7 });
    const report = endpointReport(f, [endpoint(parent), endpoint(child, 'unreachable', 'timeout')]);
    assert.equal(f.result(parent, report, [parent, child]).state, 'open');
    assert.equal(f.result(child, report, [parent, child]).state, 'unreachable');
    assert.match(f.result(child, report).detail, /relay\.example\.test:8443/);
    assert.equal(f.result(child, endpointReport(f, [endpoint(parent)]), [parent, child]).state, 'unknown');
});
test('endpoint evidence cannot survive an address, port, edit or report freshness change', () => {
    const f = fixture(), row = f.row('vless', 7, { updated_at: f.now() / 1000 - 60 }), report = endpointReport(f, [endpoint(row)]);
    for (const changed of [{ ...row, host: 'moved.example.test' }, { ...row, port: 1443 }, { ...row, updated_at: row.updated_at + 1 }]) {
        assert.equal(f.result(changed, report).state, 'unknown');
    }
    const local = f.report([f.entry()]);
    for (const reachability of [null, {}, { ...report.reachability, ok: false },
        { ...report.reachability, updated_at: '2026-11-16T08:55:00Z' }]) {
        assert.equal(f.result(row, { ...local, reachability }).state, 'unknown', 'no fallback to old local success');
    }
});
test('UDP evidence: a QUIC or DNS answer is open, an ICMP refusal closed, silence no reply', () => {
    const f = fixture(), hy = f.row('hysteria'), mdns = f.row('mdns', 8);
    const udp = (row, state, reason) => ({ ...endpoint(row), transport: 'udp', state, reason });
    const open = f.result(hy, endpointReport(f, [udp(hy, 'open', 'quic_version_negotiation')]));
    assert.equal(open.state, 'open');
    assert.equal(open.label, 'Open (UDP)');
    assert.match(open.detail, /QUIC server/);
    const dns = f.result(mdns, endpointReport(f, [udp(mdns, 'open', 'dns_answer')]));
    assert.equal(dns.label, 'Open (UDP)');
    assert.match(dns.detail, /DNS server/);
    assert.equal(f.result(hy, endpointReport(f, [udp(hy, 'open', 'udp_reply')])).state, 'open');
    assert.equal(f.result(hy, endpointReport(f, [udp(hy, 'closed', 'refused')])).label, 'Closed (UDP)');
    const silent = f.result(hy, endpointReport(f, [udp(hy, 'unreachable', 'no_reply')]));
    assert.equal(silent.state, 'unreachable');
    assert.equal(silent.label, 'No reply (UDP)');
    // A UDP entry never takes a TCP-only reason as evidence.
    assert.equal(f.result(hy, endpointReport(f, [udp(hy, 'open', 'connected')])).label, 'UDP unverified');
    assert.equal(f.result(hy, endpointReport(f, [udp(hy, 'closed', 'timeout')])).label, 'UDP unverified');
});
test('TCP observations never claim UDP reachability or a closed port from a timeout', () => {
    const f = fixture(), udp = f.row('hysteria'), mixed = f.row('trusttunnel');
    assert.equal(f.result(udp, endpointReport(f, [endpoint(udp)])).state, 'unknown');
    assert.equal(f.result(mixed, endpointReport(f, [endpoint(mixed)])).label, 'Open (TCP)');
    assert.match(f.result(mixed, endpointReport(f, [endpoint(mixed)])).detail, /does not verify VPN operation or UDP/);
    assert.equal(f.result(mixed, endpointReport(f, [endpoint(mixed, 'closed', 'timeout')])).state, 'unknown');
    const mdns = f.row('mdns');
    assert.equal(f.result(mdns, endpointReport(f, [endpoint(mdns)])).state, 'unknown', 'MDNS is a UDP DNS tunnel');
    assert.equal(f.result(mdns, endpointReport(f, [{ ...endpoint(mdns), transport: 'udp', state: 'unknown', reason: 'udp_unverified' }])).label, 'UDP unverified');
});
