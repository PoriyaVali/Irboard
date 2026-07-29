#!/usr/bin/env python3
"""Add the TLS-mode and ECH controls to the admin bundle's anytls server form.

The admin frontend ships compiled - there is no source for it in this repo - so a
form field can only be added by editing the built bundle. That is done here, as a
script rather than by hand, so the change is reviewable, repeatable and provably
idempotent: the same edit applied to a fresh bundle from upstream produces the
same result, and running it twice is a no-op.

Why the fields are needed: v2_server_anytls carries `tls` (1=plain, 2=REALITY)
and `tls_settings`, and every other layer reads them - but this form is the only
place an operator can set them, and it renders none of it. The v2node form
already offers the same controls for protocol=anytls, which is the proof the rest
of the admin handles the values; only the standalone anytls form was missing it.

What is inserted, right after the existing server_name (SNI) field:

  * a TLS-mode dropdown (plain / REALITY);
  * under REALITY, the borrowed site's name and port, plus a read-only view of
    the generated public_key/short_id;
  * outside REALITY, an ECH mode (off / custom / Cloudflare) and, for custom,
    the cover domain - with a read-only note once the keys exist.

Neither keypair is an input. AnyTLSController generates both server side on save,
so there is nothing for a human to paste, and showing them read-only is what lets
an operator verify the node and the client agree.

REALITY and ECH are deliberately exclusive in the form because they are exclusive
in sing-box: `common/tls/reality_server.go` refuses an inbound carrying both with
"Reality is conflict with ECH", so a node configured with the pair would fail to
start. Offering them together would only produce nodes that do not boot.

Only React primitives already bound in that scope are used - y.a.createElement,
N["a"] (Select), s["a"] (Input) and I() (the object-spread helper) - so nothing
new has to be resolved at runtime. Persian labels are written normally below and
converted to \\u escapes on the way out, matching what the bundle already does,
so the emitted patch stays pure ASCII and no encoding can be lost in transit.
"""

import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]
# The section is fenced by both markers so a later run can REPLACE it rather than
# refuse. Without an end marker the only way to change the fields was to restore
# the bundle from an older commit first, which is a trap: the restore silently
# reverts any other bundle change made since.
MARKER = "/*anytls-tls-mode*/"
END_MARKER = "/*end-anytls-tls-mode*/"


def js(text: str) -> str:
    """A JS string literal body with every non-ASCII char as a \\uXXXX escape."""
    out = []
    for ch in text:
        code = ord(ch)
        if code > 127:
            out.append(f"\\u{code:04x}")
        elif ch in ('"', "\\"):
            out.append("\\" + ch)
        else:
            out.append(ch)
    return "".join(out)


# --- labels, readable here and escaped on the way out ----------------------
L = {
    "tls_mode":     "حالت TLS",
    "plain":        "TLS معمولی",
    "reality":      "REALITY",
    "cover":        "سایت پوشش (REALITY)",
    "cover_port":   "پورت سایت پوشش",
    "keys_pending": "کلیدها پس از ذخیره ساخته می‌شوند",
    "ech":          "ECH (رمزگذاری نام سرور)",
    "ech_off":      "خاموش",
    "ech_custom":   "سفارشی (کلید روی همین نود)",
    "ech_cf":       "کلادفلر",
    "ech_domain":   "دامنهٔ پوشش ECH",
    "ech_hint":     "باید دامنه‌ای باشد که گواهی آن روی نود موجود است",
    "ech_ready":    "کلید ECH ساخته شده است",
}

# The anchor is the end of the server_name (SNI) field plus the start of the
# padding-scheme row that follows it. server_name's onChange makes it specific to
# this form; the v2node form has its own padding-scheme row, so the padding half
# alone would not be unique.
ANCHOR = (
    'value: e.server_name,\n'
    '                    onChange: e=>this.formChange("server_name", e.target.value)\n'
    '                })), y.a.createElement("div", {\n'
    '                    className: "row"\n'
    '                }, y.a.createElement("div", {\n'
    '                    className: "form-group col-md-12 col-xs-12"\n'
    '                }, y.a.createElement("label", null, y.a.createElement("a", {\n'
    '                    href: "javascript:void(0);",\n'
    '                    onClick: ()=>this.showChildDrawer('
)

# Only the anchor's first half is kept before the insertion point.
KEEP = (
    'value: e.server_name,\n'
    '                    onChange: e=>this.formChange("server_name", e.target.value)\n'
    '                })), '
)

# Shorthand for "merge one key into tls_settings", the only write this form does.
def set_ts(key: str, expr: str) -> str:
    return (f'this.formChange("tls_settings",I()({{}},e.tls_settings||{{}},'
            f'{{{key}:{expr}}}))')


SECTION = (
    MARKER
    # --- TLS mode -----------------------------------------------------------
    + 'y.a.createElement("div",{className:"form-group"},'
      f'y.a.createElement("label",null,"{js(L["tls_mode"])}"),'
      'y.a.createElement(N["a"],{'
      'value:2===parseInt(e.tls)?2:1,'
      'style:{width:"100%"},'
      'onChange:v=>this.formChange("tls",v)},'
      f'y.a.createElement(N["a"].Option,{{key:1,value:1}},"{js(L["plain"])}"),'
      f'y.a.createElement(N["a"].Option,{{key:2,value:2}},"{js(L["reality"])}"))),'
    # --- REALITY details, only in mode 2 ------------------------------------
      '2===parseInt(e.tls)&&y.a.createElement("div",{className:"form-group"},'
      f'y.a.createElement("label",null,"{js(L["cover"])}"),'
      'y.a.createElement(s["a"],{'
      'placeholder:"www.microsoft.com",'
      'value:(e.tls_settings||{}).server_name||"",'
      f'onChange:ev=>{set_ts("server_name", "ev.target.value")}}}),'
      f'y.a.createElement("label",{{style:{{marginTop:8}}}},"{js(L["cover_port"])}"),'
      'y.a.createElement(s["a"],{'
      'placeholder:"443",'
      'value:(e.tls_settings||{}).server_port||"",'
      f'onChange:ev=>{set_ts("server_port", "ev.target.value")}}}),'
      '(e.tls_settings||{}).public_key?'
      'y.a.createElement("div",{style:{marginTop:8,fontSize:12,wordBreak:"break-all"}},'
      'y.a.createElement("div",null,"public_key: "+(e.tls_settings||{}).public_key),'
      'y.a.createElement("div",null,"short_id: "+((e.tls_settings||{}).short_id||""))):'
      'y.a.createElement("div",{style:{marginTop:8,fontSize:12,opacity:.7}},'
      f'"{js(L["keys_pending"])}")),'
    # --- ECH, only OUTSIDE reality (sing-box refuses the combination) --------
      '2!==parseInt(e.tls)&&y.a.createElement("div",{className:"form-group"},'
      f'y.a.createElement("label",null,"{js(L["ech"])}"),'
      'y.a.createElement(N["a"],{'
      'value:(e.tls_settings||{}).ech||"",'
      'style:{width:"100%"},'
      f'onChange:v=>{set_ts("ech", "v")}}},'
      f'y.a.createElement(N["a"].Option,{{key:0,value:""}},"{js(L["ech_off"])}"),'
      f'y.a.createElement(N["a"].Option,{{key:1,value:"custom"}},"{js(L["ech_custom"])}"),'
      f'y.a.createElement(N["a"].Option,{{key:2,value:"cloudflare"}},"{js(L["ech_cf"])}"))),'
    # --- ECH cover domain, only for the custom mode -------------------------
      '2!==parseInt(e.tls)&&"custom"===((e.tls_settings||{}).ech||"")&&'
      'y.a.createElement("div",{className:"form-group"},'
      f'y.a.createElement("label",null,"{js(L["ech_domain"])}"),'
      'y.a.createElement(s["a"],{'
      'placeholder:"node.example.com",'
      'value:(e.tls_settings||{}).ech_server_name||"",'
      f'onChange:ev=>{set_ts("ech_server_name", "ev.target.value")}}}),'
      'y.a.createElement("div",{style:{marginTop:6,fontSize:12,opacity:.7}},'
      f'"{js(L["ech_hint"])}"),'
      '(e.tls_settings||{}).ech_config?'
      'y.a.createElement("div",{style:{marginTop:6,fontSize:12,color:"#52c41a"}},'
      f'"{js(L["ech_ready"])}"):'
      'y.a.createElement("div",{style:{marginTop:6,fontSize:12,opacity:.7}},'
      f'"{js(L["keys_pending"])}")),'
    + END_MARKER
)


def patch(path: Path) -> str:
    # newline="" on BOTH sides, or Python rewrites every line ending. The bundle
    # is LF-only and ~117k lines; the default translation turns all of them into
    # CRLF on write, so a small insertion lands as a 118 KB diff that no one can
    # review and that no longer matches the file served in production.
    with path.open("r", encoding="utf-8", newline="") as fh:
        src = fh.read()

    # Already carries a section: replace it in place, so changing the fields is
    # one re-run rather than a restore-from-history dance.
    if MARKER in src:
        start = src.find(MARKER)
        end = src.find(END_MARKER, start)
        if end < 0:
            # An older run inserted a section before the fence existed. Its
            # extent cannot be determined safely, so refuse rather than guess at
            # a cut point inside minified JS.
            return ("ABORT: found an unfenced older section - restore the bundle "
                    "from the commit before it was added, then re-run")
        end += len(END_MARKER)
        if src[start:end] == SECTION:
            return "already current - no change"
        out = src[:start] + SECTION + src[end:]
        shutil.copyfile(path, path.with_suffix(path.suffix + ".prepatch"))
        with path.open("w", encoding="utf-8", newline="") as fh:
            fh.write(out)
        return f"section replaced: {len(out) - len(src):+d} chars"

    n = src.count(ANCHOR)
    if n != 1:
        return f"ABORT: anchor found {n} times (expected exactly 1)"

    # The insertion point is derived from the anchor, not searched for on its
    # own: KEEP is only the anchor's first half, and that half also appears in
    # the vless and v2node forms, which render an identical server_name field.
    # Locating it independently would splice this section into whichever of
    # those happened to come first in the bundle.
    if not ANCHOR.startswith(KEEP):
        return "ABORT: KEEP is not a prefix of ANCHOR - the two have drifted"

    cut = src.find(ANCHOR) + len(KEEP)
    out = src[:cut] + SECTION + src[cut:]

    shutil.copyfile(path, path.with_suffix(path.suffix + ".prepatch"))
    with path.open("w", encoding="utf-8", newline="") as fh:
        fh.write(out)
    return f"patched: +{len(out) - len(src)} chars"


def main() -> int:
    rc = 0
    for name in TARGETS:
        p = ADMIN / name
        if not p.exists():
            print(f"{name}: MISSING")
            rc = 1
            continue
        result = patch(p)
        print(f"{name}: {result}")
        if result.startswith("ABORT"):
            rc = 1
    return rc


if __name__ == "__main__":
    sys.exit(main())
