#!/usr/bin/env python3
"""Let the web admin see the receipt image of a card-to-card payment.

Receipts arrive as images from the Telegram bot, the app and the customer site
(card-payment/claim and card-payment/receipt). The panel serves any of them to
an admin through card-payment/receipt, but the prebuilt admin bundles' card
payment list never asked for it: an admin saw the tracking number and nothing
else. This adds, to both bundles:

  * a "view receipt" row on every payment that has one (receipt_file_id);
  * cpShowReceipt(id), which fetches the image and shows it in an overlay.

The image goes in through a data: URL set as a property with a whitelisted
type, and every message through textContent, never innerHTML. All other bytes,
including local operator edits, are preserved. A missing or repeated anchor
aborts BEFORE writing either file. Re-running is idempotent.

Usage:
  python scripts/patch_admin_card_receipt.py [--check] [bundle ...]
"""

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

ADMIN = Path(__file__).resolve().parent.parent / "public" / "assets" / "admin"

# The destination-card row of a payment; the receipt row follows it.
ROW_ANCHOR = (
    "if(p.card_number)html+='<div class=\"cp-row\"><span>کارت مقصد:</span>"
    "<b style=\"direction:ltr\">'+p.card_number+'</b></div>';"
)
# The first action handler; the viewer goes right before it.
FN_ANCHOR = "window.cpVerifyFull=function(id){"

ROW = (
    "/*__DCR_ROW_START__*/"
    "if(p.receipt_file_id)html+='<div class=\"cp-row\"><span>تصویر رسید:</span>"
    "<button class=\"cp-btn\" type=\"button\" onclick=\"cpShowReceipt('+Number(p.id)+')\">"
    "🧾 مشاهدهٔ رسید</button></div>';"
    "/*__DCR_ROW_END__*/"
)

FN = (
    "/*__DCR_FN_START__*/\n"
    "window.cpShowReceipt=function(id){\n"
    "var o=document.createElement(\"div\");"
    "o.style.cssText=\"position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.75);"
    "display:flex;align-items:center;justify-content:center;padding:16px\";"
    "o.onclick=function(e){if(e.target===o)o.remove()};\n"
    "var b=document.createElement(\"div\");"
    "b.style.cssText=\"background:#fff;color:#333;border-radius:10px;max-width:96vw;max-height:94vh;"
    "overflow:auto;padding:10px;text-align:center\";"
    "b.textContent=\"⏳ در حال دریافت رسید…\";o.appendChild(b);document.body.appendChild(o);\n"
    "fetch(\"/api/v1/\"+window.settings.secure_path+\"/card-payment/receipt?id=\"+encodeURIComponent(id),"
    "{headers:{\"Authorization\":cpGetToken()}})"
    ".then(function(r){return r.json()}).then(function(j){\n"
    "var d=j&&j.data;if(!d||!d.image){b.textContent=(j&&j.message)||\"رسیدی موجود نیست\";return}\n"
    "var t=/^image\\/(jpeg|png|webp|gif)$/.test(d.mime||\"\")?d.mime:\"image/jpeg\";b.textContent=\"\";\n"
    "var im=document.createElement(\"img\");im.alt=\"رسید\";im.src=\"data:\"+t+\";base64,\"+d.image;"
    "im.style.cssText=\"max-width:92vw;max-height:82vh;display:block;margin:0 auto 8px\";\n"
    "var c=document.createElement(\"button\");c.type=\"button\";c.className=\"cp-btn\";c.textContent=\"بستن\";"
    "c.onclick=function(){o.remove()};b.appendChild(im);b.appendChild(c)\n"
    "}).catch(function(){b.textContent=\"دریافت رسید ناموفق بود\"})};\n"
    "/*__DCR_FN_END__*/\n"
)

# The function block brings its own trailing newline and takes it away again;
# the row is inline and must leave the bundle's own newline after it alone.
FENCES = [
    ("__DCR_ROW_START__", "__DCR_ROW_END__", ""),
    ("__DCR_FN_START__", "__DCR_FN_END__", "\n"),
]


class PatchError(ValueError):
    pass


def remove_owned(source: str) -> str:
    for start, end, tail in FENCES:
        if source.count(start) != source.count(end) or source.count(start) > 1:
            raise PatchError(f"ambiguous or incomplete {start} fence")
        source = re.sub(
            r"/\*" + start + r"\*/.*?/\*" + end + r"\*/" + re.escape(tail), "", source, count=1, flags=re.DOTALL
        )
    return source


def patch(source: str) -> str:
    base = remove_owned(source)
    for anchor, name in ((ROW_ANCHOR, "card-row"), (FN_ANCHOR, "verify-full")):
        if base.count(anchor) != 1:
            raise PatchError(f"{name} anchor occurs {base.count(anchor)} times; expected exactly one")
    base = base.replace(ROW_ANCHOR, ROW_ANCHOR + ROW, 1)
    return base.replace(FN_ANCHOR, FN + FN_ANCHOR, 1)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="verify the bundles already have the viewer; do not write")
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
            print(f"{bundle}: card receipt viewer missing or out of date", file=sys.stderr)
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
