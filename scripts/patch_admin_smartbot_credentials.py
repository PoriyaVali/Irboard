#!/usr/bin/env python3
"""Add the "send email and password" checkbox to the smart-bot admin page.

The smart-bot page lives inside both prebuilt admin bundles. Two fenced
additions are written: a checkbox at the end of each platform card, and the
line in sbSave() that sends its value. All other bytes, including local
operator edits, are preserved. A missing or repeated anchor aborts BEFORE
writing either file. Re-running is idempotent. Run it against the deployment's
own bundle copies; never replace a customised bundle with the repository's.

Usage:
  python scripts/patch_admin_smartbot_credentials.py [--check] [bundle ...]
"""

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"

# End of sbSection(): the add-link button closes the links group, then the card.
SECTION_ANCHOR = "➕ افزودن لینک</button></div>'\n"
SECTION_FOLLOW = "+'</div>';\n}"
CHECKBOX = (
    "+/*__DSC_SECTION_START__*/'<label style=\"display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:14px\">"
    "<input type=\"checkbox\" id=\"sb_'+key+'_creds\"'+(sbData&&sbData[\"help_\"+key+\"_send_credentials\"]?\" checked\":\"\")+'>"
    "<span>بعد از فایل‌ها و لینک‌ها، "
    "ایمیل و رمز عبور کاربر هم "
    "برایش ارسال شود</span></label>'/*__DSC_SECTION_END__*/\n"
)
# sbSave(): one line per platform, inside the forEach.
SAVE_ANCHOR = 'body["help_"+p+"_links"]=sbCollectLinks(p);\n'
SAVE = ('/*__DSC_SAVE_START__*/var _cr=document.getElementById("sb_"+p+"_creds");'
        'if(_cr)body["help_"+p+"_send_credentials"]=_cr.checked?1:0;/*__DSC_SAVE_END__*/\n')

FENCES = {
    "SECTION": r"\+/\*__DSC_SECTION_START__\*/.*?/\*__DSC_SECTION_END__\*/\n",
    "SAVE": r"/\*__DSC_SAVE_START__\*/.*?/\*__DSC_SAVE_END__\*/\n",
}


class PatchError(ValueError):
    pass


def remove_owned(source: str) -> str:
    for kind, pattern in FENCES.items():
        start, end = f"__DSC_{kind}_START__", f"__DSC_{kind}_END__"
        if source.count(start) != source.count(end) or source.count(start) > 1:
            raise PatchError(f"ambiguous or incomplete {kind} fence")
        source, count = re.subn(pattern, "", source, flags=re.DOTALL)
        if count > 1:
            raise PatchError(f"unexpected {kind} owned region")
    return source


def patch(source: str) -> str:
    base = remove_owned(source)
    anchor = SECTION_ANCHOR + SECTION_FOLLOW
    if base.count(anchor) != 1:
        raise PatchError(f"sbSection anchor occurs {base.count(anchor)} times; expected exactly one")
    if base.count(SAVE_ANCHOR) != 1:
        raise PatchError(f"sbSave anchor occurs {base.count(SAVE_ANCHOR)} times; expected exactly one")
    out = base.replace(anchor, SECTION_ANCHOR + CHECKBOX + SECTION_FOLLOW, 1)
    return out.replace(SAVE_ANCHOR, SAVE_ANCHOR + SAVE, 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="verify the bundles already have the current additions; do not write")
    parser.add_argument("bundles", nargs="*", type=Path)
    args = parser.parse_args()
    bundles = args.bundles or [ADMIN / "umi.js", ADMIN / "umi-fa.js"]

    planned = []
    for bundle in bundles:
        original = bundle.read_bytes()
        try:
            text = original.decode("utf-8")
            output = patch(text).encode("utf-8")
        except (UnicodeDecodeError, PatchError) as error:
            print(f"{bundle}: {error}", file=sys.stderr)
            return 1
        planned.append((bundle, original, output))

    report = []
    for bundle, original, output in planned:
        changed = original != output
        if args.check and changed:
            print(f"{bundle}: smart-bot credentials checkbox missing or out of date", file=sys.stderr)
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
