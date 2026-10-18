# AI work log and handoff

## Active handoff - 2026-10-17 - Codex - reviewed backend locally committed; NOT pushed or deployed

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
