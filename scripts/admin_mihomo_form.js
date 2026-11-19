/* Native React node editor. Uses the same Umi/DVA imports, Ant Design controls,
 * server drawer and action footer as the existing node editors. */
var imnProtocolNames = {
    ssr: 'ShadowsocksR', socks5: 'SOCKS5', http: 'HTTP / HTTPS', snell: 'Snell',
    wireguard: 'WireGuard / AmneziaWG', shadowquic: 'ShadowQUIC', gostrelay: 'GOST Relay',
    ssh: 'SSH', mieru: 'Mieru', sudoku: 'Sudoku', masque: 'MASQUE', openvpn: 'OpenVPN',
    tailscale: 'Tailscale / Headscale', zerotier: 'ZeroTier', easytier: 'EasyTier'
};
// Ant Design's Modal, already in the bundle; this module did not import it.
var imnModal = (n('2qtc'), n('kLXV'))['a'];
function imnToken() { return localStorage.getItem('authorization') || ''; }
function imnScope() { return location.pathname + '#' + location.hash; }
function imnText(fa, en) { return /^fa/i.test(localStorage.getItem('umi_locale') || 'fa') ? fa : en; }
function imnLocale(value, fallback) {
    if (typeof value === 'string') return value;
    return value ? imnText(value.fa || value.en || fallback, value.en || value.fa || fallback) : (fallback || '');
}
function imnCopy(value) { return JSON.parse(JSON.stringify(value)); }
async function imnApi(path, body, session, controller, whole) {
    var secure = String((window.settings || {}).secure_path || '');
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!/^[A-Za-z0-9_-]+$/.test(secure) || !session.token) throw new Error(imnText('نشست مدیریت معتبر نیست.', 'An admin session is required.'));
    var response = await fetch('/api/v1/' + secure + '/server/' + path, {
        method: body === undefined ? 'GET' : 'POST',
        headers: {authorization: session.token, Accept: 'application/json', 'Content-Type': 'application/json'},
        signal: controller ? controller.signal : undefined,
        body: body === undefined ? undefined : JSON.stringify(body)
    });
    var data = await response.json();
    if (session.token !== imnToken() || session.scope !== imnScope()) throw new Error('stale');
    if (!response.ok) {
        var message = data.message || imnText('درخواست ناموفق بود.', 'Request failed.');
        if (data.errors) message += '\n' + Object.keys(data.errors).map(key => data.errors[key].join(' ')).join('\n');
        var error = new Error(message); error.fields = data.errors || {}; throw error;
    }
    return whole ? data : data.data;
}
async function imnMutation(owner, type, action, body) {
    if (owner.imnBusy) return;
    if (action === 'drop' && !window.confirm(imnText('این نود حذف شود؟', 'Delete this node?'))) return;
    var session = {token: imnToken(), scope: imnScope()}; owner.imnBusy = true;
    try { await imnApi(type + '/' + action, body, session); owner.props.dispatch({type: 'serverManage/getNodes'}); }
    catch (error) { if (session.token === imnToken() && session.scope === imnScope() && error.message !== 'stale') c['a'].error(error.message); }
    finally { owner.imnBusy = false; }
}
function imnAddress(row) {
    if (row.host) return String(row.host) + (row.port ? ':' + row.port : row.port_range ? ':' + row.port_range : '');
    return row.network || row.hostname || row.network_name || imnProtocolNames[row.type] || '';
}
class MihomoNodeDrawer extends y.a.Component {
    constructor(props) {
        super(props);
        this.state = {visible: false, busy: false, error: '', errors: {}, server: null, catalog: null, drafts: {}, advanced: false};
        this.session = null; this.controller = null; this.alive = true;
    }
    componentWillUnmount() {
        this.alive = false; clearInterval(this.timer);
        if (this.controller) this.controller.abort(); this.session = null;
    }
    current(session) { return this.alive && this.session === session && session.token === imnToken() && session.scope === imnScope(); }
    close() {
        clearInterval(this.timer); if (this.controller) this.controller.abort(); this.session = null;
        if (this.alive) this.setState({visible: false, busy: false, error: '', errors: {}, server: null, catalog: null, drafts: {}, advanced: false});
    }
    async open() {
        if (this.controller) this.controller.abort();
        var session = {token: imnToken(), scope: imnScope()}; this.session = session; this.controller = new AbortController();
        clearInterval(this.timer); this.timer = setInterval(() => { if (!this.current(session)) this.close(); }, 250);
        this.setState({visible: true, busy: true, error: '', errors: {}, server: null, catalog: null, drafts: {}, advanced: false});
        try {
            var data = await imnApi('protocols', undefined, session, this.controller); if (!this.current(session)) return;
            var type = this.props.record ? this.props.record.type : this.props.protocol;
            var definition = data && data.protocols && data.protocols[type];
            if (!definition) throw new Error(imnText('پروتکل در این نسخه موجود نیست.', 'This protocol is unavailable.'));
            // Examples explain a format; only the audited create defaults become values.
            // Never reapply them when editing an existing row (including false/null).
            var server = this.props.record ? imnCopy(this.props.record) : Object.assign({
                name: '', host: '', port: null, server_port: null, rate: 1,
                group_id: [], route_id: [], tags: [], parent_id: null, show: false, sort: 0, managed: false
            }, imnCopy((definition.form || {}).defaults || {}));
            server.type = type;
            server.group_id = (server.group_id || []).map(String); server.route_id = (server.route_id || []).map(String);
            var advanced = !!this.props.record && Object.keys(definition.fields).some(column =>
                this.sectionOf(column, server, definition) === 'advanced' && server[column] != null);
            this.setState({catalog: data.protocols, server: server, drafts: {}, busy: false, advanced: advanced});
        } catch (error) { if (this.current(session)) this.setState({busy: false, error: error.message}); }
    }
    definition() { return this.state.catalog[this.state.server.type]; }
    // Only a managed root node runs a V2bX listener; external services and relays have no service port.
    listens(server) { return !!(this.definition().managed_server && !server.parent_id && server.managed); }
    // On a node V2bX manages, the per-user credentials and the Sudoku key are
    // generated, so they wait under the advanced settings.
    sectionOf(column, server, definition) {
        var field = (definition || this.definition()).fields[column];
        if ((field.managed_credential || field.managed_auto) && server.managed && !server.parent_id) return 'advanced';
        return field.section || 'advanced';
    }
    source(server) {
        var source = server, seen = new Set();
        while (source.parent_id && !seen.has(Number(source.parent_id))) {
            seen.add(Number(source.parent_id));
            var parent = this.props.nodes.find(row => row.type === server.type && Number(row.id) === Number(source.parent_id));
            if (!parent) break; source = parent;
        }
        return source;
    }
    common(key, value) {
        this.setState(state => {
            var server = Object.assign({}, state.server, {[key]: value}), errors = Object.assign({}, state.errors);
            delete errors[key];
            if (server.type === 'mieru' && this.source(server).port_range) server.port = null;
            return {server: server, errors: errors, error: ''};
        });
    }
    json(column, text) {
        this.setState(state => {
            var server = Object.assign({}, state.server), errors = Object.assign({}, state.errors);
            try {
                var parsed = text.trim() ? JSON.parse(text) : null;
                if (parsed !== null && (typeof parsed !== 'object')) throw new Error('shape');
                server[column] = parsed; delete errors[column];
            } catch (_) { errors[column] = imnText('یک آرایه یا شیء JSON معتبر وارد کنید.', 'Enter a valid JSON array or object.'); }
            return {server: server, errors: errors, drafts: Object.assign({}, state.drafts, {[column]: text}), error: ''};
        });
    }
    guide(key) { return (((this.definition().form || {}).common || {})[key] || this.definition().fields[key] || {}); }
    label(key) { return imnLocale(this.guide(key).label, key); }
    field(key, control, options) {
        var guide = this.guide(key), error = this.state.errors[key], help = imnLocale(guide.help);
        var id = 'imn-' + this.state.server.type + '-' + key, opts = options || {};
        var required = opts.required || (guide.required && !this.state.server.parent_id);
        return y.a.createElement('div', {key: key, className: 'form-group' + (opts.column ? ' ' + opts.column : '') + (error ? ' has-error' : ''), 'data-mihomo-field': key},
            y.a.createElement('label', {htmlFor: id}, this.label(key), required && y.a.createElement('span', {'aria-hidden': true, style: {color: '#f5222d'}}, ' *')),
            control,
            help && y.a.createElement('small', {id: id + '-help', className: 'form-text text-muted', 'data-mihomo-help': key, dir: imnText('rtl', 'ltr'), style: {display: 'block', lineHeight: 1.7, overflowWrap: 'anywhere', textAlign: imnText('right', 'left')}}, help),
            error && y.a.createElement('div', {id: id + '-error', role: 'alert', 'data-mihomo-error': key, style: {color: '#f5222d', marginTop: 4}}, error));
    }
    controlProps(key) {
        return {id: 'imn-' + this.state.server.type + '-' + key, name: key, disabled: this.state.busy,
            'aria-label': this.label(key), 'aria-invalid': !!this.state.errors[key],
            'aria-describedby': 'imn-' + this.state.server.type + '-' + key + '-help' + (this.state.errors[key] ? ' imn-' + this.state.server.type + '-' + key + '-error' : '')};
    }
    input(key, options) {
        var guide = this.guide(key), props = Object.assign({}, this.controlProps(key), {
            value: this.state.server[key] == null ? '' : this.state.server[key],
            placeholder: imnLocale(guide.placeholder), autoComplete: 'off', dir: key === 'name' ? imnText('rtl', 'ltr') : 'ltr',
            onChange: event => this.common(key, event.target.value)
        }, options || {});
        return y.a.createElement(s['a'], props);
    }
    option(value, label) { return y.a.createElement(N['a'].Option, {key: String(value), value: value, 'data-mihomo-option': String(value)}, label); }
    select(key, choices, options) {
        var props = Object.assign({}, this.controlProps(key), {
            value: this.state.server[key] == null ? '' : this.state.server[key], style: {width: '100%'},
            placeholder: imnLocale(this.guide(key).placeholder), onChange: value => this.common(key, value)
        }, options || {});
        return y.a.createElement(N['a'], props, choices.map(choice => this.option(choice.value, imnLocale(choice.label, String(choice.value)))));
    }
    toggle(key) {
        return y.a.createElement('div', null, y.a.createElement(f['a'], Object.assign({}, this.controlProps(key), {
            checked: !!this.state.server[key], checkedChildren: imnText('فعال', 'On'), unCheckedChildren: imnText('غیرفعال', 'Off'),
            onChange: value => this.common(key, value)
        })));
    }
    protocolField(column) {
        var field = this.definition().fields[column], value = this.state.server[column], control;
        if (field.kind === 'bool') {
            control = this.select(column, [
                {value: '', label: imnText('پیش‌فرض پروتکل', 'Protocol default')},
                {value: 'true', label: imnText('فعال', 'Enabled')}, {value: 'false', label: imnText('غیرفعال', 'Disabled')}
            ], {value: value == null ? '' : value ? 'true' : 'false', onChange: choice => this.common(column, choice === '' ? null : choice === 'true')});
        } else if (field.choices && field.choices.length) {
            var choices = field.choices.slice();
            if (!field.required) choices.unshift({value: '', label: imnText('پیش‌فرض پروتکل', 'Protocol default')});
            if (value != null && value !== '' && !choices.some(choice => choice.value === value)) choices.push({value: value, label: String(value)});
            control = this.select(column, choices, {onChange: choice => this.common(column, choice === '' ? null : field.kind === 'int' ? Number(choice) : choice)});
        } else if (field.kind === 'json' || /^(ca|cert|key|private_key|certificate|tls_auth|tls_crypt|tls_crypt_v2)$/.test(column)) {
            control = y.a.createElement(s['a'].TextArea, Object.assign({}, this.controlProps(column), {
                rows: 3, dir: 'ltr', autoComplete: 'off', spellCheck: false, placeholder: imnLocale(field.placeholder),
                value: field.kind === 'json' ? (Object.prototype.hasOwnProperty.call(this.state.drafts, column) ? this.state.drafts[column] : value == null ? '' : JSON.stringify(value, null, 2)) : (value || ''),
                onChange: event => field.kind === 'json' ? this.json(column, event.target.value) : this.common(column, event.target.value)
            }));
        } else {
            control = this.input(column, {type: field.kind === 'int' ? 'number' : /password|secret|psk|auth_key|passphrase/.test(column) ? 'password' : 'text',
                dir: 'ltr', min: field.min, max: field.max, step: field.kind === 'int' ? 1 : undefined,
                onChange: event => this.common(column, field.kind === 'int' ? (event.target.value === '' ? null : Number(event.target.value)) : event.target.value)});
        }
        return this.field(column, control, {required: field.required || (field.required_external && !this.state.server.managed)});
    }
    parents() {
        var server = this.state.server, rows = this.props.nodes.filter(row => row.type === server.type), blocked = new Set([Number(server.id)]);
        var changed = true;
        while (changed) { changed = false; rows.forEach(row => { if (blocked.has(Number(row.parent_id)) && !blocked.has(Number(row.id))) { blocked.add(Number(row.id)); changed = true; } }); }
        return rows.filter(row => !blocked.has(Number(row.id)));
    }
    section(key, title, help, content) {
        return y.a.createElement('section', {key: key, 'data-mihomo-section': key, style: {marginBottom: 20}},
            y.a.createElement('h4', {style: {fontSize: 14, marginBottom: 6}}, title),
            help && y.a.createElement('p', {className: 'text-muted', 'data-mihomo-section-help': key, dir: imnText('rtl', 'ltr'), style: {fontSize: 12, lineHeight: 1.7, marginBottom: 12, textAlign: imnText('right', 'left')}}, help), content);
    }
    protocolSection(key) {
        var definition = this.definition(), server = this.state.server, columns = Object.keys(definition.fields).filter(column => this.sectionOf(column, server) === key);
        if (!columns.length || server.parent_id) return null;
        var section = ((definition.form || {}).sections || []).find(section => section.key === key) || {};
        var help = this.listens(server) && section.managed_help ? section.managed_help : section.help;
        return this.section(key, imnLocale(section.label, imnText('تنظیمات پروتکل', 'Protocol settings')), imnLocale(help), columns.map(column => this.protocolField(column)));
    }
    validate() {
        var server = this.state.server, definition = this.definition(), effective = this.source(server), errors = {};
        var required = imnText('این بخش را تکمیل کنید.', 'Complete this field.');
        if (!String(server.name || '').trim()) errors.name = required;
        if (!server.group_id || !server.group_id.length) errors.group_id = imnText('حداقل یک گروه دسترسی انتخاب کنید.', 'Select at least one access group.');
        if (server.rate === '' || server.rate == null || !Number.isFinite(Number(server.rate)) || Number(server.rate) < 0 || Number(server.rate) > 99999999.99) errors.rate = imnText('ضریب باید عددی بین ۰ و ۹۹۹۹۹۹۹۹٫۹۹ باشد.', 'Enter a rate between 0 and 99999999.99.');
        if (!Number.isInteger(Number(server.sort)) || Number(server.sort) < 0 || Number(server.sort) > 2147483647) errors.sort = imnText('ترتیب باید یک عدد صحیح نامنفی باشد.', 'Enter a non-negative integer sort order.');
        var overlay = ['tailscale', 'zerotier', 'easytier'].indexOf(server.type) >= 0 || (server.type === 'wireguard' && effective.peers && effective.peers.length);
        if (!overlay && !String(server.host || '').trim()) errors.host = required;
        if (!overlay && server.host && /[\s\x00-\x1f/]/.test(server.host)) errors.host = imnText('فقط دامنه یا IP وارد کنید؛ بدون https:// یا مسیر.', 'Enter only a domain or IP, without https:// or a path.');
        ['port', 'server_port'].forEach(key => {
            if (key === 'server_port' && !this.listens(server)) return;
            // A managed node listens on its connection port unless told otherwise.
            var needed = key === 'port' ? !overlay && !(server.type === 'mieru' && effective.port_range) : false;
            if ((server[key] == null || server[key] === '') && !needed) return;
            if (!Number.isInteger(Number(server[key])) || Number(server[key]) < 1 || Number(server[key]) > 65535) errors[key] = imnText('یک پورت معتبر بین ۱ و ۶۵۵۳۵ وارد کنید.', 'Enter a port from 1 to 65535.');
        });
        if (!server.parent_id) {
            Object.keys(definition.fields).forEach(column => {
                var field = definition.fields[column], value = server[column];
                if ((field.required || (field.required_external && !server.managed)) && (value == null || value === '')) errors[column] = required;
                if (field.kind === 'int' && value != null && value !== '' && (!Number.isInteger(Number(value)) || (field.min != null && value < field.min) || (field.max != null && value > field.max))) errors[column] = imnText('یک عدد صحیح در بازهٔ مشخص‌شده وارد کنید.', 'Enter an integer within the stated range.');
                if (field.managed_credential && server.managed && value) {
                    if ((!String(value).includes('{{uuid}}') && !String(value).includes('{{user_id}}')) || String(value).replace(/\{\{uuid\}\}|\{\{user_id\}\}/g, '').includes('{{')) errors[column] = imnText('برای کاربران مستقل خالی بگذارید یا از {{uuid}} استفاده کنید.', 'Leave blank for individual users or use {{uuid}}.');
                }
                if (Object.prototype.hasOwnProperty.call(this.state.drafts, column) && this.state.drafts[column].trim()) {
                    try { var parsed = JSON.parse(this.state.drafts[column]); if (!parsed || typeof parsed !== 'object') throw new Error('shape'); }
                    catch (_) { errors[column] = imnText('یک آرایه یا شیء JSON معتبر وارد کنید.', 'Enter a valid JSON array or object.'); }
                }
            });
            if (server.managed && definition.managed_server && !['username', 'password'].some(column => !server[column] || String(server[column]).includes('{{uuid}}'))) errors.password = imnText('شناسهٔ عددی کاربر قابل حدس است؛ نام کاربری یا رمز عبور باید {{uuid}} داشته باشد یا خالی بماند.', 'A user ID is guessable: the username or password must contain {{uuid}} or stay blank.');
            if (server.managed && server.type === 'mieru' && server.port_range) errors.port_range = imnText('برای نود مدیریت‌شده، بازه را خالی و یک پورت انتخاب کنید.', 'Managed nodes need one port; clear the port range.');
            if (server.type === 'ssh' && !server.password && !server.private_key) errors.password = imnText('رمز عبور یا کلید خصوصی SSH را وارد کنید.', 'Enter the SSH password or private key.');
            if (['wireguard', 'masque'].indexOf(server.type) >= 0 && server.network !== 'h3-l4proxy' && !server.ip && !server.ipv6) errors.ip = imnText('IPv4 یا IPv6 تخصیص‌یافتهٔ کلاینت را وارد کنید.', 'Enter the client IPv4 or IPv6 address assigned by the service.');
            if (server.type === 'openvpn' && !(server.username && server.password) && !(server.cert && server.key)) errors.username = imnText('نام کاربری و رمز، یا گواهی و کلید کلاینت را تکمیل کنید.', 'Provide username/password or a client certificate/key pair.');
        }
        return errors;
    }
    showErrors(errors, message) {
        var fields = this.definition().fields, first = Object.keys(errors)[0];
        this.setState({errors: errors, error: message || imnText('بخش‌های مشخص‌شده را اصلاح کنید.', 'Correct the highlighted fields.'), advanced: this.state.advanced || Object.keys(errors).some(key => fields[key] && this.sectionOf(key, this.state.server) === 'advanced')}, () => {
            var element = first && document.getElementById('imn-' + this.state.server.type + '-' + first);
            if (element) { element.scrollIntoView({block: 'center'}); element.focus(); }
        });
    }
    async save(event) {
        if (event) event.preventDefault();
        if (this.state.busy || !this.session) return;
        if (!this.current(this.session)) { this.close(); return; }
        var errors = this.validate(); if (Object.keys(errors).length) { this.showErrors(errors); return; }
        var server = this.state.server, definition = this.definition(), payload = {}, session = this.session;
        ['id', 'name', 'host', 'port', 'server_port', 'rate', 'group_id', 'route_id', 'tags', 'parent_id', 'show', 'sort'].concat(Object.keys(definition.fields)).forEach(key => {
            if (Object.prototype.hasOwnProperty.call(server, key)) payload[key] = server[key];
        });
        if (definition.managed_server) payload.managed = !!server.managed;
        ['port', 'server_port', 'parent_id'].forEach(key => { payload[key] = payload[key] === '' || payload[key] == null ? null : Number(payload[key]); });
        payload.host = payload.host || null; payload.rate = Number(payload.rate); payload.sort = Number(payload.sort); payload.show = !!payload.show;
        this.setState({busy: true, error: '', errors: {}});
        // Decided before close(), which takes the catalog away.
        var created = this.listens(server) && !server.id;
        try {
            var saved = await imnApi(server.type + '/save', payload, session, this.controller, true); if (!this.current(session)) return;
            this.props.dispatch({type: 'serverManage/getNodes'}); this.close();
            if (created && saved && saved.id) this.nextSteps(server, saved.id);
        } catch (error) {
            if (!this.current(session)) return;
            var mapped = {};
            Object.keys(error.fields || {}).forEach(key => {
                var base = key.split('.')[0], column = base === 'server' ? 'host' : Object.keys(definition.fields).find(column => definition.fields[column].option === base) || base;
                mapped[column] = error.fields[key].join(' ');
            });
            this.setState({busy: false}); this.showErrors(mapped, error.message);
        }
    }
    // A new node V2bX will run: say what is left to do, with the ID V2bX asks for.
    nextSteps(server, id) {
        var name = imnProtocolNames[server.type] || server.type, p = (text, extra) => y.a.createElement('p', Object.assign({dir: imnText('rtl', 'ltr'), style: {lineHeight: 1.8}}, extra || {}), text);
        imnModal.success({
            title: imnText('نود ' + name + ' با ID ' + id + ' ثبت شد', name + ' node saved with ID ' + id),
            okText: imnText('متوجه شدم', 'OK'),
            content: y.a.createElement('div', {'data-mihomo-next-steps': id},
                p(imnText('۱. روی سرور «v2bx config» را اجرا کنید و آدرس پنل، کلید ارتباط و ID ' + id + ' را بدهید؛ V2bX پروتکل و هسته را خودش تشخیص می‌دهد.', '1. On the server run "v2bx config" and give it this panel\'s address, the node key and ID ' + id + '; V2bX detects the protocol and core itself.')),
                p(imnText('۲. وقتی V2bX نود را اجرا کند وضعیتش آبی می‌شود و هر مشکلی زیر نامش نمایش داده می‌شود.', '2. The node turns blue once V2bX serves it, and any problem shows under its name.')),
                p(imnText('۳. سپس «نمایش در اشتراک» را روشن کنید.', '3. Then turn on Show in subscription.')))
        });
    }
    body() {
        var server = this.state.server; if (!server) return null;
        var definition = this.definition(), form = definition.form || {}, source = this.source(server);
        var overlay = ['tailscale', 'zerotier', 'easytier'].indexOf(server.type) >= 0 || (server.type === 'wireguard' && source.peers && source.peers.length);
        var advanced = Object.keys(definition.fields).some(column => this.sectionOf(column, server) === 'advanced');
        return y.a.createElement('form', {'data-mihomo-form': server.type, onSubmit: event => this.save(event), noValidate: true, style: {overflowWrap: 'anywhere'}},
            y.a.createElement('p', {className: 'text-muted', 'data-mihomo-description': true, dir: imnText('rtl', 'ltr'), style: {lineHeight: 1.8, textAlign: imnText('right', 'left')}}, imnLocale(this.listens(server) && form.managed_description ? form.managed_description : form.description)),
            this.section('general', imnText('مشخصات و دسترسی', 'Node details and access'), imnText('نام قابل‌نمایش و گروه کاربرانی را تعیین کنید که اجازهٔ استفاده از این نود دارند.', 'Choose the display name and the subscriber groups allowed to use this node.'), [
                y.a.createElement('div', {className: 'row', key: 'name-rate'}, this.field('name', this.input('name', {maxLength: 255}), {required: true, column: 'col-8'}), this.field('rate', this.input('rate', {type: 'number', min: 0, max: 99999999.99, step: '0.01', addonAfter: 'x', dir: 'ltr'}), {required: true, column: 'col-4'})),
                this.field('group_id', this.select('group_id', this.props.groups.map(group => ({value: String(group.id), label: group.name})), {mode: 'multiple', 'data-mihomo-groups': true}), {required: true}),
                this.field('tags', this.select('tags', [], {mode: 'tags', tokenSeparators: [',']}))
            ]),
            this.section('endpoint', imnText('اتصال و مدیریت نود', 'Connection and node management'), imnLocale(form.capability), [
                this.field('parent_id', this.select('parent_id', [{value: '', label: imnText('بدون والد', 'No parent')}].concat(this.parents().map(row => ({value: row.id, label: row.name + ' (#' + row.id + ')'}))), {value: server.parent_id || '', onChange: value => this.common('parent_id', value === '' ? null : Number(value))})),
                !server.parent_id && definition.managed_server && this.field('managed', this.toggle('managed')),
                !overlay && this.field('host', this.input('host', {dir: 'ltr'}), {required: true}),
                !overlay && y.a.createElement('div', {className: 'row', key: 'ports'},
                    this.field('port', this.input('port', {type: 'number', min: 1, max: 65535, dir: 'ltr', disabled: this.state.busy || !!source.port_range}), {column: 'col-6', required: !source.port_range}),
                    this.listens(server) && this.field('server_port', this.input('server_port', {type: 'number', min: 1, max: 65535, dir: 'ltr',
                        placeholder: server.port ? String(server.port) : imnText('همان پورت اتصال', 'Same as the connection port')}), {column: 'col-6'})),
                server.parent_id && y.a.createElement('p', {key: 'inherited', className: 'text-muted', 'data-mihomo-inherited': true}, imnText('تنظیمات پروتکل و حالت مدیریت از نود والد گرفته می‌شود: ', 'Protocol settings and management mode are inherited from: ') + (source.name || '') + ' (#' + source.id + ')')
            ]),
            this.protocolSection('connection'), this.protocolSection('credentials'), this.protocolSection('security'),
            !server.parent_id && advanced && y.a.createElement(y.a.Fragment, null,
                y.a.createElement(l['a'], {'data-mihomo-advanced': true, htmlType: 'button', 'aria-expanded': this.state.advanced, style: {marginBottom: 16}, onClick: () => this.setState({advanced: !this.state.advanced})}, imnText('تنظیمات پیشرفتهٔ پروتکل', 'Advanced protocol settings'), y.a.createElement(m['a'], {type: this.state.advanced ? 'up' : 'down'})),
                this.state.advanced && this.protocolSection('advanced')),
            this.section('publication', imnText('مسیریابی و نمایش', 'Routing and visibility'), imnText('مسیریابی، ترتیب فهرست و نمایش در اشتراک را پس از تکمیل تنظیمات بررسی کنید.', 'Review routing, list order and subscription visibility after configuring the connection.'), [
                this.field('route_id', this.select('route_id', this.props.routes.map(route => ({value: String(route.id), label: route.remarks || route.name || String(route.id)})), {mode: 'multiple'})),
                this.field('sort', this.input('sort', {type: 'number', min: 0, max: 2147483647, dir: 'ltr'})), this.field('show', this.toggle('show'))
            ]),
            y.a.createElement('div', {className: 'v2board-drawer-action'},
                y.a.createElement(l['a'], {htmlType: 'button', disabled: this.state.busy, style: {marginRight: 8}, onClick: () => this.close()}, imnText('انصراف', 'Cancel')),
                y.a.createElement(l['a'], {htmlType: 'submit', type: 'primary', loading: this.state.busy, disabled: this.state.busy, 'data-mihomo-save': true}, imnText('ثبت', 'Save'))));
    }
    render() {
        return y.a.createElement(y.a.Fragment, null,
            y.a.cloneElement(this.props.children, {onClick: event => { event.preventDefault(); this.open(); }}),
            y.a.createElement(R['a'], {id: 'server', visible: this.state.visible, width: window.innerWidth <= 768 ? '100%' : '80%', maskClosable: !this.state.busy,
                onClose: () => this.close(), title: (this.props.record ? imnText('ویرایش نود', 'Edit node') : imnText('نود جدید', 'New node')) + ' — ' + (imnProtocolNames[this.props.protocol || (this.props.record || {}).type] || ''), destroyOnClose: true},
                this.state.busy && !this.state.server && y.a.createElement('p', {role: 'status'}, imnText('در حال دریافت تنظیمات…', 'Loading settings…')),
                this.state.error && y.a.createElement('div', {role: 'alert', className: 'alert alert-danger', style: {whiteSpace: 'pre-wrap'}}, this.state.error), this.body()));
    }
}
var mMihomo = Object(_['c'])(state => ({groups: state.serverGroup.groups || [], routes: state.serverRoute.routes || [], nodes: state.serverManage.servers || []}))(MihomoNodeDrawer);

/*__NODE_PORT_SOURCE_START__*/
/* Native admin node-port column. Evidence is the panel server's firewall
 * report, never an assumed result of a remote TCP/UDP connectivity test. */
var imnPortMaxAge = 180000;
function imnPortTimestamp(value) {
    if (typeof value !== 'string') return NaN;
    var match = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d{1,3})?(Z|[+-]\d{2}:?\d{2})$/.exec(value);
    if (!match) return NaN;
    var offset = match[4];
    if (offset !== 'Z' && offset.indexOf(':') < 0) offset = offset.slice(0, 3) + ':' + offset.slice(3);
    return Date.parse(match[1] + 'T' + match[2] + (match[3] || '') + offset);
}
function imnPortSpec(row) {
    if (row.type === 'mieru') {
        var range = /^(\d{1,5})\s*-\s*(\d{1,5})$/.exec(String(row.port_range || '').trim());
        if (range && Number(range[1]) >= 1 && Number(range[2]) <= 65535 && Number(range[1]) <= Number(range[2])) {
            return Number(range[1]) === Number(range[2]) ? String(Number(range[1])) : Number(range[1]) + '-' + Number(range[2]);
        }
    }
    var numeric = value => value != null && String(value).trim() !== '' && Number.isFinite(Number(value));
    var value = numeric(row.server_port) ? row.server_port : row.port;
    var port = numeric(value) ? Math.trunc(Number(value)) : 0;
    return port >= 1 && port <= 65535 ? String(port) : null;
}
function imnPortProtocols(row) {
    if (row.type === 'mieru') {
        var transport = String(row.transport || '').trim().toUpperCase();
        return transport === 'TCP' ? ['tcp'] : transport === 'UDP' ? ['udp'] : ['tcp', 'udp'];
    }
    if (['hysteria', 'tuic', 'shadowquic', 'wireguard', 'masque'].includes(row.type)) return ['udp'];
    if (['vless', 'vmess', 'trojan', 'anytls', 'v2node', 'sudoku', 'ssh', 'http'].includes(row.type)) {
        return ['kcp', 'mkcp', 'quic'].includes(String(row.network || '').toLowerCase()) ? ['udp'] : ['tcp'];
    }
    return ['tcp', 'udp'];
}
function imnEndpointResult(row, report, now) {
    var unknown = detail => ({state: 'unknown', label: imnText('نامشخص', 'Unknown'), detail: detail});
    if (!report || report.ok !== true || !Array.isArray(report.results)) {
        return unknown(imnText('بررسی آدرس و پورت گره هنوز آماده نیست.', 'The node endpoint check is not available yet.'));
    }
    var checked = imnPortTimestamp(report.updated_at), age = now - checked;
    if (!Number.isFinite(checked) || age > imnPortMaxAge || age < -30000) {
        return unknown(imnText('نتیجه بررسی پورت قدیمی است؛ منتظر بررسی تازه هستیم.', 'The endpoint check is stale; waiting for a fresh result.'));
    }
    var normalizeHost = value => String(value || '').trim().replace(/^\[|\]$/g, '').toLowerCase();
    var host = normalizeHost(row.host), raw = row.type === 'mieru' && String(row.port_range || '').trim() ? row.port_range : row.port;
    var port = raw != null && String(raw).trim() !== '' && Number.isInteger(Number(raw)) && Number(raw) >= 1 && Number(raw) <= 65535 ?
        String(Number(raw)) : String(row.port_range || raw || '').trim().replace(/\s*-\s*/g, '-');
    var range = /^(\d{1,5})-(\d{1,5})$/.exec(port);
    if (range) port = Number(range[1]) === Number(range[2]) ? String(Number(range[1])) : Number(range[1]) + '-' + Number(range[2]);
    if (!host || !port) return unknown(imnText('آدرس و پورت اتصال این گره مشخص نیست.', 'This node has no valid connection address and port.'));
    // A relay has its OWN endpoint. Its parent's listener being up does not
    // make the relay address reachable, and service_port is not client port.
    var entry = report.results.find(item => item && item.node === row.type + '#' + row.id &&
        normalizeHost(item.host) === host && String(item.port) === port);
    if (!entry || (Number(row.updated_at) > 0 && Number(entry.updated_at) !== Number(row.updated_at))) {
        return unknown(imnText('برای آدرس فعلی این گره بررسی تازه‌ای نداریم.', 'No current check matches this node endpoint.'));
    }
    if (entry.reason === 'unsupported_range') return unknown(imnText('بازه پورت هنوز بررسی نشده است.', 'This port range has not been checked.'));
    if (entry.reason === 'budget_exceeded') return unknown(imnText('بررسی این گره در مهلت این نوبت کامل نشد.', 'This endpoint check did not complete within this batch deadline.'));
    if (entry.transport === 'udp') {
        // Only a protocol answer, or an ICMP refusal, is UDP evidence: QUIC
        // Version Negotiation (inside Hysteria's obfuscation), a DNS answer
        // from MDNS, or any datagram back. Silent protocols stay unverified.
        var udpOpen = entry.state === 'open' && ['quic_version_negotiation', 'dns_answer', 'udp_reply'].includes(entry.reason);
        var udpClosed = entry.state === 'closed' && entry.reason === 'refused';
        var udpSilent = entry.state === 'unreachable' && ['no_reply', 'dns_error', 'timeout'].includes(entry.reason);
        if (!udpOpen && !udpClosed && !udpSilent) {
            return {state: 'unknown', label: imnText('UDP بررسی نشده', 'UDP unverified'),
                detail: imnText('این پروتکل به بسته ناشناس پاسخ نمی‌دهد، پس باز بودن پورت UDP آن از سرور پنل قابل تأیید نیست.',
                    'This protocol does not answer unknown packets, so its UDP port cannot be verified from the panel server.')};
        }
        var udpAddress = (host.indexOf(':') >= 0 ? '[' + host + ']' : host) + ':' + port;
        var how = entry.reason === 'quic_version_negotiation' ? 'QUIC' : entry.reason === 'dns_answer' ? 'DNS' : 'UDP';
        var udpDetail = udpOpen ? imnText('سرور ' + how + ' روی ' + udpAddress + ' به سرور پنل پاسخ داد.',
                'The ' + how + ' server at ' + udpAddress + ' answered the panel server.') :
            udpClosed ? imnText('پورت UDP ' + udpAddress + ' رد شد؛ چیزی روی آن گوش نمی‌دهد.',
                'UDP port ' + udpAddress + ' was refused; nothing listens on it.') :
            imnText('از ' + udpAddress + ' پاسخی نیامد: گره خاموش است، پورت با فایروال بسته شده یا UDP در مسیر مسدود است.',
                'No answer from ' + udpAddress + ': the node is down, a firewall drops the port, or UDP is blocked on the way.');
        udpDetail += imnText(' این بررسی صحت اتصال کاربر یا VPN را تأیید نمی‌کند.', ' This does not verify user connections or the VPN.');
        udpDetail += '\n' + imnText('آخرین بررسی: ', 'Last checked: ') + new Date(checked).toLocaleString(imnText('fa-IR', 'en-US'));
        return {state: udpOpen ? 'open' : udpClosed ? 'closed' : 'unreachable',
            label: udpOpen ? imnText('باز (UDP)', 'Open (UDP)') : udpClosed ? imnText('بسته (UDP)', 'Closed (UDP)') :
                imnText('بدون پاسخ (UDP)', 'No reply (UDP)'), detail: udpDetail};
    }
    if (row.type === 'mdns' || !imnPortProtocols(row).includes('tcp')) {
        // A UDP-only protocol never takes a TCP result as its own.
        return {state: 'unknown', label: imnText('UDP بررسی نشده', 'UDP unverified'),
            detail: imnText('باز بودن UDP با اتصال TCP قابل تشخیص نیست.', 'UDP reachability cannot be shown by a TCP connection.')};
    }
    if (entry.transport !== 'tcp') return unknown(imnText('روش بررسی این پروتکل پشتیبانی نشده است.', 'This protocol has no supported endpoint check.'));
    var state = entry.state;
    if (!((state === 'open' && entry.reason === 'connected') || (state === 'closed' && entry.reason === 'refused') || state === 'unreachable')) {
        return unknown(imnText('نتیجه معتبری برای این پورت ثبت نشده است.', 'There is no verified result for this port.'));
    }
    var address = (host.indexOf(':') >= 0 ? '[' + host + ']' : host) + ':' + port;
    var detail = imnText('بررسی اتصال TCP از سرور پنل به ', 'TCP connection from the panel server to ') + address;
    detail += state === 'open' ? imnText(' برقرار شد.', ' succeeded.') : state === 'closed' ?
        imnText(' رد شد؛ پورت TCP بسته است.', ' was refused; the TCP port is closed.') :
        imnText(' موفق نشد؛ مسیر یا پورت از این سرور در دسترس نیست.', ' failed; the endpoint is unreachable from this server.');
    detail += imnText(' این بررسی صحت VPN یا دسترسی UDP را تأیید نمی‌کند.', ' This does not verify VPN operation or UDP reachability.');
    detail += '\n' + imnText('آخرین بررسی: ', 'Last checked: ') + new Date(checked).toLocaleString(imnText('fa-IR', 'en-US'));
    return {state: state, label: state === 'open' ? imnText('باز (TCP)', 'Open (TCP)') : state === 'closed' ?
        imnText('بسته (TCP)', 'Closed (TCP)') : imnText('در دسترس نیست', 'Unreachable'), detail: detail};
}
function imnPortResult(row, nodes, report, now) {
    if (report && Object.prototype.hasOwnProperty.call(report, 'reachability')) return imnEndpointResult(row, report.reachability, now);
    var unknown = reason => ({state: 'unknown', label: imnText('نامشخص', 'Unknown'), detail: reason});
    if (!report || report.ok !== true || !Array.isArray(report.ports)) {
        return unknown(imnText('گزارش معتبر وضعیت پورت در دسترس نیست.', 'A valid port-status report is unavailable.'));
    }
    var checked = imnPortTimestamp(report.updated_at), age = now - checked;
    if (!Number.isFinite(checked) || age > imnPortMaxAge || age < -30000) {
        return unknown(imnText('گزارش قدیمی یا نامعتبر است؛ منتظر بررسی تازه هستیم.', 'The report is stale or invalid; waiting for a fresh check.'));
    }
    var source = row, seen = new Set(), changed = Number(row.updated_at) || 0;
    while (source && source.parent_id) {
        var key = source.type + '#' + source.id;
        if (seen.has(key)) return unknown(imnText('گره اصلی مشخص نیست.', 'The parent node is unavailable.'));
        seen.add(key);
        source = nodes.find(item => item.type === source.type && String(item.id) === String(source.parent_id));
        if (source) changed = Math.max(changed, Number(source.updated_at) || 0);
    }
    if (!source) return unknown(imnText('گره اصلی مشخص نیست.', 'The parent node is unavailable.'));
    if (changed * 1000 > checked) {
        return unknown(imnText('تنظیمات گره تغییر کرده؛ منتظر بررسی تازه هستیم.', 'The node was edited; waiting for a fresh check.'));
    }
    var native = ['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls', 'v2node', 'mdns', 'trusttunnel'];
    if (!native.includes(source.type) && !source.managed) {
        return unknown(imnText('این گره سرویس خارجی است و گزارش فایروال محلی ندارد.', 'This external service has no local firewall report.'));
    }
    var port = imnPortSpec(source), ref = source.type + '#' + source.id;
    if (!port) return unknown(imnText('پورت سرویس این گره مشخص نیست.', 'This node has no valid service port.'));
    var protocols = imnPortProtocols(source);
    var entries = protocols.map(proto => report.ports.filter(item => item && String(item.port) === port && item.proto === proto &&
        Array.isArray(item.nodes) && item.nodes.includes(ref)));
    var blocked = entries.some(matches => matches.some(item => item.allowed === false));
    var complete = entries.every(matches => matches.length > 0 && matches.every(item => item.allowed === true));
    if (!blocked && !complete) {
        return unknown(imnText('برای این گره گزارشی از فایروال سرور پنل نداریم.', 'There is no panel-server firewall report for this node.'));
    }
    var detail = imnText('فایروال سرور پنل؛ پورت سرویس ', 'Panel-server firewall; service port ') + port + ' / ' + protocols.join('+').toUpperCase();
    if (source !== row) detail += imnText('؛ مربوط به گره اصلی', '; parent node listener');
    detail += imnText('؛ این وضعیت، اتصال از بیرون را تضمین نمی‌کند.', '; this does not confirm external connectivity.');
    detail += '\n' + imnText('آخرین بررسی: ', 'Last checked: ') + new Date(checked).toLocaleString(imnText('fa-IR', 'en-US'));
    return {state: blocked ? 'closed' : 'open', label: blocked ? imnText('بسته', 'Closed') : imnText('باز', 'Open'), detail: detail};
}

// All visible rows share a single request. No token or report crosses an
// admin session/navigation boundary, even if an old response arrives late.
var imnPortStore = {
    listeners: new Set(), report: null, session: null, request: null, timer: null, nextPoll: 0, expired: false,
    identity() { return {token: imnToken(), scope: imnScope()}; },
    current(session) { return this.session === session && session.token === imnToken() && session.scope === imnScope(); },
    emit() { this.listeners.forEach(listener => listener()); },
    snapshot() { return this.session && this.current(this.session) ? this.report : null; },
    clear() {
        if (this.request) { clearTimeout(this.request.timeout); this.request.controller.abort(); }
        this.request = null; this.report = null; this.session = null; this.nextPoll = 0; this.expired = false;
    },
    subscribe(listener) {
        this.listeners.add(listener);
        if (!this.timer) { this.tick(); this.timer = setInterval(() => this.tick(), 1000); }
        return () => {
            this.listeners.delete(listener);
            if (!this.listeners.size) { clearInterval(this.timer); this.timer = null; this.clear(); }
        };
    },
    tick() {
        var identity = this.identity(), secure = String((window.settings || {}).secure_path || '');
        if (this.session && !this.current(this.session)) { this.clear(); this.emit(); }
        if (!identity.token || !/^[A-Za-z0-9_-]+$/.test(secure)) return;
        var timestamp = this.report && Object.prototype.hasOwnProperty.call(this.report, 'reachability') ?
            (this.report.reachability || {}).updated_at : (this.report || {}).updated_at;
        if (this.report && !this.expired && Date.now() - imnPortTimestamp(timestamp) > imnPortMaxAge) {
            this.expired = true; this.emit();
        }
        if (!this.request && Date.now() >= this.nextPoll) this.refresh(identity, secure);
    },
    async refresh(identity, secure) {
        var session = this.session || identity; this.session = session;
        var controller = new AbortController();
        var request = {controller: controller, timeout: setTimeout(() => controller.abort(), 8000)};
        this.request = request; this.nextPoll = Date.now() + 20000;
        try {
            var response = await fetch('/api/v1/' + secure + '/system/getNodePorts', {
                headers: {authorization: session.token, Accept: 'application/json'}, signal: controller.signal
            });
            var payload = await response.json();
            if (!this.current(session)) return;
            if (!response.ok || !payload || !payload.data || typeof payload.data !== 'object') throw new Error('unavailable');
            this.report = payload.data; this.expired = false; this.emit();
        } catch (_) {
            if (this.current(session)) { this.report = null; this.expired = false; this.emit(); }
        } finally {
            clearTimeout(request.timeout);
            if (this.request === request) this.request = null;
        }
    }
};

class ImnNodePortBadge extends y.a.Component {
    constructor(props) { super(props); this.state = {revision: 0}; }
    componentDidMount() { this.unsubscribe = imnPortStore.subscribe(() => this.setState(state => ({revision: state.revision + 1}))); }
    componentWillUnmount() { if (this.unsubscribe) this.unsubscribe(); }
    render() {
        var result = imnPortResult(this.props.row, this.props.nodes || [], imnPortStore.snapshot(), Date.now());
        var palette = result.state === 'open' ? ['#f6ffed', '#b7eb8f', '#237804', '#52c41a'] :
            ['closed', 'unreachable'].includes(result.state) ? ['#fff1f0', '#ffa39e', '#a8071a', '#f5222d'] : ['#fafafa', '#d9d9d9', '#595959', '#8c8c8c'];
        var caption = imnText('وضعیت پورت', 'Port status');
        return y.a.createElement('span', {'data-node-port-state': result.state, 'data-node-port-ref': this.props.row.type + '#' + this.props.row.id,
            title: result.detail, role: 'status', 'aria-label': caption + ': ' + result.label,
            dir: imnText('rtl', 'ltr'), style: {display: 'inline-flex', alignItems: 'center', gap: 6, whiteSpace: 'nowrap',
                border: '1px solid ' + palette[1], borderRadius: 5, backgroundColor: palette[0], color: palette[2], padding: '3px 8px', fontSize: 12, lineHeight: 1.5}},
            y.a.createElement('span', {'aria-hidden': true, 'data-node-port-dot': true,
                style: {display: 'inline-block', width: 8, height: 8, borderRadius: '50%', backgroundColor: palette[3], flexShrink: 0}}),
            (this.props.caption ? caption + ': ' : '') + result.label);
    }
}
function imnPortColumns(columns, nodes) {
    var result = columns.slice(), address = result.findIndex(column => column.key === 'host');
    result.splice(address < 0 ? result.length : address + 1, 0, {
        title: imnText('وضعیت پورت', 'Port status'), key: 'node_port_status', width: 145,
        render: (_, row) => y.a.createElement(ImnNodePortBadge, {row: row, nodes: nodes})
    });
    return result;
}
/*__NODE_PORT_SOURCE_END__*/
