# AI work log and handoff

## Active handoff - 2026-10-19 - Codex - tailored/gift backend exact CI audited; not deployed, HOLD

Source COMMITTED/PUSHED0a40a87a85a67ba42b64033ac4c555593064687e atop0fbfa28
in existing OPEN/DRAFT PR15; priorgift4f572b0 retained. Actual CI39242808396
all4jobsSUCCESS. BothPHP8.1/8.2 mandatory87/634PASS. Informational FULLsuite
136tests/921assertions/5ERRORS on each; all5GoogleAuth missingSQLitev2_plan
names/errors match saved baseline. No wholePHPpass. All4checkoutsyntheticmerge
ac522ffd tree149ef006 exactly equals0a40a87 source tree; APIparentbinding proven.
Evidence ../audit/panel-tailored-ci/{SUMMARY.md,audit-result.json,4rawlogs}.
This follow-up is documentation only after validated implementation. Final mandatory
87tests/634assertionsPASS:33custom/316 +54existing/318. Actual final log
../audit/custom-plan-financial-final-delivery.log. All10changed/newPHPfiles
syntax PASS, gitdiffcheckPASS. Independent financial reviewer cleared final
source. Earlier UNSAFE/pending notes are preserved history, superseded now.
Full localPHPsuite NOTPASS: earlier128tests/827assertions/10errors,5known
GoogleAuth missingSQLitev2_plan and5localAPP_KEY/authfixtures; no whole-suite
rerun after final6controls, no broadPHPpass claimed.

Exact files: .github/workflows/ci.yml; AI_HANDOFF.md;
app/Console/Commands/{ActivateReservedPlans.php,CheckRenewal.php};
app/Http/Controllers/V1/User/{OrderController.php,UserController.php,CustomPlanController.php};
app/Http/Routes/V1/UserRoute.php; app/Models/Order.php;
app/Services/{OrderService.php,CustomPlanService.php};
database/{install.sql,update.sql}; tests/Feature/CustomPlanOrderTest.php.

Server reads eligible same-access-cohort canonical USD tariffs, infers median
normalizedA, preserves fixed shape, existing Toman flooring/thousands, creates
authenticated15minquotes and dedicated idempotent saves. TotalGB/exactdays,
reusedhiddenplan, snapshot delivery/reservation, capability bothcolumns+unique
index, no customgift. Actual3 financial prefails corrected and controlled:
currentcustom old ordinary surplus; custom->ordinary->ordinary oldhistory
revival; legitimate paid ordinary job delivered AFTER custom activation.
Boundary uses actual delivery time plus consumed same-secondIDs/frontier and
pending paid IDs; immutable commercial fields preserved. Reserved pending
orders remain globally excluded from credit. Cancelled late-paid refund and
existing gift/wallet paths remain covered. Full MySQL concurrency, real gateway
and live production schema unrun. App report records contract and evidence.

ActualfreshSQL52tables/update53/reapply53/upstreamcolumns checksPASS. Fresh
strictimport includes custom_keyUNIQUE DDL; upgrade does not explicitlyquery
thatindex and uses mysql--force. MandatorySQLite tests cover partialcolumns/
missinguniqueindexfailclosed, not liveMySQLconcurrency. Actualinstaller50tables/
admin200/protectedAPI403/aaPanelPASS; no individualnewfieldassertions. Both
dependency scans25advisories exactlypriorgiftset; unchangedcomposer.lock vs
baseline23scan, no remediation claimed. SourceandCIauditscomplete, no merge or
deployment. TWO additivecustomschema columns + prior3giftcolumns await future
authorized rollout. No productionDDL/payment/publish/updater; user HOLD persists.

## Previous handoff - 2026-10-19 - Codex - tailored-package backend IN PROGRESS for app2.2.4, HOLD

Latest user adds data-driven tailored tariff and changes app target2.2.4+149.
User confirms onlybasisAinferred; offset150/reference185GB/30days/alpha1.13/
beta.20 fixed. Source priorgift implementation4f572b0/docs0fbfa28 preserved in
draftPR15. Backend agent owns new service/controller/API/schema/order/reserved/
renewal/receipt/tests/CIchanges after reading durablehandoff/status/log. Parent
owns shared handoff. No new feature or validation PASS claimed yet.

Agreed dedicated GET/plan/custom, POST/plan/custom/quote, POST/order/saveCustom.
Read current canonical USD tariffs by same effective access/reset cohort,
infer mediannormalizedbase, quote price/currency server-side. New totalGB is
for exact tdays without monthlyreset. Hiddenreusedcustom accessplan +order
snapshot support exact paidfulfilment/reservation, immutable consent price,
user-bound15minquote/idempotenttrade and existing wallet/payment/cancel flows.
No publictariffrowperrequest. Generalpromocoupons obey actualcustomplanperiod
constraints; purchasedordinarygiftcards cannot become arbitrarycustomGB.
No customgift purchase thisscope; normalgiftfeature retained. Preserve existing
cancelled-order paymentreconciliation, exclude customPLAN-surpluscash minting.

No livepayment/productionDDL/deployment/merge/release/publicupload/updater.
Owner publication HOLD persists. PriorCI54/318mandatoryPASS plus5baselinefull
suiteerrors remain historical only for priorgift source; newcustomfinancial
tests/source/SQL/CIchecks are required before committing ascomplete.

UNSAFE NEW FINANCIAL PATH UNDER REPAIR (allnewsourceUNCOMMITTED): independent
review reproduced two phantom credit cases from exhausted ordinary history
while currentcustom and after custom->ordinary->ordinary. Author added real
prefail tests (expected1000actual0; expected200000actual150327) and is correcting
currentcustom surplus suppression plus an actual-delivery boundary, retaining
legitimate later ordinary/queued/late-paid rights and exact same-second order
chronology. Do not commit/deploy ascomplete while those regressions fail.
Earlier79mandatorytests/555assertions passed before these extra controls.
Full local suite128tests/827assertions/10errors consists of5knownGoogleAuth
and5knownlocalAPP_KEY/auth environment failures, not a fullsuitePASS.

Exactnewbackendfiles: .github/workflows/ci.yml;
app/Console/Commands/{ActivateReservedPlans.php,CheckRenewal.php};
app/Http/Controllers/V1/User/{OrderController.php,UserController.php,CustomPlanController.php};
app/Http/Routes/V1/UserRoute.php; app/Models/Order.php;
app/Services/{OrderService.php,CustomPlanService.php};
database/{install.sql,update.sql}; tests/Feature/CustomPlanOrderTest.php.
No production action, merge, commit or push of this custom feature yet.

## Previous handoff - 2026-10-18 - Codex - current PR15 CI39138995692 verified, NOT deployed

Documentation follow-up0fbfa28f9bc41139d7d1e6ce47e2cb7bfd669499 is COMMITTED/
PUSHED and remains the OPEN/DRAFT PR15 head. Only AI_HANDOFF.md changed after
implementation4f572b0. LatestCI39138995692 all4jobs SUCCESS on currentPRhead;
both PHP8.1/8.2 logs again verify mandatory54tests/318assertions PASS and actual
full suite103tests/605assertions/5samebaselineGoogleAuth missing-v2_plan errors.
Evidence ../audit/panel-posttest-ci/latest-ci-39138995692.md. This new status
entry alone is UNCOMMITTED documentation, not new application source.

No merge, live database update, payment, deployment, public release/upload or
updater activation. Owner publication HOLD persists. App paired draftPR15 at
5d357d42 is running unpublished test148 CI39139130147; do not infer that build
has passed yet. Gift rollout and real concurrency/gateway testing remain pending.
Separate pricePR14 is preserved. Previous validation/risks remain below.

## Previous handoff - 2026-10-18 - Codex - mandatory financial CI PASS; full suite retains5baseline errors, HOLD

Review PR15 remains OPEN/DRAFT at pushed ea184ca (implementation4f572b0), not
merged/deployed. Independent CI39137931184 audit verified all4jobs succeed;
checked-out merge23cee56484a31b8ec14d64ede1a4c89ebb84cfbb tree exactly matches
PR head. Mandatory54financialtests/318assertions PASS on PHP8.1 and8.2,
including23gift cases. Informational full suite has103tests/605assertions and
5GoogleAuth SQLite missing-v2_plan errors on BOTH versions. Baseline38027975879
has80tests/390assertions and the identical5errors. Existing continue-on-error
does not make that full suite passing; no claim of fullPHP PASS.

MariaDB fresh schema52tables, update/reapply53; upstream schema comparison
passes, bothSQL files include all3gift columns and unique coupon link. Actual
installer50tables matches baseline50, but does not explicitly assert gift
columns; difference vs direct SQL52 remains unexplained/investigated only.
Route/model/runtime audits pass with existing exclusions. Dependency scan now
reports25advisories vsbaseline23 with UNCHANGED composer.lock (new scan IDs
CVE-2026-108082/CVE-2026-109413); no dependency changes/remediation claimed.
Evidence is in ../audit/panel-posttest-ci/ and its separate audit handoff.

Exact new local change is this UNCOMMITTED documentation entry only. No
production code after tested4f572b0, live database/DDL/payment/deployment,
merge, release, public upload or updater activation. User publication HOLD
continues; app test148 is being finalized in sibling checkout. Gift capability
requires later authorized rollout of backend plus3additive columns. Real
MySQL concurrency and live gateway behavior remain unrun. Separate pricePR14
is preserved unchanged.

## Previous handoff - 2026-10-18 - Codex - review PR15 pushed; NOT merged or deployed

Parent pushed implementation4f572b0656859ce2ac47b24a6f370bf1655d21c8 plus
documentationea184ca18eba85358d6e9bc04030f1330b557872 to
fix/posttest-gift-orders and created OPEN/DRAFT
https://github.com/PoriyaVali/Irboard/pull/15. Exact source/test/SQL/CI files
remain as validated below (54 strict tests / 318 assertions; 13 PHP syntax
checks). This status entry alone is a new UNCOMMITTED local documentation
change; no production source changed after that tested implementation.

CI is pending, not claimed passing. No merge, production migration/DDL,
deployment, gateway/card payment or app publication occurred. The owner's
publication hold persists. Separate grouped-price PR14 is unchanged. Parent
is completing app test148 and final connection validation; backend deployment
requires later authorized rollout of the additive schema and application code.

## Previous handoff - 2026-10-17 - Codex - reviewed backend locally committed; NOT pushed or deployed

Parent reviewed the final contract and financial diffs, then created scoped
implementation commit 4f572b0656859ce2ac47b24a6f370bf1655d21c8 on
fix/posttest-gift-orders. All 17 feature/test/SQL/CI/handoff files are committed;
this status entry is a later UNCOMMITTED documentation update. The exact tested
source is unchanged: 54 strict financial tests / 318 assertions pass, PHP syntax
passes for all 13 changed/new PHP files, and whitespace checks pass. Earlier
environment-dependent expanded test errors remain recorded below.

No branch push, PR, merge, live payment, production database update, deployment,
or release has occurred. User's publication hold remains active. Main and the
separate grouped-price PR14 are unchanged. The app uses dedicated saveGift and
requires explicit schema capability, so older servers cannot silently charge
the purchaser for an ordinary subscription. Parent is finishing the final app
background-start regression before preparing a new unpublished test build.

## Previous handoff - 2026-10-17 - Codex independent backend review - UNCOMMITTED; publication/deployment HOLD

Objective: review and finish the prior Codex agent's purchased-gift source, preserving
all source in this isolated fix/posttest-gift-orders checkout at fb1c64d. The prior
implementation below is inherited work, not newly authored by this review. No commit,
push, release, production database/migration, deployment, or live payment occurred.
Separate grouped-price branch/PR14 remains untouched.

This review changed app/Http/Controllers/V1/User/OrderController.php,
app/Http/Routes/V1/UserRoute.php, app/Http/Controllers/V1/Guest/PaymentController.php,
tests/Feature/PurchasedGiftOrderTest.php, app/Services/GiftOrderService.php,
app/Models/Order.php, database/{install,update}.sql, .github/workflows/ci.yml,
and this AI_HANDOFF.md.
POST /api/v1/user/order/saveGift forces is_gift=true server-side and delegates to
validated gift creation. App clients must use this unique route and never fall back
to ordinary /order/save: older mixed-rollout panels may silently ignore is_gift on
ordinary creation. The dedicated route remains schema-gated (503; no charge/order).
App payment callback links append is_gift=1. Corrected an independently reproduced
existing callback defect: HTML-escaped ampersands were reused inside JavaScript,
so automatic redirects lost trade_no and gift identity. HTML attributes stay escaped;
JavaScript now embeds a JSON-encoded URI with script-safe hexadecimal escapes.

Validation during review: initial strict focused PurchasedGiftOrderTest,
OrderMoneyFlowTest, TelegramBotFixesTest, ExchangeRateTest PASS 47 tests / 252
assertions. New dedicated-endpoint regression failed before implementation with
404; callback gift query regression failed before flag; callback URI parsing
regression then independently failed because trade_no was missing. Final targeted
three tests PASS 3 / 27. New card-claim safety regression passed on inherited code:
existing bot-wide pending-card guard already prevents replacing a paid-but-unverified
order with a gift coupon; no redundant money-path patch was added.
An expanded local run including AccessAndPaymentFixesTest FAILED 60 tests / 278
assertions / 5 errors: four AuthService JWT errors due missing local APP_KEY and one
bot panel redirect fixture error, unrelated files untouched. No Redis service is
running locally. These expanded environment failures remain unresolved and are not
claimed fixed. The final focused result below supersedes earlier focused counts.
SQLite :memory: test isolation is explicit in phpunit.xml. MySQL concurrency,
real gateway/card administrator/browser/device behavior remain UNRUN.


Further review fixes: a real late-payment bug in Guest PaymentController::handleOrder
returned success for cancelled status2 before OrderService could reconcile funds.
New controller-level regression failed (status2, wallet40k instead of status4,
wallet100k after verified60k gateway share). Cancelled callbacks now go through
paid(); repeated callbacks remain idempotent. App/Telegram receipt text for status4
correctly explains wallet credit, without promising a gift code.
Owner requirement for persistent code receipts also failed before correction when
the coupon was deleted. New hidden gift_code_snapshot is written transactionally
with issuance and exposed only as owner-scoped gift_code after status3. Deleted
coupon keeps receipt/code copyable with gift_status=unavailable; it is never reissued.
Both install/update SQL include snapshot; capability requires all three gift columns.
Receipt-schema regression exposed a partial-DDL hazard: gift source recognition
also depended on snapshot readiness, so existing purchased coupons became ordinary
coupons and could stack VIP/surplus cash. Test failed (is_gift=false). Recognition
now needs only the source-link columns, independently of full purchase capability;
targeted regression passes with no recipient cash. CI focused gate now also fails
on PHPUnit warnings and risky tests. Recipient-fulfilment rollback test passes,
restoring the coupon use/order/user atomically on an injected failure.
Final validation PASS: php vendor/bin/phpunit --no-coverage --fail-on-warning
--fail-on-risky --filter 'PurchasedGiftOrderTest|OrderMoneyFlowTest|TelegramBotFixesTest|ExchangeRateTest'
completed 54 tests / 318 assertions, exit0, on SQLite :memory:. This includes all
23 purchased-gift tests and the existing financial/Telegram/exchange regressions.
PHP syntax PASS for all 13 changed/new PHP files; git diff --check PASS.
PHPUnit reports the existing deprecated XML schema notice; no PHPUnit test warning
or risky test occurred. No full-suite pass is claimed; no live payment, MySQL
concurrency, production DDL, deployment, or device/browser payment test was run.
Backend source is ready for parent review and remains entirely UNCOMMITTED.
No known unresolved defect was found within the reviewed purchased-gift paths.
Publication/deployment HOLD remains explicit; parent must review before commit.

Inherited uncommitted source also includes .github/workflows/ci.yml;
app/Http/Controllers/V1/User/{CouponController,PlanController,UserController}.php;
app/Http/Requests/User/OrderSave.php; app/Models/Order.php;
app/Services/{CouponService,GiftOrderService,OrderService}.php;
app/Services/Telegram/TelegramBotService.php; database/{install,update}.sql.
Read the older entry for design/contract, but its progress counts are historical.

## Previous handoff - 2026-10-17 - Codex - purchased gifts implemented locally; publication and deployment HOLD

User reported seven post-test issues and requested friends/family gift purchases.
Parent agent owns Flutter and release coordination. This isolated worktree is on
fix/posttest-gift-orders from latest origin/main fb1c64d (explicit fetch verified),
not the older fix/grouped-price-input branch. The separate price PR14 remains
unmerged and untouched. No push, merge, release, database update or live payment.

Current source is UNCOMMITTED. Exact files: app/Services/GiftOrderService.php;
app/Services/{CouponService,OrderService}.php; app/Models/Order.php;
app/Http/Requests/User/OrderSave.php; app/Http/Controllers/V1/User/
{CouponController,OrderController,PlanController,UserController}.php;
database/{install,update}.sql; tests/Feature/PurchasedGiftOrderTest.php; this file.

Chosen contract: is_gift=true on ordinary order/save; gifts charge the server's
published plan/period tariff with optional wallet payment, no purchaser VIP,
coupon or existing-plan surplus benefits. Only subscription periods qualify.
After successful paid settlement, row-locked transactional fulfilment creates
exactly one cryptographically random one-use 100% coupon restricted to that
plan and period. Purchaser's subscription remains untouched. Owner-scoped
order fetch/detail expose gift_code, gift_code_type=coupon and gift_status;
no code before status3. Purchased gifts are excluded from future surplus credit.
Cancel refunds only wallet money and issues no gift. A late payment after a
mixed-wallet cancellation credits the gateway portion without gifting again.

Plan/fetch adds top-level gift_purchase_enabled only when schema columns exist;
app must require explicit true and recheck before gifting. Existing coupon/check
now accepts period and returns is_gift. Purchased coupons require exact source
plan/period even during preview, skip recipient VIP stacking, and work either
through normal coupon checkout or existing redeemgiftcard. Direct redemption
creates/fulfils a normal recipient order and preserves existing active plans by
reservation. Cancelling an unpaid gift redemption restores its one use once;
late payment of that cancelled zero-price order cannot deliver it again.

Validation so far: composer install from committed lock (119 packages, no
composer files changed); local PHP8.5.8 lacks pcntl/posix/zip, so those platform
requirements ignored for local dependency install only. No vendor files tracked.
PHPUnit uses existing mandatory SQLite :memory: isolation. Initial new tests
needed a cached auth-session fixture because no local Redis; no production auth
was changed. New PurchasedGiftOrderTest PASS 9 tests / 96 assertions. Existing
OrderMoneyFlowTest PASS 9 / 37 before the final focused suite. Tests cover full
wallet payment, server price, no purchaser activation, unpaid forgery, callback
and stale-job replays, owner visibility, exact plan/period, recipient replay,
active-recipient reservation, VIP/no wallet cash, cancellation restoration and
late callback, no recursive discounted gifting, and capability/schema fail-close.

Unresolved work: finish card-to-card integration, schema/receipt persistence,
rollback atomicity and core money-path regression validation; review source
carefully before commit. True concurrent MySQL transactions and production
gateway/admin/browser behavior are not tested locally. Existing full-suite
GoogleAuthTest SQLite setup errors from baseline are not claimed fixed.
