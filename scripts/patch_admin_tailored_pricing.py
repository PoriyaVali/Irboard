#!/usr/bin/env python3
"""Add the Tailored sidebar item and owned route to both prebuilt admin bundles.

Only two fenced additions are written, immediately after the existing /plan
menu and route objects. All other bytes (including local operator edits) are
preserved. Unknown or ambiguous anchors abort BEFORE writing either file.
Re-running is idempotent. Run against explicit private copies when preparing
an overlay for a customised deployment; never replace that deployment's whole
bundle with a repository copy.

Usage:
  python scripts/patch_admin_tailored_pricing.py [--check] [bundle ...]
"""

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"
ROUTE = "/tailored-pricing"
LABEL = r'(/^fa\b/i.test(localStorage.getItem("umi_locale") || "fa") ? "\u062f\u0633\u062a\u200c\u062f\u0648\u0632" : "Tailored")'
MENU = '{title: ' + LABEL + ', type: "item", href: "' + ROUTE + '", icon: o.a.createElement("i", {className: "nav-main-link-icon si si-scissors"})}'
PAGE = ('{path: "' + ROUTE + '", exact: !0, component: function(e) {'
        'var r=n.n(n("q1tI")).a; return r.createElement(n("Bl7J").a, '
        'Object.assign({},e,{title:' + LABEL + '}), '
        'r.createElement("div",{id:"doctor-tailored-host"}));}}')
REGIONS = {
    "MENU": ', /*__DTP_MENU_START__*/' + MENU + '/*__DTP_MENU_END__*/',
    "ROUTE": ', /*__DTP_ROUTE_START__*/' + PAGE + '/*__DTP_ROUTE_END__*/',
}


class PatchError(ValueError):
    pass


def remove_owned(source: str) -> str:
    for kind in REGIONS:
        start, end = f"/*__DTP_{kind}_START__*/", f"/*__DTP_{kind}_END__*/"
        starts, ends = source.count(start), source.count(end)
        if starts != ends or starts > 1:
            raise PatchError(f"ambiguous or incomplete {kind} fence")
        if starts:
            pattern = r", /\*__DTP_" + kind + r"_START__\*/.*?/\*__DTP_" + kind + r"_END__\*/"
            source, count = re.subn(pattern, "", source, flags=re.DOTALL)
            if count != 1:
                raise PatchError(f"unexpected {kind} owned-region delimiter")
    return source


def object_span(source: str, property_name: str) -> tuple[int, int]:
    anchor = re.compile(r"\b" + property_name + r'\s*:\s*"/plan"')
    matches = list(anchor.finditer(source))
    if len(matches) != 1:
        raise PatchError(f"{property_name} /plan anchor occurs {len(matches)} times; expected exactly one")
    start = source.rfind("{", 0, matches[0].start())
    if start < 0:
        raise PatchError(f"missing containing {property_name} object")
    depth, quote, escaped = 0, None, False
    index = start
    while index < len(source):
        char = source[index]
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
        elif source.startswith("//", index):
            newline = source.find("\n", index + 2)
            if newline < 0:
                break
            index = newline
        elif source.startswith("/*", index):
            end = source.find("*/", index + 2)
            if end < 0:
                break
            index = end + 1
        elif char in ('"', "'", "`"):
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return start, index + 1
        index += 1
    raise PatchError(f"unbalanced {property_name} object")


def patch_bytes(original: bytes) -> bytes:
    try:
        source = original.decode("utf-8")
    except UnicodeError as error:
        raise PatchError("bundle must contain valid UTF-8; no lossy conversion is permitted") from error
    clean = remove_owned(source)
    if re.search(r'\b(?:href|path)\s*:\s*"/tailored-pricing"', clean):
        raise PatchError("an unowned tailored menu/route already exists")
    menu_start, menu_end = object_span(clean, "href")
    route_start, route_end = object_span(clean, "path")
    if 'o.a.createElement' not in clean[menu_start:menu_end]:
        raise PatchError("the sidebar React binding changed")
    if 'n("ih8c").default' not in clean[route_start:route_end]:
        raise PatchError("the original plan route binding changed")
    output = clean
    for end, region in sorted(((menu_end, REGIONS["MENU"]), (route_end, REGIONS["ROUTE"])), reverse=True):
        output = output[:end] + region + output[end:]
    if remove_owned(output) != clean:
        raise PatchError("unexpected mutation outside owned additions")
    return output.encode("utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="verify both bundles already have the current additions; do not write")
    parser.add_argument("bundles", nargs="*", type=Path)
    arguments = parser.parse_args()
    paths = arguments.bundles or [ADMIN / "umi.js", ADMIN / "umi-fa.js"]
    prepared = []
    try:
        for path in paths:
            original = path.read_bytes()
            prepared.append((path, original, patch_bytes(original)))
    except (OSError, PatchError) as error:
        print(f"ABORT: {error}", file=sys.stderr)
        return 2
    current = True
    for path, original, output in prepared:
        changed = original != output
        current = current and not changed
        if changed and not arguments.check:
            path.write_bytes(output)
        print(json.dumps({"file": str(path), "changed": changed, "written": changed and not arguments.check,
                          "before_sha256": hashlib.sha256(original).hexdigest(),
                          "after_sha256": hashlib.sha256(output).hexdigest(),
                          "before_bytes": len(original), "after_bytes": len(output)}))
    return 0 if current or not arguments.check else 1


if __name__ == "__main__":
    sys.exit(main())
