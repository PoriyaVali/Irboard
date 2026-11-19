#!/usr/bin/env python3
"""Add the "serve node IPs" switch to the admin's subscription settings tab.

The setting `subscribe_serve_ips` (NodeAddressResolver) is saved through the
ordinary config/save call, but the prebuilt admin bundles only draw the
settings they were built with. This adds one fenced row, a switch right after
"show subscription info in the subscription" on the subscription tab, to both
bundles. All other bytes, including local operator edits, are preserved. A
missing or repeated anchor aborts BEFORE writing either file. Re-running is
idempotent.

Usage:
  python scripts/patch_admin_subscribe_serve_ips.py [--check] [bundle ...]
"""

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"

# The end of the show_info_to_server_enable row; the next row starts right after.
ANCHOR = 'onChange: e=>this.set("subscribe", "show_info_to_server_enable", e ? 1 : 0)\n                })), '

TITLE = "ارسال IP نودها در اشتراک"
DESCRIPTION = (
    "به‌جای دامنه، IP هر نود در اشتراک فرستاده می‌شود تا برنامه پیش از اتصال "
    "نام نودها را از DNS نپرسد. نودهای Cloudflare، نودهای دارای تگ keep-domain "
    "و نودهای ws/httpupgrade/xhttp بدون Host دامنه‌ی خود را نگه می‌دارند."
)


def js_text(text: str) -> str:
    """The bundle's own string style: RTL-embedded and \\u-escaped."""
    return "".join(ch if ord(ch) < 128 else "\\u%04x" % ord(ch) for ch in "‫" + text + "‬")


ROW = (
    "/*__DSSI_ROW_START__*/f.a.createElement(m, {\n"
    '                    title: "' + js_text(TITLE) + '",\n'
    '                    description: "' + js_text(DESCRIPTION) + '"\n'
    '                }, f.a.createElement(l["a"], {\n'
    "                    checked: parseInt(r.subscribe_serve_ips) === 1,\n"
    '                    onChange: e=>this.set("subscribe", "subscribe_serve_ips", e ? 1 : 0)\n'
    "                })), /*__DSSI_ROW_END__*/"
)
FENCE = r"/\*__DSSI_ROW_START__\*/.*?/\*__DSSI_ROW_END__\*/"


class PatchError(ValueError):
    pass


def remove_owned(source: str) -> str:
    start, end = "__DSSI_ROW_START__", "__DSSI_ROW_END__"
    if source.count(start) != source.count(end) or source.count(start) > 1:
        raise PatchError("ambiguous or incomplete serve-ips fence")
    return re.sub(FENCE, "", source, count=1, flags=re.DOTALL)


def patch(source: str) -> str:
    base = remove_owned(source)
    if base.count(ANCHOR) != 1:
        raise PatchError(f"subscription-tab anchor occurs {base.count(ANCHOR)} times; expected exactly one")
    return base.replace(ANCHOR, ANCHOR + ROW, 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="verify the bundles already have the current row; do not write")
    parser.add_argument("bundles", nargs="*", type=Path)
    args = parser.parse_args()
    bundles = args.bundles or [ADMIN / "umi.js", ADMIN / "umi-fa.js"]

    planned = []
    for bundle in bundles:
        original = bundle.read_bytes()
        try:
            output = patch(original.decode("utf-8")).encode("utf-8")
        except (UnicodeDecodeError, PatchError) as error:
            print(f"{bundle}: {error}", file=sys.stderr)
            return 1
        planned.append((bundle, original, output))

    report = []
    for bundle, original, output in planned:
        changed = original != output
        if args.check and changed:
            print(f"{bundle}: serve-node-IPs switch missing or out of date", file=sys.stderr)
            return 1
        if changed and not args.check:
            bundle.write_bytes(output)
        report.append({"bundle": str(bundle), "changed": changed,
                       "before_sha256": hashlib.sha256(original).hexdigest(),
                       "after_sha256": hashlib.sha256(output).hexdigest(),
                       "added_bytes": len(output) - len(original)})
    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
