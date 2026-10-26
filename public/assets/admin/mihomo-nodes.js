/* Maintained editor beside the prebuilt SPA's native-node table. */
(function () {
    'use strict';
    if (window.__irboardMihomoNodes) return;
    window.__irboardMihomoNodes = true;
    var mounted = null, queued = false;
    function token() { return localStorage.getItem('authorization') || ''; }
    function locale() { return /^fa/i.test(localStorage.getItem('umi_locale') || 'fa') ? 'fa' : 'en'; }
    function secure() { var p = String((window.settings || {}).secure_path || ''); return /^[A-Za-z0-9_-]+$/.test(p) ? p : ''; }
    function onRoute() {
        var hash = location.hash.replace(/^#/, '').split('?')[0].replace(/\/$/, '');
        if (hash) return hash === '/server/manage';
        var p = location.pathname.replace(/\/$/, '');
        return p === '/server/manage' || (secure() && p === '/' + secure() + '/server/manage');
    }
    function live(s) { return mounted === s && !s.disposed && token() === s.token && locale() === s.lang && onRoute(); }
    function t(s, fa, en) { return s.lang === 'fa' ? fa : en; }
    function el(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }
    function add(parent, tag, text, cls) { var e = el(tag, text, cls); parent.appendChild(e); return e; }
    function button(s, parent, text, action, name) {
        var b = add(parent, 'button', text, 'imn-button'); b.type = 'button'; b.disabled = s.busy || s.blocked;
        if (name) b.dataset.action = name;
        b.addEventListener('click', action); return b;
    }
    async function api(s, path, body) {
        var response = await fetch('/api/v1/' + secure() + path, {
            method: body === undefined ? 'GET' : 'POST', signal: s.controller.signal,
            headers: { authorization: s.token, Accept: 'application/json', 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body)
        });
        var data = await response.json();
        if (!live(s)) throw new Error('stale');
        if (!response.ok) {
            if (response.status === 401 || response.status === 403) s.blocked = true;
            var error = data.message || t(s, 'درخواست ناموفق بود.', 'Request failed.');
            if (data.errors) error += '\n' + Object.keys(data.errors).map(function (key) { return key + ': ' + data.errors[key].join(' '); }).join('\n');
            throw new Error(error);
        }
        return data.data;
    }
    async function load(s) {
        s.busy = true; s.error = ''; render(s);
        try {
            var data = await Promise.all([api(s, '/server/mihomo/fetch'), api(s, '/server/group/fetch')]);
            if (!live(s)) return;
            if (!data[0] || !data[0].protocols || !Array.isArray(data[0].nodes) || !Array.isArray(data[1])) throw new Error(t(s, 'پاسخ سرور معتبر نیست.', 'Invalid server response.'));
            s.data = data[0]; s.groups = data[1];
        } catch (error) { if (live(s)) s.error = error.message; }
        finally { if (live(s)) { s.busy = false; render(s); } }
    }
    async function mutate(s, action, payload) {
        if (s.busy || s.blocked) return;
        s.busy = true; s.error = ''; render(s);
        try {
            await api(s, '/server/mihomo/' + action, payload);
            if (!live(s)) return;
            if (action === 'save') s.draft = null;
            s.busy = false; await load(s);
        } catch (error) { if (live(s)) { s.error = error.message; s.busy = false; render(s); } }
    }
    function edit(s, row) {
        if (s.draft && !window.confirm(t(s, 'تغییرات ذخیره‌نشده کنار گذاشته شود؟', 'Discard the unsaved draft?'))) return;
        var protocol = row ? row.protocol : Object.keys(s.data.protocols)[0];
        s.draft = {
            id: row ? row.id : null, name: row ? row.name : '', protocol: protocol,
            group_id: row ? row.group_id.map(String) : [], tags: row ? (row.tags || []).join(', ') : '',
            sort: row ? row.sort : 0, show: row ? !!row.show : false,
            json: JSON.stringify(row ? row.outbound_settings : s.data.protocols[protocol].example, null, 2)
        };
        s.error = ''; render(s);
        s.root.querySelector('[name="name"]').focus();
    }
    function field(s, parent, text, name, type) {
        var label = add(parent, 'label', null, 'imn-field'); add(label, 'span', text);
        var input = add(label, type === 'textarea' ? 'textarea' : 'input'); input.name = name;
        if (type && type !== 'textarea') input.type = type;
        input.disabled = s.busy || s.blocked;
        return input;
    }
    function editor(s) {
        var d = s.draft, form = add(s.root, 'form', null, 'imn-editor');
        add(form, 'h3', t(s, d.id ? 'ویرایش نود Mihomo' : 'نود جدید Mihomo', d.id ? 'Edit Mihomo node' : 'New Mihomo node'));
        var grid = add(form, 'div', null, 'imn-grid');
        var name = field(s, grid, t(s, 'نام نود', 'Node name'), 'name', 'text'); name.required = true; name.maxLength = 255; name.value = d.name;
        name.oninput = function () { d.name = name.value; };
        var pl = add(grid, 'label', null, 'imn-field'); add(pl, 'span', t(s, 'پروتکل', 'Protocol'));
        var protocol = add(pl, 'select'); protocol.name = 'protocol'; protocol.disabled = s.busy || s.blocked;
        Object.keys(s.data.protocols).forEach(function (key) { var o = add(protocol, 'option', s.data.protocols[key].label); o.value = key; }); protocol.value = d.protocol;
        protocol.onchange = function () {
            if (!window.confirm(t(s, 'تنظیمات اختصاصی با نمونهٔ پروتکل جدید جایگزین شود؟', 'Replace the settings with the new protocol example?'))) { protocol.value = d.protocol; return; }
            d.protocol = protocol.value; d.json = JSON.stringify(s.data.protocols[d.protocol].example, null, 2); render(s);
        };
        var gl = add(grid, 'label', null, 'imn-field'); add(gl, 'span', t(s, 'گروه‌های دسترسی', 'Access groups'));
        var groups = add(gl, 'select'); groups.name = 'group_id'; groups.multiple = true; groups.required = true; groups.disabled = s.busy || s.blocked;
        s.groups.forEach(function (g) { var o = add(groups, 'option', g.name); o.value = String(g.id); o.selected = d.group_id.indexOf(String(g.id)) >= 0; });
        groups.onchange = function () { d.group_id = Array.from(groups.selectedOptions).map(function (o) { return o.value; }); };
        var tags = field(s, grid, t(s, 'برچسب‌ها (با کاما جدا کنید)', 'Tags (comma separated)'), 'tags', 'text'); tags.value = d.tags;
        tags.oninput = function () { d.tags = tags.value; };
        var sort = field(s, grid, t(s, 'ترتیب', 'Sort order'), 'sort', 'number'); sort.min = 0; sort.max = 2147483647; sort.required = true; sort.value = d.sort;
        sort.oninput = function () { d.sort = sort.value; };
        var show = field(s, grid, t(s, 'نمایش در سابسکریپشن', 'Include in subscriptions'), 'show', 'checkbox'); show.checked = d.show;
        show.onchange = function () { d.show = show.checked; };
        var json = field(s, form, t(s, 'تنظیمات Mihomo (JSON)', 'Mihomo settings (JSON)'), 'outbound_settings', 'textarea');
        json.className = 'imn-json'; json.dir = 'ltr'; json.spellcheck = false; json.required = true; json.value = d.json; json.maxLength = 131072;
        json.oninput = function () { d.json = json.value; };
        add(form, 'p', t(s, 'گزینه‌های تو در تو حفظ می‌شوند. name و type از نام و پروتکل بالا گرفته می‌شوند. {{uuid}} و {{user_id}} در مقادیر متنی برای هر مشترک جایگزین می‌شوند.',
            'Nested options are preserved. name and type come from the fields above. {{uuid}} and {{user_id}} in string values are replaced for each subscriber.'), 'imn-note');
        var actions = add(form, 'div', null, 'imn-actions');
        var save = add(actions, 'button', t(s, 'ذخیره نود', 'Save node'), 'imn-button imn-primary'); save.type = 'submit'; save.dataset.action = 'save'; save.disabled = s.busy || s.blocked;
        button(s, actions, t(s, 'بستن ویرایشگر', 'Close editor'), function () {
            if (!window.confirm(t(s, 'تغییرات ذخیره‌نشده کنار گذاشته شود؟', 'Discard the unsaved draft?'))) return;
            s.draft = null; s.error = ''; render(s);
        }, 'cancel');
        form.onsubmit = function (event) {
            event.preventDefault(); if (s.busy || s.blocked) return;
            var settings;
            try { settings = JSON.parse(d.json); if (!settings || Array.isArray(settings) || typeof settings !== 'object') throw new Error(); }
            catch (_) { s.error = t(s, 'تنظیمات باید یک شیء JSON معتبر باشد.', 'Settings must be a valid JSON object.'); render(s); return; }
            mutate(s, 'save', { id: d.id, name: d.name, protocol: d.protocol, group_id: d.group_id, tags: d.tags.split(/[,،]/).map(function (v) { return v.trim(); }).filter(Boolean), sort: Number(d.sort), show: d.show, outbound_settings: settings });
        };
    }
    function render(s) {
        s.root.replaceChildren(); s.root.dir = s.lang === 'fa' ? 'rtl' : 'ltr'; s.root.setAttribute('aria-busy', String(s.busy));
        var heading = add(s.root, 'div', null, 'imn-heading'); add(heading, 'h2', t(s, 'نودهای Mihomo', 'Mihomo nodes'));
        var actions = add(heading, 'div', null, 'imn-actions');
        button(s, actions, t(s, 'دریافت دوباره', 'Reload'), function () { load(s); }, 'reload');
        if (s.data) button(s, actions, t(s, 'افزودن نود Mihomo', 'Add Mihomo node'), function () { edit(s, null); }, 'add');
        add(s.root, 'p', t(s, 'پروفایل اتصال به سرویس‌های مستقل، برای Mihomo 1.19.33 و نسخه‌های سازگار. این بخش سرویس سرور نصب نمی‌کند و گزارش مصرف یا محدودیت اتصال سمت سرور ندارد.',
            'Client profiles for independently provisioned services, for Mihomo 1.19.33 and compatible versions. This editor does not install servers, collect traffic or enforce server connection limits.'), 'imn-note');
        if (s.error) { var error = add(s.root, 'p', s.error, 'imn-error'); error.setAttribute('role', 'alert'); }
        if (s.busy) { var status = add(s.root, 'p', t(s, 'در حال انجام…', 'Working…')); status.setAttribute('role', 'status'); }
        if (!s.data) return;
        if (!s.data.nodes.length) add(s.root, 'p', t(s, 'هنوز نود Mihomo ثبت نشده است.', 'No Mihomo nodes yet.'));
        else {
            var wrap = add(s.root, 'div', null, 'imn-table-wrap'), table = add(wrap, 'table', null, 'imn-table'), head = add(table, 'thead'), hr = add(head, 'tr');
            [t(s, 'نام', 'Name'), t(s, 'پروتکل', 'Protocol'), t(s, 'گروه‌ها', 'Groups'), t(s, 'ترتیب', 'Order'), t(s, 'نمایش', 'Visible'), t(s, 'عملیات', 'Actions')].forEach(function (v) { add(hr, 'th', v); });
            var body = add(table, 'tbody');
            s.data.nodes.forEach(function (row) {
                var tr = add(body, 'tr'); tr.dataset.node = row.id;
                add(tr, 'td', row.name); add(tr, 'td', (s.data.protocols[row.protocol] || {}).label || row.protocol);
                add(tr, 'td', row.group_id.map(function (id) { var group = s.groups.find(function (g) { return String(g.id) === String(id); }); return group ? group.name : id; }).join(', '));
                add(tr, 'td', row.sort); add(tr, 'td', row.show ? t(s, 'بله', 'Yes') : t(s, 'خیر', 'No'));
                var buttons = add(tr, 'td', null, 'imn-row-actions');
                button(s, buttons, t(s, 'ویرایش', 'Edit'), function () { edit(s, row); }, 'edit');
                button(s, buttons, row.show ? t(s, 'مخفی', 'Hide') : t(s, 'نمایش', 'Show'), function () { mutate(s, 'update', { id: row.id, show: !row.show }); }, 'toggle');
                button(s, buttons, t(s, 'کپی مخفی', 'Copy hidden'), function () { mutate(s, 'copy', { id: row.id }); }, 'copy');
                button(s, buttons, t(s, 'حذف', 'Delete'), function () { if (window.confirm(t(s, 'این نود حذف شود؟', 'Delete this node?'))) mutate(s, 'drop', { id: row.id }); }, 'drop');
            });
        }
        if (s.draft) editor(s);
    }
    function dispose(s) { s.disposed = true; s.controller.abort(); s.draft = null; s.data = null; s.root.replaceChildren(); s.root.remove(); mounted = null; }
    function scan() {
        queued = false;
        if (mounted && (!onRoute() || token() !== mounted.token || locale() !== mounted.lang)) dispose(mounted);
        if (!onRoute() || !secure() || !token()) return;
        var parent = document.getElementById('main-container') || document.querySelector('.ant-layout-content');
        if (!parent || !parent.querySelector('.ant-table')) return;
        if (mounted) { if (!mounted.root.isConnected) parent.appendChild(mounted.root); return; }
        var root = el('section', null, 'imn-panel'); root.id = 'irboard-mihomo-nodes';
        var s = { root: root, token: token(), lang: locale(), controller: new AbortController(), data: null, groups: [], draft: null, busy: false, blocked: false, error: '', disposed: false };
        mounted = s; parent.appendChild(root); load(s);
    }
    function schedule() { if (!queued) { queued = true; queueMicrotask(scan); } }
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    ['hashchange', 'popstate', 'storage', 'visibilitychange'].forEach(function (event) { window.addEventListener(event, schedule); });
    setInterval(schedule, 500); schedule();
}());
