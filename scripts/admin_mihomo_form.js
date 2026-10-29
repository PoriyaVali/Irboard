/* Native React node editor. Uses the same Umi/DVA imports, Ant Design controls,
 * server drawer and action footer as the existing node editors. */
var imnProtocolNames = {
    ssr: 'ShadowsocksR', socks5: 'SOCKS5', http: 'HTTP / HTTPS', snell: 'Snell',
    wireguard: 'WireGuard / AmneziaWG', shadowquic: 'ShadowQUIC', gostrelay: 'GOST Relay',
    ssh: 'SSH', mieru: 'Mieru', sudoku: 'Sudoku', masque: 'MASQUE', openvpn: 'OpenVPN',
    tailscale: 'Tailscale / Headscale', zerotier: 'ZeroTier', easytier: 'EasyTier'
};
function imnToken() { return localStorage.getItem('authorization') || ''; }
function imnScope() { return location.pathname + '#' + location.hash; }
function imnText(fa, en) { return /^fa/i.test(localStorage.getItem('umi_locale') || 'fa') ? fa : en; }
function imnLocale(value, fallback) {
    if (typeof value === 'string') return value;
    return value ? imnText(value.fa || value.en || fallback, value.en || value.fa || fallback) : (fallback || '');
}
function imnCopy(value) { return JSON.parse(JSON.stringify(value)); }
async function imnApi(path, body, session, controller) {
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
    return data.data;
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
                definition.fields[column].section === 'advanced' && server[column] != null);
            this.setState({catalog: data.protocols, server: server, drafts: {}, busy: false, advanced: advanced});
        } catch (error) { if (this.current(session)) this.setState({busy: false, error: error.message}); }
    }
    definition() { return this.state.catalog[this.state.server.type]; }
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
        var definition = this.definition(), columns = Object.keys(definition.fields).filter(column => (definition.fields[column].section || 'advanced') === key);
        if (!columns.length || this.state.server.parent_id) return null;
        var section = ((definition.form || {}).sections || []).find(section => section.key === key) || {};
        return this.section(key, imnLocale(section.label, imnText('تنظیمات پروتکل', 'Protocol settings')), imnLocale(section.help), columns.map(column => this.protocolField(column)));
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
            var needed = key === 'port' ? !overlay && !(server.type === 'mieru' && effective.port_range) : !!effective.managed && !server.parent_id;
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
        this.setState({errors: errors, error: message || imnText('بخش‌های مشخص‌شده را اصلاح کنید.', 'Correct the highlighted fields.'), advanced: this.state.advanced || Object.keys(errors).some(key => fields[key] && fields[key].section === 'advanced')}, () => {
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
        try {
            await imnApi(server.type + '/save', payload, session, this.controller); if (!this.current(session)) return;
            this.props.dispatch({type: 'serverManage/getNodes'}); this.close();
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
    body() {
        var server = this.state.server; if (!server) return null;
        var definition = this.definition(), form = definition.form || {}, source = this.source(server);
        var overlay = ['tailscale', 'zerotier', 'easytier'].indexOf(server.type) >= 0 || (server.type === 'wireguard' && source.peers && source.peers.length);
        var advanced = Object.keys(definition.fields).some(column => (definition.fields[column].section || 'advanced') === 'advanced');
        return y.a.createElement('form', {'data-mihomo-form': server.type, onSubmit: event => this.save(event), noValidate: true, style: {overflowWrap: 'anywhere'}},
            y.a.createElement('p', {className: 'text-muted', 'data-mihomo-description': true, dir: imnText('rtl', 'ltr'), style: {lineHeight: 1.8, textAlign: imnText('right', 'left')}}, imnLocale(form.description)),
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
                    this.field('server_port', this.input('server_port', {type: 'number', min: 1, max: 65535, dir: 'ltr'}), {column: 'col-6', required: !!source.managed && !server.parent_id})),
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
