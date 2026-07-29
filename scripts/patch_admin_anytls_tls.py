#!/usr/bin/env python3
"""Add a TLS-mode section to the admin bundle's standalone anytls server form.

The admin frontend ships compiled - there is no source for it in this repo - so a
form field can only be added by editing the built bundle. That is done here, as a
script rather than by hand, so the change is reviewable, repeatable and provably
idempotent: the same edit applied to a fresh bundle from upstream produces the
same result, and running it twice is a no-op.

Why the field is needed: v2_server_anytls now carries `tls` (1=plain, 2=REALITY)
and `tls_settings`, and every other layer reads them - but this form is the only
place an operator can set them, and it renders neither. The v2node form already
offers exactly this control for protocol=anytls, which is the proof the rest of
the admin can handle the values; only the standalone anytls form was missing it.

What is inserted, right after the existing server_name (SNI) field:

  * a TLS-mode dropdown, and
  * when REALITY is selected, the borrowed site's name and port, plus a
    read-only view of the generated public_key/short_id.

The keypair is deliberately NOT an input. AnyTLSController generates it server
side on save, so there is nothing for a human to paste, and showing the values
read-only is what lets an operator verify the node and the client agree.

Only React primitives already bound in that scope are used - y.a.createElement,
N["a"] (Select), s["a"] (Input) and I() (the object-spread helper) - so nothing
new has to be resolved at runtime. Persian labels are written as \\u escapes,
matching what the bundle already does elsewhere, so this file stays pure ASCII
and no encoding can be lost in transit.
"""

import re
import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]

# Persian, as \u escapes. Kept next to the English so a reader can check them.
L_TLS_MODE = "\\u062d\\u0627\\u0644\\u062a TLS"                      # "TLS mode"
L_PLAIN = "TLS \\u0645\\u0639\\u0645\\u0648\\u0644\\u06cc"           # "plain TLS"
L_REALITY = "REALITY"
L_COVER = ("\\u0633\\u0627\\u06cc\\u062a \\u067e\\u0648\\u0634\\u0634")  # "cover site"
L_COVER_PORT = ("\\u067e\\u0648\\u0631\\u062a \\u0633\\u0627\\u06cc\\u062a "
                "\\u067e\\u0648\\u0634\\u0634")                       # "cover site port"
# "keys are generated after saving"
L_PENDING = ("\\u06a9\\u0644\\u06cc\\u062f\\u0647\\u0627 \\u067e\\u0633 \\u0627\\u0632 "
             "\\u0630\\u062e\\u06cc\\u0631\\u0647 \\u0633\\u0627\\u062e\\u062a\\u0647 "
             "\\u0645\\u06cc\\u200c\\u0634\\u0648\\u0646\\u062f")

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

# Split point: everything up to and including `})), ` is kept, then the new
# section, then the original padding-scheme row.
KEEP = (
    'value: e.server_name,\n'
    '                    onChange: e=>this.formChange("server_name", e.target.value)\n'
    '                })), '
)

MARKER = "/*anytls-tls-mode*/"

SECTION = (
    MARKER +
    # --- TLS mode dropdown -------------------------------------------------
    'y.a.createElement("div",{className:"form-group"},'
    'y.a.createElement("label",null,"' + L_TLS_MODE + '"),'
    'y.a.createElement(N["a"],{'
    'value:2===parseInt(e.tls)?2:1,'
    'style:{width:"100%"},'
    'onChange:v=>this.formChange("tls",v)},'
    'y.a.createElement(N["a"].Option,{key:1,value:1},"' + L_PLAIN + '"),'
    'y.a.createElement(N["a"].Option,{key:2,value:2},"' + L_REALITY + '"))),'
    # --- REALITY details, only when mode 2 is selected ---------------------
    '2===parseInt(e.tls)&&y.a.createElement("div",{className:"form-group"},'
    'y.a.createElement("label",null,"' + L_COVER + '"),'
    'y.a.createElement(s["a"],{'
    'placeholder:"www.microsoft.com",'
    'value:(e.tls_settings||{}).server_name||"",'
    'onChange:ev=>this.formChange("tls_settings",I()({},e.tls_settings||{},'
    '{server_name:ev.target.value}))}),'
    'y.a.createElement("label",{style:{marginTop:8}},"' + L_COVER_PORT + '"),'
    'y.a.createElement(s["a"],{'
    'placeholder:"443",'
    'value:(e.tls_settings||{}).server_port||"",'
    'onChange:ev=>this.formChange("tls_settings",I()({},e.tls_settings||{},'
    '{server_port:ev.target.value}))}),'
    # Read-only key display: proof the panel generated them, and what the
    # client will be told. Never an input - the server makes these on save.
    '(e.tls_settings||{}).public_key?'
    'y.a.createElement("div",{style:{marginTop:8,fontSize:12,wordBreak:"break-all"}},'
    'y.a.createElement("div",null,"public_key: "+(e.tls_settings||{}).public_key),'
    'y.a.createElement("div",null,"short_id: "+((e.tls_settings||{}).short_id||""))):'
    'y.a.createElement("div",{style:{marginTop:8,fontSize:12,opacity:.7}},'
    '"' + L_PENDING + '")),'
)


def patch(path: Path) -> str:
    # newline="" on BOTH sides, or Python rewrites every line ending. The bundle
    # is LF-only and ~117k lines; the default translation turns all of them into
    # CRLF on write, so a 1.5 KB insertion lands as a 118 KB diff that no one can
    # review and that no longer matches the file served in production.
    with path.open("r", encoding="utf-8", newline="") as fh:
        src = fh.read()

    if MARKER in src:
        return "already patched - no change"

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
