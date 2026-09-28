#!/usr/bin/env python3
"""Show each node's load in the admin node list, and label the report
thresholds in the unit the node reads them in.

1. **The load a node reports was thrown away.** V2bX posts its CPU, memory,
   swap and disk every minute (UniProxy/status); the panel only took it as a
   heartbeat, so a node running out of memory or disk was invisible until users
   complained. The panel now keeps the last report for five minutes and the node
   list returns it as `load_status`; this shows it under the node's name as
   "CPU 12% · RAM 40% · Disk 31%". A node that stops reporting simply shows
   nothing.

2. **"Kb" was the wrong unit.** The two traffic thresholds on the node settings
   tab (report threshold, device threshold) are read by V2bX as kilobytes
   (x1000 bytes). "Kb" reads as kilobits, an eighth of that.

Idempotent: the load cell is fenced, so a re-run replaces it rather than
stacking a second copy, and the label fix is a no-op once applied.
"""

import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]

START = "/*__NODELOAD__*/"
END = "/*__NODELOAD_END__*/"

# The name cell of the node table: a status dot, then the name. ASCII-only and
# asserted unique.
NAME_ANCHOR = (
    'status: D[t.available_status]\n'
    '                        }), y.a.createElement("span", null, e))'
)

LOAD_CELL = START + (
    '(function(s){'
    'if(!s||typeof s!=="object")return null;'
    'var p=function(m){return m&&m.total>0?Math.round(m.used*100/m.total)+"%":null};'
    'var parts=["CPU "+Math.round(Number(s.cpu)||0)+"%"];'
    'var r=p(s.mem);if(r)parts.push("RAM "+r);'
    'var d=p(s.disk);if(d)parts.push("Disk "+d);'
    'return y.a.createElement("div",{style:{fontSize:11,opacity:.65,marginTop:2,direction:"ltr",textAlign:"right"}},'
    'parts.join(" \\u00b7 "))'
    '})(t.load_status)'
) + END

NAME_NEW = (
    'status: D[t.available_status]\n'
    '                        }), y.a.createElement("span", null, e), ' + LOAD_CELL + ')'
)

# The two threshold inputs are the only "Kb" addons in the bundle.
KB_OLD = 'addonAfter: "Kb",'
KB_NEW = 'addonAfter: "KB",'


def patch(path: Path) -> str:
    src = path.read_text(encoding="utf-8")
    out = src
    notes = []

    # --- 1. load under the node name --------------------------------------
    if START in out:
        a = out.find(START)
        b = out.find(END, a)
        if b < 0:
            return "ABORT: fenced load cell has no end marker"
        b += len(END)
        if out[a:b] != LOAD_CELL:
            out = out[:a] + LOAD_CELL + out[b:]
            notes.append("load cell replaced")
    else:
        if out.count(NAME_ANCHOR) != 1:
            return f"ABORT: node name cell found {out.count(NAME_ANCHOR)} times, want 1"
        out = out.replace(NAME_ANCHOR, NAME_NEW, 1)
        notes.append("load cell added")

    # --- 2. threshold unit ------------------------------------------------
    n = out.count(KB_OLD)
    if n not in (0, 2):
        return f"ABORT: {n} \"Kb\" addons, want the 2 threshold inputs"
    if n:
        out = out.replace(KB_OLD, KB_NEW)
        notes.append("Kb -> KB")

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
