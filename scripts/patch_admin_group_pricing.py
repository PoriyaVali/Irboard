#!/usr/bin/env python3
"""Make the Group Pricing tab open on the first click instead of after a reload.

The tab is not a real page. The route `/group-pricing` is registered against a
placeholder component, and a script appended to the compiled admin bundle
overwrites `#main-container` with its own markup whenever the hash matches.

That survives a full page load and loses a race on an in-app click:

    function inject(){
      if(injected)return;            // a blind latch
      ...
      injected=true;                 // set before the content is safe
      mc.innerHTML='...loading...';
      load();
    }
    new MutationObserver(...{ if(hash matches){inject()} else {injected=false} })

Clicking the menu item changes the hash, the script writes its markup, and React
*then* renders the placeholder into the same container and wipes it. The observer
does fire on that render - but `injected` is already true, so inject() returns at
once and nothing repairs it. Reloading works because the route is already active
at startup, so the trailing `setTimeout(inject,900)` runs after React has settled.

The fix replaces the boolean with a question about the DOM - "is my content still
there?" - by planting a marker element with the markup. inject() bails out only
while that marker is inside the current container; when React clobbers it the
marker goes too, the observer fires, and the next call re-injects.

That alone would be a trap: load() replaces the container's contents when the
fetch returns, which removes the marker and would make the observer re-inject
forever. So load()'s own successful write is wrapped in the same marker, and a
short throttle bounds the remaining case - a failed fetch, which now retries
slowly instead of hammering.

Idempotent: the replacement is fenced, so a re-run replaces it rather than
stacking a second copy.
"""

import shutil
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
TARGETS = ["umi.js", "umi-fa.js"]

MARKER = "/*__GP_INJECT_FIX__*/"
END_MARKER = "/*__GP_INJECT_FIX_END__*/"

# ASCII-only anchors. The span between them contains Persian as real UTF-8, so it
# is located by position rather than reproduced here.
START_ANCHOR = (
    "function inject(){\n"
    "  if(injected)return;\n"
    "  if(location.hash.indexOf('/group-pricing')<0)return;"
)
END_ANCHOR = "setTimeout(inject,900);"

# "در حال بارگذاری…" with the hourglass, as escapes so this file stays ASCII.
LOADING = ("\\u23f3 \\u062f\\u0631 \\u062d\\u0627\\u0644 "
           "\\u0628\\u0627\\u0631\\u06af\\u0630\\u0627\\u0631\\u06cc\\u2026")

REPLACEMENT = (
    MARKER + """
function gpHere(){return location.hash.indexOf('/group-pricing')>-1;}
function gpBox(){return document.getElementById('main-container');}
// The marker IS the latch. A boolean cannot know that React re-rendered over us;
// the DOM can. load() wraps its own output in the same marker (patched below),
// so a completed page counts as ours and does not trigger a re-injection.
function gpWrap(html){return '<div data-gp-root="1">'+html+'</div>';}
function gpMine(mc){return !!(mc&&mc.querySelector('[data-gp-root]'));}
var gpBurst=0,gpWindow=0;
function inject(){
  if(!gpHere()){injected=false;return;}
  var mc=gpBox();
  if(!mc)return;
  if(gpMine(mc))return;
  // A budget, not a cooldown. React clobbers the container once, immediately
  // after we write into it, so recovery has to be allowed to happen right away -
  // a plain "wait 1.5s between injections" guard blocks exactly the case this
  // patch exists to fix, which is how it was caught. Three tries per three
  // seconds leaves the re-render case instant while still capping the one the
  // marker cannot cover: a fetch that fails and leaves markup without a marker.
  var now=Date.now();
  if(now-gpWindow>3000){gpWindow=now;gpBurst=0;}
  if(gpBurst>=3)return;
  gpBurst++;
  injected=true;
  mc.innerHTML=gpWrap('<div style="text-align:center;padding:60px;color:#999">""" + LOADING + """</div>');
  load();
}
// Coalesce the burst of mutations one React render produces, so a re-render
// costs a single re-injection rather than dozens.
var gpTimer=null;
function gpSoon(){if(gpTimer)return;gpTimer=setTimeout(function(){gpTimer=null;inject();},60);}
new MutationObserver(function(){
  if(gpHere()){gpSoon()}else{injected=false}
}).observe(document.body,{childList:true,subtree:true});
window.addEventListener('hashchange',function(){setTimeout(inject,120);setTimeout(inject,400);});
setTimeout(inject,900);"""
    + END_MARKER
)

# load()'s successful write must carry the marker too, or the observer would see
# "not ours" the moment the data arrives and re-inject on top of a good page.
LOAD_WRITE_FROM = "mc.innerHTML=render(r.data);"
LOAD_WRITE_TO = "mc.innerHTML=gpWrap(render(r.data));"


def patch(path: Path) -> str:
    with path.open("r", encoding="utf-8", newline="") as fh:
        src = fh.read()

    out = src
    notes = []

    if MARKER in out:
        start = out.find(MARKER)
        end = out.find(END_MARKER, start)
        if end < 0:
            return "ABORT: fenced section has no end marker"
        end += len(END_MARKER)
        if out[start:end] != REPLACEMENT:
            out = out[:start] + REPLACEMENT + out[end:]
            notes.append("section replaced")
    else:
        if out.count(START_ANCHOR) != 1:
            return f"ABORT: start anchor found {out.count(START_ANCHOR)} times (expected 1)"
        ia = out.find(START_ANCHOR)
        ib = out.find(END_ANCHOR, ia)
        if ib < 0:
            return "ABORT: end anchor not found after the start anchor"
        ib += len(END_ANCHOR)
        out = out[:ia] + REPLACEMENT + out[ib:]
        notes.append("inject() replaced")

    if LOAD_WRITE_TO not in out:
        if out.count(LOAD_WRITE_FROM) != 1:
            return f"ABORT: load() write site found {out.count(LOAD_WRITE_FROM)} times (expected 1)"
        out = out.replace(LOAD_WRITE_FROM, LOAD_WRITE_TO)
        notes.append("load() write wrapped")

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
