#!/usr/bin/env python3
"""Let the admin register a node's tunnel, and warn when two share a relay port.

Two gaps in the tunnel pill, both hit in production:

1. **Registration was SQL-only.** The pill reads v2_tunnel_map and nothing could
   write it; the CLI that used to lived on a panel host that has since been
   decommissioned. A newly tunnelled node therefore showed "no tunnel", reported
   no relay health, and could not be switched back - until someone hand-wrote a
   row. The unregistered pill is now clickable and asks for the two addresses.

   The direct address is asked for rather than taken from the node's current
   host: by the time anyone notices the node is unregistered, its host is usually
   ALREADY the relay IP, so inferring it would record the relay as the direct
   address and make "switch off" a no-op that looks like a failure.

2. **A relay port collision showed two green lights.** The relay forwards
   strictly by public port and can bind each one once, so two nodes on the same
   relay:port cannot both be served - but the health probe connects to
   relay:port and reported both as live. The backend now returns `conflict`
   (the other node ids claiming that port) and the pill turns amber and says so.

Idempotent: the replacement is fenced, so a re-run replaces it rather than
stacking a second copy.
"""

import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]

MARKER = "/*__TUNREG__*/"
END_MARKER = "/*__TUNREG_END__*/"

# ASCII-only boundaries, replaced by position. The Persian inside this block is
# stored as REAL UTF-8 characters, not \uXXXX escapes - an anchor written with
# escapes matches nothing, which is exactly how the first attempt failed. What we
# EMIT can still use escapes (JS parses both) so this file stays ASCII; only what
# we SEARCH FOR has to match the bytes on disk.
ANCHOR_START = (
    "function render(p,n){p.style.background='';p.style.color='';"
    "p.style.borderColor='#ddd';if(!n.detected){"
)
ANCHOR_END = ";return;}"


def fa(text: str) -> str:
    return "".join(f"\\u{ord(c):04x}" if ord(c) > 127 else c for c in text)


L = {
    "unreg":       "بدون تانل — کلیک: ثبت",
    "unreg_title": "این نود در نقشهٔ تانل نیست. کلیک کنید تا ثبت شود.",
    "ask_direct":  "آدرس مستقیم این نود (دامنه‌ای که بدون تانل سرویس می‌دهد):",
    "ask_relay":   "آی‌پی رلهٔ ایران:",
    "clash":       "تداخل پورت",
    "clash_title": "این رله و پورت را نود دیگری هم گرفته است — رله هر پورت را فقط یک بار می‌گیرد، پس فقط یکی واقعاً سرویس می‌دهد. نود(های) درگیر: ",
    "saved":       "ثبت شد",
    "err":         "خطا",
}

REPLACEMENT = (
    MARKER
    + "function render(p,n){p.style.background='';p.style.color='';p.style.borderColor='#ddd';"
      "if(!n.detected){"
      f"p.textContent='{fa(L['unreg'])}';"
      "p.style.color='#999';p.style.cursor='pointer';"
      f"p.title='{fa(L['unreg_title'])}';"
      "return;}"
      # A port claimed by more than one node: at most one is really served, so
      # say which others claim it instead of showing a reassuring green light.
      "if(n.conflict&&n.conflict.length){"
      "p.style.background='#fff7e6';p.style.color='#ad6800';p.style.borderColor='#ffd591';"
      f"p.textContent='\\u26a0 {fa(L['clash'])}';"
      f"p.title='{fa(L['clash_title'])}'+n.conflict.join('\\u060c')+' (\\u067e\\u0648\\u0631\\u062a '+n.port+')';"
      "return;}"
    + END_MARKER
)

# The click handler only ever fired for registered nodes; an unregistered pill
# now opens the registration prompt instead of doing nothing.
CLICK_FROM = "function setPill(p,n){p.__n=n;p.dataset.nid=n.id;render(p,n);p.style.cursor=n.detected?'pointer':'default';}"
CLICK_TO = (
    "function tunRegister(p,n){"
    f"var d=prompt('{fa(L['ask_direct'])}', n.host||'');if(!d)return;"
    f"var t=prompt('{fa(L['ask_relay'])}', '');if(!t)return;"
    "p.textContent='\\u2026';"
    "fetch(API+'/tunnel/register',{method:'POST',"
    "headers:{authorization:tok(),'content-type':'application/json'},"
    "body:JSON.stringify({id:n.id,direct_host:d.trim(),tunnel_ip:t.trim()})})"
    ".then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})"
    f".then(function(res){{if(!res.ok){{alert((res.j&&res.j.message)||'{fa(L['err'])}');render(p,n);return;}}"
    "load(refresh);})"
    f".catch(function(){{alert('{fa(L['err'])}');render(p,n);}});}}"
    "function setPill(p,n){p.__n=n;p.dataset.nid=n.id;render(p,n);p.style.cursor='pointer';}"
)


def patch(path: Path) -> str:
    with path.open("r", encoding="utf-8", newline="") as fh:
        src = fh.read()

    out = src
    notes = []

    if MARKER in out:
        a = out.find(MARKER)
        b = out.find(END_MARKER, a)
        if b < 0:
            return "ABORT: fenced section has no end marker"
        b += len(END_MARKER)
        if out[a:b] != REPLACEMENT:
            out = out[:a] + REPLACEMENT + out[b:]
            notes.append("render replaced")
    else:
        if out.count(ANCHOR_START) != 1:
            return f"ABORT: render anchor found {out.count(ANCHOR_START)} times (expected 1)"
        ia = out.find(ANCHOR_START)
        ib = out.find(ANCHOR_END, ia)
        if ib < 0:
            return "ABORT: could not find the end of the unregistered branch"
        ib += len(ANCHOR_END)
        out = out[:ia] + REPLACEMENT + out[ib:]
        notes.append("render patched")

    if "function tunRegister(" not in out:
        if out.count(CLICK_FROM) != 1:
            return f"ABORT: setPill found {out.count(CLICK_FROM)} times (expected 1)"
        out = out.replace(CLICK_FROM, CLICK_TO)
        notes.append("register handler added")

    # The existing click dispatcher must route an unregistered pill to the
    # registration prompt rather than the toggle.
    dispatch_from = "function doToggle(p,n){if(p.dataset.b)return;"
    dispatch_to = ("function doToggle(p,n){if(p.dataset.b)return;"
                   "if(!n.detected){tunRegister(p,n);return;}")
    if "if(!n.detected){tunRegister(p,n);return;}" not in out:
        if out.count(dispatch_from) != 1:
            return f"ABORT: doToggle found {out.count(dispatch_from)} times (expected 1)"
        out = out.replace(dispatch_from, dispatch_to)
        notes.append("click dispatch routed")

    if out == src:
        return "already current - no change"

    shutil.copyfile(path, path.with_suffix(path.suffix + ".prepatch"))
    with path.open("w", encoding="utf-8", newline="") as fh:
        fh.write(out)
    return f"{', '.join(notes)} ({len(out) - len(src):+d} chars)"


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
