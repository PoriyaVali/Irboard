# AI work log and handoff

## Active handoff - 2026-10-16 - Codex - WEB FIX PUSHED AS PR14; INHERITED PHPUNIT ERRORS VERIFIED

COMMITTED/PUSHED source0042e385ca9b0d7dce9cf88ce9a9e5a45deab0f3,
OPEN/unmerged https://github.com/PoriyaVali/Irboard/pull/14.
App counterpart sourcebe7142997dac029344e730b4e5b9602e4c5dca4e is pushed
to drmobile PR13; owner-requested joint report:
https://github.com/PoriyaVali/drmobile/pull/13#issuecomment-6218619533.
Both source commits authored by Codex; earlier app changes are Claude's.
This follow-up changes only AI_HANDOFF.md, recording publication/CI evidence.

CI run38922474180 for exact source0042e385: four jobs report success.
The new Admin prices retain thousands separators step PASSED in PHP8.1/8.2.
CAUTION: overall green does NOT mean the full PHP suite passes. Existing
Run tests (informational) uses continue-on-error and phpunit ||true. Actual
logs in both matrix jobs report80tests/390assertions/5ERRORS, all GoogleAuthTest
SQLite setup missing tablev2_plan. Read baselinefb1c64d CI run38027975879,
job114142786807: SAME5errors and summary, confirming these predate this fix.
No PHP source was changed; these inherited test-setup errors remain unresolved.
Do not claim full Laravel tests passed. Local price11tests/syntax/whitespace
validation passed as recorded below. No real database or production request,
browser deployment, merge or release performed. No source changes after the
validated source commit; handoff-only follow-up needs no product test rerun.

## Previous handoff - 2026-10-16 - Codex - GROUPED ADMIN PRICES IMPLEMENTED AND VALIDATED

Owner requested comma-grouped prices for readability, correction of the
reproduced web input issue, and a report on drmobile PR13. This repository
contains the separate web fix; it will be linked from that app PR.

Exact files: public/assets/admin/umi.js and umi-fa.js (identical bundles),
tests/AdminPriceInputTest.cjs, .github/workflows/ci.yml, and AI_HANDOFF.md.
Valid grouped Persian/Arabic/ASCII prices are normalized before the existing
web price conversion and form serialization. USD fractions retain their
value. Existing plan inputs open grouped and regroup on blur; all8Toman
table columns show grouping. Input onChange keeps visible text, so typing
does not lose the separators or have its cursor reset by formatting.

No backend, stored price unit, legacy display /100/submit x100 scale, FX rate,
rounding or database behavior was changed. Malformed grouping such as1,5
remains invalid; it is not assigned a decimal meaning. Valid comma removal
is intentional, not a vulnerability. Existing table records are cloned
before formatting the editor, so opening it cannot mutate table values.

Validation PASS: node --check on both bundles; node --test
tests/AdminPriceInputTest.cjs11/11. Harness executes actual bundled handler,
editor constructor, table render, plan model, and form serializer with a
recording HTTP adapter. Before source fix, initial9-test version had6failures
including valid thousands becoming NaN;3passed. Added real editor/table
coverage after the first9passed. Both bundles still byte-identical.
git diff --check PASS. No Laravel vendor directory here, so full PHP feature
suite NOT RUN; no PHP source changed. No real browser/server/database/payment
request, deployment, merge or release. GitHub CI has not run for this branch
at the time of this entry. Code is ready for commit/push and a linked PR.

## Previous handoff - 2026-10-16 - Codex - GROUPED ADMIN PRICES IN PROGRESS

Owner explicitly requested preserving visible comma thousands separators,
fixing the reproduced issue, and reporting it in drmobile PR13. Commas are
never decimal marks in this project's input contract. No price-unit, exchange
rate, rounding, deployment or database behavior change is authorized here.

Base fb1c64dfff6bdf73fc85433e814ac82b7c607e11, clean isolated clone inspected
before changes. No existing handoff or AGENTS file present. Own branch
fix/grouped-price-input. Planned source changes public/assets/admin/umi.js
and umi-fa.js, regression tests tests/AdminPriceInputTest.cjs, CI step
.github/workflows/ci.yml, and this record. The Node harness exercises actual
bundle onChange/model/form serializer with HTTP stubbed; no real requests.
New tests are being run against the old code before applying the fix.
No commit, push, deployment, database mutation, merge or release yet.
