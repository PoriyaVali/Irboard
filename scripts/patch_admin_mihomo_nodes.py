#!/usr/bin/env python3
"""Add Mihomo protocols to the existing node menu/table in both built SPAs.

The maintained React drawer lives in admin_mihomo_form.js and uses imports from
that webpack module. Every edit is fenced and reversible; --check compares the
current bundle to the deterministic result, without rewriting unrelated patches.
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ADMIN = ROOT / 'public/assets/admin'
FORM = Path(__file__).with_name('admin_mihomo_form.js')


def changes():
    return [
        ('TAG_METHOD', '            getTypeTag(e, t) {\n                switch (e) {',
         '            getTypeTag(e, t, protocol) {\n                if (imnProtocolNames[e]) return y.a.createElement(g["a"], {color:"#5B6FC7"}, imnProtocolNames[e] + " " + t);\n                switch (e) {', 1),
        ('TABLE_TAG', 'this.getTypeTag(t.type, t.parent_id ? e + " => " + t.parent_id : e)',
         'this.getTypeTag(t.type, t.parent_id ? e + " => " + t.parent_id : e, t.protocol)', 2),
        ('MOBILE_TAG', 'this.getTypeTag(e.type, e.parent_id ? e.id + " => " + e.parent_id : e.id)',
         'this.getTypeTag(e.type, e.parent_id ? e.id + " => " + e.parent_id : e.id, e.protocol)', 1),
        ('COPY', '            copy(e) {\n                this.props.dispatch({',
         '            copy(e) {\n                if (e && imnProtocolNames[e.type]) { imnMutation(this, e.type, "copy", {id:e.id}); return; }\n                this.props.dispatch({', 1),
        ('UPDATE', '            update(e, t, n) {\n                this.props.dispatch({',
         '            update(e, t, n) {\n                if (e && imnProtocolNames[e.type]) { imnMutation(this, e.type, "update", {id:e.id,show:!!n}); return; }\n                this.props.dispatch({', 1),
        ('DROP', '            drop(e) {\n                this.props.dispatch({',
         '            drop(e) {\n                if (e && imnProtocolNames[e.type]) { imnMutation(this, e.type, "drop", {id:e.id}); return; }\n                this.props.dispatch({', 1),
        ('FILTERS', '''filters: ["V2node", "Shadowsocks", "Vmess", "Trojan", "Hysteria", "Tuic", "Vless", "AnyTLS", "Mdns"].map(e=>({
                        text: e,
                        value: e
                    })),
                    onFilter: (e,t)=>t.type === e.toLowerCase(),''', '''filters: ["V2node", "Shadowsocks", "Vmess", "Trojan", "Hysteria", "Tuic", "Vless", "AnyTLS", "Mdns"].map(e=>({
                        text: e,
                        value: e
                    })).concat(Object.keys(imnProtocolNames).map(key=>({text:imnProtocolNames[key],value:key}))),
                    onFilter: (e,t)=>t.type === e.toLowerCase(),''', 1),
        ('EDIT', '''"v2node" === e.type && y.a.createElement(mV2node, {
                        key: e.id,
                        record: e
                    }, y.a.createElement("a", null, y.a.createElement(m["a"], {
                        type: "edit"
                    }), " ویرایش")))''', '''"v2node" === e.type && y.a.createElement(mV2node, {
                        key: e.id,
                        record: e
                    }, y.a.createElement("a", null, y.a.createElement(m["a"], {
                        type: "edit"
                    }), " ویرایش")), imnProtocolNames[e.type] && y.a.createElement(mMihomo, {key:e.type+"-"+e.id,record:e}, y.a.createElement("a", {"data-mihomo-edit":e.id}, y.a.createElement(m["a"], {type:"edit"}), " ویرایش")))''', 1),
        ('CONTEXT_EDIT', '''"vless" === (null === (v = this.record) || void 0 === v ? void 0 : v.type) && y.a.createElement(G, {
                    key: Math.random(),
                    record: this.record
                }, y.a.createElement("a", null, y.a.createElement(m["a"], {
                    type: "form"
                }), " ویرایش")))''', '''"vless" === (null === (v = this.record) || void 0 === v ? void 0 : v.type) && y.a.createElement(G, {
                    key: Math.random(),
                    record: this.record
                }, y.a.createElement("a", null, y.a.createElement(m["a"], {
                    type: "form"
                }), " ویرایش")), this.record && imnProtocolNames[this.record.type] && y.a.createElement(mMihomo, {key:this.record.type+"-context-"+this.record.id,record:this.record}, y.a.createElement("a", {"data-mihomo-edit":this.record.id}, y.a.createElement(m["a"], {type:"form"}), " ویرایش")))''', 1),
        ('ADD_MENU', '''                    }, y.a.createElement("a", null, this.getTypeTag("trusttunnel", "TrustTunnel")))))''', '''                    }, y.a.createElement("a", null, this.getTypeTag("trusttunnel", "TrustTunnel")))), Object.keys(imnProtocolNames).map(protocol=>y.a.createElement(p["a"].Item, {key:"mihomo-"+protocol},y.a.createElement(mMihomo,{key:protocol,protocol:protocol},y.a.createElement("a",{"data-mihomo-add":protocol},y.a.createElement(g["a"],{color:"#5B6FC7"},imnProtocolNames[protocol]))))))''', 1),
        ('ADD_MENU_SCROLL', '''                    overlay: y.a.createElement(p["a"], null, y.a.createElement(p["a"].Item, null, y.a.createElement(mV2node, {''', '''                    overlay: y.a.createElement(p["a"], {style:{maxHeight:"65vh",overflowY:"auto"}}, y.a.createElement(p["a"].Item, null, y.a.createElement(mV2node, {''', 1),
        ('ADD_TRIGGER', '''                }, y.a.createElement(l["a"], null, y.a.createElement(m["a"], {
                    type: "plus"
                }))), y.a.createElement(s["a"], {''', '''                }, y.a.createElement(l["a"], {"data-mihomo-add-menu":true}, y.a.createElement(m["a"], {
                    type: "plus"
                }))), y.a.createElement(s["a"], {''', 1),
        ('ROW_NAME', '''}), y.a.createElement("span", null, e), /*__NODELOAD__*/''', '''}), imnProtocolNames[t.type] ? y.a.createElement(mMihomo,{record:t},y.a.createElement("a",{"data-mihomo-edit":t.id},e)) : y.a.createElement("span", null, e), /*__NODELOAD__*/''', 1),
        ('ADDRESS', '}, t.host + ":" + t.port)', '}, imnProtocolNames[t.type] ? imnAddress(t) : t.host + ":" + t.port)', 1),
        ('ADDRESS_COPY', 'S()(t.host),', 'S()(imnProtocolNames[t.type] ? imnAddress(t) : t.host),', 1),
        ('MOBILE_ADDRESS', 'description: "".concat(e.host, ":").concat(e.port)', 'description: imnProtocolNames[e.type] ? imnAddress(e) : "".concat(e.host, ":").concat(e.port)', 1),
        ('STATUS', 'status: D[t.available_status]', 'status: imnProtocolNames[t.type] && t.available_status == null ? "default" : D[t.available_status]', 1),
        ('MOBILE_STATUS', 'status: D[e.available_status]', 'status: imnProtocolNames[e.type] && e.available_status == null ? "default" : D[e.available_status]', 1),
    ]


def fence(key, body):
    return '/*__IMN_' + key + '_START__*/' + body + '/*__IMN_' + key + '_END__*/'


def strip_owned(text):
    text = re.sub(r'/\*__IMN_FORM_START__\*/.*?/\*__IMN_FORM_END__\*/', '', text, flags=re.S)
    for key, original, _, _ in changes():
        text = re.sub(r'/\*__IMN_' + key + r'_START__\*/.*?/\*__IMN_' + key + r'_END__\*/', lambda _: original, text, flags=re.S)
    return text


def patch_text(text):
    text = strip_owned(text)
    start = text.index('    uzXD: function(e, t, n) {')
    end = text.index('\n    v32e: function', start)
    block = text[start:end]
    marker = '        class q extends y.a.Component {'
    if block.count(marker) != 1:
        raise RuntimeError('Native node manager anchor changed')
    source = FORM.read_text(encoding='utf-8')
    block = block.replace(marker, fence('FORM', source) + marker)
    for key, original, updated, expected in changes():
        if block.count(original) != expected:
            raise RuntimeError(f'{key}: expected {expected} native anchors, found {block.count(original)}')
        block = block.replace(original, fence(key, updated))
    return text[:start] + block + text[end:]


def main():
    check = '--check' in sys.argv
    for name in ['umi.js', 'umi-fa.js']:
        path = ADMIN / name
        original = path.read_bytes()
        result = patch_text(original.decode('utf-8')).encode('utf-8')
        if check:
            if result != original:
                raise SystemExit(f'{name}: Mihomo node patch is not current')
        elif result != original:
            path.write_bytes(result)
        print(f'{name}: native Mihomo node controls ready')


if __name__ == '__main__':
    main()
