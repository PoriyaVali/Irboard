# AI work log and handoff

## Active handoff - 2026-10-21 - Codex - both gift P1s FIXED/LOCAL PASS; CI required before merge

User authorized finishing all GitHub sync/merges. UNCOMMITTED runtimefix exactfiles:
app/Services/{GiftOrderService,CouponService,OrderService}.php;
app/Http/Controllers/V1/User/{OrderController,UserController}.php;
app/Console/Commands/ActivateReservedPlans.php; tests/Feature/PurchasedGiftOrderTest.php;
this handoff. Four regressioncases failed BEFORE changes, passAFTER. Latestrequired
set133tests/1102assertions PASS on LOCALPHP8.5.8, not8.1. Addedcouponcheckout +partial
schemarefusal beforemoney/transaction regressions. No newDDL/pricing/comma/formula
change. See ignored ../audit/github-complete-{gift-before,gift-after,panel-final}.*.

Fixed gifts now capture original commercialterms at save before payment, in typed
kind=fixed_gift snapshot in existing hidden custom_snapshot JSON. ALL couponuse
paths copy snapshot. ExistingplanIDs retained whencontractunchanged; edited/deleted
plans get hidden gift- keyed templates (existinguniqueindex), no mutation ofpublic
plans. Fulfilment and queuedactivation resolve frozenquota/name/group/device/speed/
reset/carry; summary reports originalterms. Fixedtemplates are NOT labelledtailored
in userresponse. Onetimequeuedactivation uses expired_atNULL, replay doesnotreset
usage. Newgiftcreation fails503 beforewalletcharge if snapshot schema/index partial.
Legacypaidfixedgifts with NO historicalsnapshot retain pre-existingliveplanbehavior;
original deleted/edited historicalterms cannot be magically reconstructed. Existing
zero-cash/coupon-once/buyerreceipt semantics retained byregressions. No runtime
productiondeploy or customertariff/data writes in this GitHubtask. Pushreviewfix,
wait freshPHP8.1+8.2 mandatoryCI, reply+resolveactualP1threads, then guardedmerge.

## Active handoff - 2026-10-21 - Codex - backend P1 reproduction/fix; OpenWrt modules recovered

appPR15 successfully MERGED at guardedhead4c27ff06 to mainedaf4b25 (GitHub result).
Backend P1 regressions reproduced BEFORE code changes:4tests/19assertions/4FAIL
(purchased quota/name changed, deletedplan500, queueddeletedplan neveractivated,
onetime activated expirednow). After fix4PASS/39assertions; requiredfinancialset
131PASS/1090assertions on currentdefaultPHP8.5.8 (NOT productionPHP8.1 yet). Exact
logs ../audit/github-complete-gift-{before,after} and panel-mandatory. Additional
coupon-checkout+partialschema tests added, pending final rerun/CI8.1+8.2.

UNCOMMITTED exact backend runtime files: GiftOrderService typed fixed_gift snapshot
in existing hidden custom_snapshot JSON; capture purchase-time quota/name/access/
reset/carry, preserve ordinary IDs iftermsmatch, hidden gift- template ifedited/
deleted; CouponService attaches same snapshot on ALL couponuse paths; OrderService
uses frozenplan; ActivateReservedPlans re-resolves underlocks and onetimeexpiryNULL;
OrderController snapshot beforewalletcharge +earlyfullschemagate; UserController
keeps fixedtemplate distinct from tailoredUI. PurchasedGiftOrderTest adds meaningful
regressions. NO newDDL or changes to comma/thousands/priceformula. Legacyfixedgifts
without historicalsnapshots keepexistingbehavior; oldterms cannot be reconstructed.
No productiondeployment/newpayment/tariffchange. DO NOT mergebackend beforefinalCI.

Root inherited20modulefiles copied ONLY into isolatedOpenWrt source dirs with LF;
rootpreserved. MultiWANWindowsrace/vet/LinuxARM64build/LinuxAMD64testcompilePASS.
DisposableownedVMresumed PID12864 using exactprioroverlay +secondusrQCOW; /usr
bindmounted frompersisteddisk. Nativekernelintegration justexecuted; inspectresult
beforeclaimpass. LiveNanoPi unchanged. Need moduleCI/syntax/patchchecks, validation
and sources update, commit/push/OpenWrtPR/merge; backendP1threadreplies/resolution
onlyafterpublishedfix. CurrentcodechangesUNCOMMITTED, no unsafecode shipped.

## Active handoff - 2026-10-21 - Codex - all GitHub synchronization/merges AUTHORIZED; backend P1s discovered

User explicitly requested complete correct synchronization of ALL remaining work
including inherited MultiWAN/WiFi modules and main merges. Fresh read/status/logs
read across root/OpenWrt/panel; preserve inherited files/authorship. Source appPR15
head4c27ff06 verified semanticdiff onlydocs fromtested0dc6662, CI39553978590testSUCCESS,
no reviewthreads. Guarded expectedhead MERGE succeeded: mainedaf4b25d8b56257f1c66ca9fc8b8385666d1272.
No release/tag/updater write, no productcode changed by this merge.

IMPORTANT: fresh backend reviewthread query found TWO unresolved P1 comments on
850f998 GiftOrderService: fixed gifts lack immutable entitlement on edited/deleted
plans; reserved onetime gift becomes expirednow in reserved:activate. They were
not surfaced by previous source-sync handoff and MUST be reproduced/fixed/tested
before backendPR15merge. Backendmain remainsfb1c64d. Runtime fix NOT yet made;
no productionchange this turn. Investigating safe snapshot path including existing
payment/coupon-route and queued activation; no comma-number contract changes.

Inherited root openwrt/multiwan + wifi-scan-fix were installed Oct15 per durable
records (reconstructed earlierwork), not new code authored this turn. Will recover
into isolatedfix/openwrt-2.2.5, review and run unit/race/nativekernel+syntax checks
before commit/push/PRmerge. Real router must stayconnected; no need to reinstall
alreadymatchingmodules merely for GitHub. Current .1 core deployment remains as
previous validated. No delegation. Current work UNCOMMITTED until stated below.

## Active handoff - 2026-10-21 - Codex - backend source synchronized; required CI PASS on PHP8.1/8.2

Owner-authorized source sync COMPLETE for runtime commit
850f9989df3c9f339b835709d7617c7d9ce8d06f, PUSHED to fix/posttest-gift-orders.
PR15 is ready for review, updated with current scope and validation, NOT MERGED:
https://github.com/PoriyaVali/Irboard/pull/15
Existing main remainsfb1c64d. This entry is a documentation-only checkpoint;
no runtime files or production state changed after the tested feature commit.

Fresh GitHub CI39552754029 SUCCESS at EXACT850f998:
https://github.com/PoriyaVali/Irboard/actions/runs/39552754029
All4jobs SUCCESS: PHP8.1, PHP8.2, installer, install.sql schema. Mandatory regression
127tests/1051assertions PASS on BOTH PHP versions. Real Chromium admin21PASS/0FAIL.
Local PHP8.1 same127/1051PASS. Fresh INFORMATIONAL whole-suite result on BOTH PHP
versions:176tests/1338assertions/5ERRORS, same unrelated GoogleAuth SQLite fixtures
as prior baseline. Required CI is green; this is NOT a whole-backend test pass.
Log ../audit/github225-panel-ci.log; no real payment or concurrent MySQL test.

Exact runtime/test changes in850f998: app/Http/Controllers/V1/User/UserController.php,
app/Services/GiftOrderService.php, tests/Feature/CustomPlanGiftOrderTest.php and
tests/Feature/PurchasedGiftOrderTest.php, plus this handoff. Additive active/reserved/
processing receipt metadata was ALREADY LIVE since152; no deployment, schema,
settings/reference approvals, tariff changes or customer writes in this task.

Paired app sourceeb00ef0d PUSHED to drmobile PR15 (ready, not merged), version
2.2.5+156, local full1361PASS/2SKIP/0FAIL. Owner already published binaries, all5
hashes matched final local156. No new release/tag/updater writes. Prior author
identities retained; new commits authored Codex. No force push or delegation.

## Active handoff - 2026-10-21 - Codex - receipt source PUSHED; PR15 ready; fresh CI running

Commit850f9989df3c9f339b835709d7617c7d9ce8d06f (Codex) is PUSHED to
origin/fix/posttest-gift-orders. Exactly five files: this AI_HANDOFF.md;
app/Http/Controllers/V1/User/UserController.php; app/Services/GiftOrderService.php;
tests/Feature/CustomPlanGiftOrderTest.php; tests/Feature/PurchasedGiftOrderTest.php.
No newly implemented runtime behavior this turn: this records the metadata patch
already LIVE since152. No redeployment, schema, setting/tariff or customer changes.

https://github.com/PoriyaVali/Irboard/pull/15 is UPDATED with final tailored-admin,
gift-card and receipt scope and READY FOR REVIEW, not merged. Main unchanged;
unrelated PR14 remains open. Owner explicitly authorized source synchronization;
no force push/history rewriting and previous authorship preserved. App source
commit eb00ef0d is also pushed; app PR15 ready with version2.2.5+156. Owner already
published2.2.5 binaries; no binary/tag/updater changes byagent here.

Local PHP8.1 mandatory regression127tests/1051assertions PASS (16.893s), deprecated
PHPUnit XML-schema notice only. Current app full1361PASS/2SKIP/0FAIL. Diff and
high-signal credential scans PASS. GitHub CI39552754029 at exact850f998 RUNNING;
PHP8.1, installer and schema already SUCCESS, PHP8.2 completing. Full backend suite
not rerun locally; historical unrelated GoogleAuth fixture failures documented
below. Do not claim whole-suite pass. Final CI result and final documentation commit
pending. No physical device, actual payment or true MySQL race test performed.

## Active handoff - 2026-10-21 - Codex - GitHub sync authorized; gift receipt source ready

Owner approved the app work and requested source synchronization on GitHub. The
previous GitHub hold is lifted for completed app and related backend source. No
server deployment, binary release, tag or updater change is part of this task.

Exact pending backend files: app/Http/Controllers/V1/User/UserController.php,
app/Services/GiftOrderService.php, tests/Feature/CustomPlanGiftOrderTest.php,
tests/Feature/PurchasedGiftOrderTest.php, and this AI_HANDOFF.md. The two runtime
files are the metadata-only patch already LIVE as recorded below; no new product
code changes in this turn. Additive receipt reports real plan/quota and fulfillment
state; failed optional reads cannot undo an already committed redemption.

Validation rerun on PHP8.1: mandatory financial/custom/gift set127tests and1051
assertions PASS; log ../audit/github225-panel-mandatory.log and exit0. Deprecated
PHPUnit XML-schema notice remains. Full backend suite not rerun; prior unrelated
GoogleAuth SQLite fixture errors remain a known limitation. App156 frozen source
matches1,011 build-time hashes. Existing remote PR15 is a draft atd4a9999, matching
local HEAD. Branch had a main-only fetch refspec; fetched its feature ref explicitly
without changing the refspec. No AGENTS.md here; root coordination rules apply.

Status: these five files UNCOMMITTED; commit/push and fresh PR CI pending. Existing
owner and other-agent authorship preserved. No force push/main merge or delegation.

## Active handoff - 2026-10-20 - Codex - FINAL152 verified241/241; stale Android AOT RESOLVED; Laravel metadata LIVE; GitHub/public app HOLD

Supersedes the Android-staleness correction and the earlier premature222-check
completion entry below. Actual current out/test152 APKs ARE fresh and ready for
OWNER testing. Prior stale152 APKs and their oldchecksums were moved OUT of the
release folder into ignored audit/test152-rejected-stale-aot/ (all preserved).
Version remains unchanged: app is2.2.5+152, original151files remain in out/ unchanged.

Android152 first reused151 AOT despite current source/version152. Detected before
final delivery by new binary literal inspection, not by signatures/manifest alone.
Preserved .dart_tool/flutter_build as flutter_build-stale152 and build/app/intermediates/
flutter as flutter-stale152. Forced exact previously logged Gradle assembleRelease
arguments with --rerun-tasks --no-build-cache; same local Maven/signing/native inputs.
Actual fresh build192s,945/945tasks EXECUTED,EXIT0 (test152-android-forced-build.*).
New libapp.so includes gift-redemption-result in BOTHABIs; prior151does not. Current
local152 inspector adds19 freshness/feature checks for both AndroidABIs and Windows;
original release/Actions strict inspectors remain unchanged. Final ACTUAL5files
PASS241/241,0FAIL (test152-verification.json/.log/.exit0). Source802files unchanged.
Before-freshness222-pass report retained under test152-verification-before-freshness.*;
explicit invalid-for-delivery proof test152-stale-aot-rejection.json. Do not use those
old hashes. Windows152 was already fresh and required no second Windows rebuild.

Completed feature files/tests/server details in earlier152completion handoff remain
valid: account_actions.dart, new model/gift_redemption.dart,4translations, pubspec152,
profile tests and NEW docs/testing/2.2.5-152-gift-results.fa.md. Persistent readable
receipt gives actual plan name/GB/duration and active/reserved/processing state from
Laravel; old/unknown purchased gifts NEVER mislabel value0 as unlimited. Confirmation
appears before slow profile sync; account/dispose guards intact. FullFlutter1316PASS/
2SKIP, finalprofile14+visual2PASS, targeted124PASS; analyzer0errors/30baselinewarnings/
1126infosEXIT1 (not clean). Laravel mandatory127tests/1051assertionsPASS and gifts45/
470PASS onPHP8.1; unrelated broad112test run1ERROR missing Redis (initially APP_KEY).
No real gift redemption/payment/device/installer test was performed.

Laravel metadata2filepatch IS LIVE on91.107.255.204, exact hashes/backups/API runtime
and HTTP200/403 checks in prior152completion entry and gift152-health-final.log.
No DB/schema/tariff/fulfilmentchanges. SIGUSR2 API drain needed idle closed-counter
cleanup via SIGINT ONLY already-unlistening/noHTTPsocket/CPU-idle children; fresh4
APIworkers confirmed. No Horizon/config-agent restart, no SIGKILL/activeHTTP termination.
Do not describe this as pure SIGUSR2-only exit. Deployed source remains UNCOMMITTED
patch atop e9c7456; no new commit/PR/Actions/tag/updater/public app release. RootHEAD
 ef32475/app9e8ed9c/paneld4a9999 unchanged; inheritedrootopenwrt preserved.

FINAL ONLY delivery artifacts in app/out/test152/:
- DoctorMobile-2.2.5-android-universal.apk 126399327 bytes SHA256 ce03c0f1041171e2febb04a8b5c7782494db7fcd50ff26194b4e4574fb0fd21e
- DoctorMobile-2.2.5-android-arm64-v8a.apk 68489189 bytes SHA256 a2457d808511906f01cd48067a9cf23fdd23f0a41f2e78bfc01f001131a79b4e
- DoctorMobile-2.2.5-android-armeabi-v7a.apk 68257753 bytes SHA256 596ecf5cd48498d48ab499f4600c213206c03dae4cab510ac2e0f5568471f06b
- DoctorMobile-2.2.5-windows-x64-portable.zip 73360218 bytes SHA256 6b85218d6a6e5f02702fd3479b14d19268423a46823daad0fd5f4a5442abb794
- DoctorMobile-2.2.5-windows-x64-setup.exe 48338366 bytes SHA256 c6e28a493f4bf64242939b061a95b00400602dae879208920915dd6ea0c7c09c
DoctorMobile-2.2.5-SHA256SUMS.txt regenerated from these exact5verifiedfiles. All3APKs
original signer a3e2fd30008124bc2358c084fd8907b5fa9c179266088a995bf830a400d702c4,
appIDapp.drmobile.com/version2.2.5/code152. Windows runner2.2.5+152/original installer
identity; Windows has no Authenticode signature, matching baseline. Next step OWNER
manual test only; GitHub/public app release stays HOLD until user authorizes.

## Active correction - 2026-10-20 - Codex - Android152 STALE AOT detected; artifacts REJECTED pending clean rebuild

Do NOT deliver out/test152 APKs until this correction is superseded by VERIFIED
fresh Android binaries. The prior222checks passed signatures/versions/native code
but missed a stale Dart AOT snapshot:152 libapp.so was byte-identical to151 and
lacked gift-redemption-result, although current Windows app.so correctly contains
it. Additional literal feature inspection caught this BEFORE final delivery.
All151 Android features ARE present (tailored-open-editor/payment-as-gift), so151
itself was not rejected. Android Flutter/Gradle cached AOT did not track source
changes correctly in this Windows workspace. Preserve old cache and rejected152
APKs; force clean Flutter AOT and Gradle --rerun-tasks --no-build-cache, then require
new feature literals in BOTH Android ABI app.so plus all prior222checks. Source802
snapshot unchanged. Laravel metadata patch IS LIVE and healthy; no server rollback
needed. Windows152 binaries remain valid. GitHub/app-publication HOLD remains.

## Active handoff - 2026-10-20 - Codex - gift-card result COMPLETE; Laravel metadata LIVE; 2.2.5+152 local files VERIFIED; app/GitHub HOLD

User requested Persian gift-card label, readable registration confirmation, exact
plan name and real active/reserved state. ALL prior151 work preserved; local version
still2.2.5, build152 allows Android upgrade. NO commit/push/Actions/PR/tag/app release
or updater changes. AppHEAD9e8ed9c and panelHEADd4a9999 remain unchanged; new patch
is UNCOMMITTED. RootHEADef32475/openwrt preserved. Original151 files remain in out/;
ACTUAL new152 files are in app/out/test152/. Do NOT confuse their same version names.

NEW app files: lib/features/profile/model/gift_redemption.dart (typed strict server
receipt parser), lib/features/profile/widget/account_actions.dart (gift result dialog,
plan/volume/duration/status; immediate confirmation before optional profile fetch;
session/dispose guards retained),4assets/translations/{fa,en,ru,zh-CN}.i18n.json
(new result text, Persian label and confirmation changed to card-e hediye), ignored
generated Slang files, pubspec.yaml=2.2.5+152; profile test file
 test/features/profile/account_actions_dispose_test.dart. No display of placeholder
purchased-gift type5/value0 as unlimited. Old/invalid/missing metadata confirms code
acceptance and asks user to inspect subscription/reserved plans, never to redeem again.
Legacy credit/days/GB/reset/direct-plan cards retain their actual result descriptions.
Persistent result includes activated/reserved/processing/unknown, no status guessing.

NEW backend files: app/Services/GiftOrderService.php (optional authoritative receipt
summary, after committed redemption; failures returnnull),
app/Http/Controllers/V1/User/UserController.php (additive redemption object for purchased
and legacy plan cards; data/type/value/trade_no retained; method_exists guards mixed
old/new worker classes). Tests modified PurchasedGiftOrderTest.php,
CustomPlanGiftOrderTest.php. No schema, fulfilment, billing, coupon or pricing changes.

Validation: targeted app124PASS, full1316PASS/2SKIP/0FAIL (test152-full.log/.exit0).
Final Polish: profile14 +actual Persian demo screenshots2=16PASS; final active/reserved
screenshots .dart_tool/gift152-fa-{active,reserved}.png reviewed, no live purchases.
Final analyzer0errors/30baselinewarnings/1126infos EXIT1 due lint findings, not clean.
Laravel on official SHA256-verified portable PHP8.1.34: gifts45tests/470assertionsPASS;
exact mandatoryCI set127tests/1051assertionsPASS INCLUDING final method_exists guard.
No GitHub CI run represented. Tests pinned SQLite:memory, no productionDB access.
Additional broad112tests had1environmentERROR in AccessAndPaymentFixesTest::
testACardPaymentCannotBeClaimedForACancelledOrder: absent localAPP_KEY, then (after
supplying process-only test key) absent PHPRedis extension. NOT an all-backend pass;
failed logs retained. Initial legacy fixture lacked mandatory started_at/ended_at,
fixed fixture and rerun45PASS. First translation patch mismatched RU/ZH source text,
failed atomically; corrected UTF8 patch/regeneration passed. PHP syntax2filesPASS.

LIVE main-server PATCH (earlier user explicitly authorized Laravel deployment;
latest hold concerns GitHub/public app release). Exact baseline live2file hashes
matched HEAD before writing. Only additive response presentation installed on
91.107.255.204 /www/wwwroot/ddr.drmobilejayzan.info. Root-owned0644 preserved.
Private payload/script/backups+metadata/journal under
/root/drmobile-deploy-20261019-tailored-v150/gift152-response-20261020/; source backups
private0700/0600. No .env/composer/config/routes/Umi/tariff/DB changes or test orders.
LIVE fileSHA256:
GiftOrderService.php 291b9d55e4165c575a371ac82a21a60513973d4700df10af3a0f3eb91d1bf5d1
UserController.php 80efc01214ebc295287e3406223350bbc8f685d9d0a9fc35eea35fdf5df78249
This is an UNCOMMITTED2file deployed patch atop e9c7456, NOT a new deployed commit.

RUNTIME: public main Nginx uses PHP8.1FPM; source timestamp validation preserved.
WebMan master3228410 also reloaded to ensure alternate API runtime fresh. Verified
installed Workerman5.2.2 SIGUSR2 contract before signaling. Pure graceful reload
stalled because AdapterMan retained closed-connection counters (20-43 per idleworker)
while actual/proc TCP HTTP sockets were gone. For EACH old HTTP child, verified
already-unlistening +zero actual HTTP sockets +1sCPU idle before SIGINT to end only
that drained child; master replaced it before draining next. No SIGKILL, no active
HTTP connection terminated, no Horizon/config-agent restart; do NOT claim every
child exit used only SIGUSR2. Old3228635-38 fully gone; newHTTP3393990,3394074,
3394089,3394117. Master3228410/Horizon3228409/config-agent1624741 retained andRUNNING.
Final HTTPS/SNI guest200; anonymous redeem403; exact installedhashes/ownersPASS.
Logs gift152-{deployment,finish-drain,health-final} and journals retained. Read-only
preflights first missed supervisorctlPATH and Worker.php moved tosrc; corrected.
Server private deployment script asserts old hashes, exact2paths, PHPsyntax, owners,
and Worker SIGUSR2 semantics before atomic replacements. Controller method_exists
protects in-flight mixed classes. No new public endpoint or financial mutation.

BUILD: local Windows86.6s, Android83.0s, Inno46.406s allEXIT0. Original Android signer
on ALL3APKs verified a3e2fd30008124bc2358c084fd8907b5fa9c179266088a995bf830a400d702c4,
appIDapp.drmobile.com, version2.2.5/code152. Windows runnerFile/Product2.2.5+152,
installer2.2.5/originalAppId; Windows historically NOT Authenticode signed. Reused
EXACT verified151 native inputs and pinned source, no new native compilation claim.
Process-only ignored local Flutter Maven cache reused from151, no global config edits.
All ACTUAL5files passed222/222 LOCAL binary/source/native/signature/runtime/import/
staging checks,0FAIL: audit/test152-verification.json/.log/.exit0. Source snapshot
802files includes NEWuntrackedmodel +ignored Slang outputs, unchanged after builds.
Original strict release/Actions inspectors UNCHANGED. New verify_local152.py remains
explicitly local/unpublished and binds prior native151 serial build evidence.
SHA256SUMS regenerated in out/test152. No physical device, real redemption/payment
or Windows installer execution; owner manual test still required. GitHub/public
app release HOLD continues until user approves after testing.

## Active handoff - 2026-10-20 - Codex - gift-card wording and authoritative redemption result IN PROGRESS

User asks Persian label card-e hediye and clear plan name + active/reserved outcome
on redemption. Preserve ALL uncommitted151 work and verified151 artifacts. GitHub,
app release and updater HOLD persists. Build refresh will stay2.2.5, increment152
so installed151 can update. No new commit/push/publication authorized by this turn.
Investigated actual Laravel controller: purchased gifts return type5/value0/trade_no,
which app wrongly labels unlimited; neither detail nor reserved endpoint reliably
exposes exact redemption state. Need additive redemption metadata from Laravel,
without changing fulfilment/transaction/coupon economics. App must accept older
responses and never infer active/unlimited for an unknown purchased gift result.
Planned files: app account_actions.dart, new gift_redemption model,4translations,
focused profile tests/pubspec; backend GiftOrderService.php/UserController.php and
purchased/custom gift response tests. Persistent readable result dialog; session/
dispose guards retained. Backend/app changes and builds NOT YET validated at entry.
Previous main-server deployment authorization exists in user history; assess final
safe metadata-only deployment after preparing actual tested source. No server
connection or mutation yet in this task. No physical or real purchase tests.

## Active handoff - 2026-10-20 - Codex - main backend DEPLOYED; 2.2.4+150 test files VERIFIED; public app HOLD

Production feature source e9c745603cf5ee3f26a4833c1b1e924a87154e1d is LIVE on main
91.107.255.204 /www/wwwroot/ddr.drmobilejayzan.info, completed07:00:17UTC Oct20.
Five additive columns + exact unique indexes custom_key/gift_coupon_id verified.
No settings/reference approvals, tariff changes, real purchase or payment tests
performed. Custom pricing remains OFF/no row/no references until administrator
explicitly chooses finite-total/no-reset plan+period references and enables it.
Protected env/composer/custom app rules preserved. Both live customized Umi
bundles gained only665fencedbytes; installed4884205bytes SHA951c7179d096082c206309f1311607ca643364328f41c69d1cd2a5a144dd52b9,
UID/GID1000:1000/mode0644; stripping additions reproduces old26135ef... bytes.

Live HTTPS/SNI checks PASS: guestJSON200, adminHTML200 with bothaddon includes,
all4assets200 and exactfilehashes; anonymousadmin_custom/user_custom/orders403.
BothSupervisor groups RUNNING (initialnewPIDs3228409/3228410); unrelatedconfig-
agent untouched. Horizon stop signal corrected TERM, WebMan QUIT; bothwait600,
backedupconfig metadata/ownership preserved and reread/update loaded after new
source installation. Source/DB activation happened only after ALLoldapp processes
were zero. Newruntime acknowledged maintenance503, then UP200 before exposure.

New private full DBbackup at .../drmobile-deploy-20261019-tailored-v150/resume-20261020:
7972743gzipbytes/176693724rawbytes, SHA63e0ac5a6faebc826ea45d333e8d3990d76a104567ffd763b63bf2485a7abe0c;
consistentInnoDB snapshot and gzipintegrityPASS; config/customization backups
private0700/0600. Rollout journal and logs there; local ignored audit/production-
resume-20261020b.log/exit0 +HTTP log. Prior attempt20261020 timedout duringruntime
stop BEFOREDDL/source, automatically restored originalUP200; failedlog preserved.
Successful nextattempt drainedfinancialwork at06:59:54, stoppedallapp processes
07:00:00, schema07:00:01/source07:00:05/UP07:00:17. No bounded HTTPforce-cleanup
branch was used in successful journal. Older attempts may have hit Supervisor's
old10s forced-stop; do not claim no forced termination across entire history.
No database restore or old-code rollback after traffic exposure is permitted.

App built source c2dc4f2b5bacb2d382d2699e08a3c0b65adddd9f, version2.2.4+150.
ExactCI39290407606 attempt1 ALLtest/Android/WindowsSUCCESS; releaseSKIPPED.
ACTUALall5files passed232/232strictchecks,0fail on2026-10-20T06:48:30UTC,
audit/test150-verification.json/.log/.exit0 +audit/test150/. Android original
signer/appID/150/nativepins, Windows150/runtime/imports/375installerpaths and
exactunchangednativecacheprovenance PASS. Strictnormalverify_release.py SHA
542f06952d18beada18913260f931ab93b67c757a66dc572f45b90e7b62889ae unchanged.
Historical147/148/149files preserved. docs/testing/2.2.4-150-artifacts.md newly
written;150ownerchecklist alreadycommitted withbuilt source. This final follow-up
commits DOCUMENTATION ONLY, with no implementation code change or rebuild.

Validation: app local1294PASS/3skip/0fail; exactLinux1287PASS/10platformskip;
analyzer0errors/30identical149warnings/1122infos. BackendexactCI39289450940:
124tests/1017assertionsbothPHP8.1+8.2 PASS,21ChromePASS+2customlivebundlecopy
checksPASS; full173/1304/5baselineGoogleAutherrors NOTwholepass. Independent
financial/API review clear; earlier failedscrolltestfixed+fullrerunretained.
No physicalAndroid/VPN, installerexecution, livegateway or trueMySQLrace test.

Admin: real Tailored sidebar directlybelowsubscriptions in BOTHUmi bundles.
App: first goldcustomcard insideBaseplans(no separatecustomtab), GB/day sliders,
groupedToman fromLaravelmodel/rate; explicit personal/gift. Normal+custom paid
codes retained/copyable inOrders; recipientProfile>Giftcard. Quotedintentbound,
noVIP/couponforgift, immutablecommercialdelivery. Commas remain THOUSANDS.
Main deployment authorized; public APP release/tag/assets/updater stillHOLD.
Do not publish or autoapprove references. Root inheritedef32475/openwrt preserved.

Final documentation scope in this commit: AI_HANDOFF.md plus app150 artifact
inventory/historical149 report pointer, or backend deployment guide respectively.
Implementation comes from the prior coordinated work and recorded commit authors;
this resumed turn verified actual artifacts, completed production rollout and
wrote final records. Public HOLD read-only snapshot confirms BOTH PRsOPEN/DRAFT,
oldv2.2.3tagfc861 unchanged/no2.2.4tag, botholdreleasesDRAFT/unpublished, public
updatermain845895c/version2.2.1 unchanged. Final snapshot is ignored
../audit/final150-publication-hold.json. No app release/merge/publicupload.

## Previous handoff - 2026-10-20 - Codex - resuming after usage interruption; owner restored Laravel, production rechecked

Owner requests completion and says Laravel was manually re-enabled after the
five-hour usage window expired. Do not blindly resume yesterday's scripts.
Fresh read-only server check: main HEADfb1c64dfff6bdf73fc85433e814ac82b7c607e11,
ONLY two customized Umi files dirty, original26135ef... hashes unchanged.
V2b/Horizon PID2883370 and WebMan PID2898796 RUNNING; Supervisor configs still
original QUIT/defaultwait. All five gift/custom columns and two indexes STILL
ABSENT. No custom pricing row or approved reference. Feature source NOT deployed.

Prior failed attempt2 (reconstructed from saved logs/current chat, not a pass):
maintenance entered, queues/background drained, old Horizon gracefully signaled.
WebMan graceful wait timed out180s on retained PHP connection counter despite
zero socket descriptors. Targeted idle-child TERM + supervisorstop wait7s also
timed out; Supervisor's old10s stop may have forcibly ended its master. Oldpinned
PIDs disappeared, four maintenance-era orphan workers were observed. No schema/
source/supervisorconfig mutation completed. Owner later restored Laravel. Failed
logs preserved; do not claim clean graceful shutdown or noforcedkill happened.
New rollout must recapture current PIDs and backups, drain actual financial work,
stop old runtime conclusively and retain new fulfilment handlers after exposure.

Implementation already COMMITTED/PUSHED: backend e9c745603cf5ee3f26a4833c1b1e924a87154e1d,
app c2dc4f2b5bacb2d382d2699e08a3c0b65adddd9f (2.2.4+150). Backend exactCI39289450940:
124tests/1017assertions bothPHP PASS,21ChromePASS; full173/1304/5baselineGoogleAuth
errors NOTwholepass. Local app1294PASS/3skip, analyzer0errors/30samewarnings/1122infos.
New signed appCI39290407606 now confirmed COMPLETE/SUCCESS; test/windows/android
SUCCESS, releaseSKIPPED. ActualLinux1287PASS/10skip, same30warnings. Strict actual
five-file150 verification started now; NOT yet a binary pass. Older147/148/149
files preserved. No new source change/rebuild needed unless a concrete issue.

Main backend deployment remains explicitly authorized. Public APP release/tag/
assets/updater HOLD persists. New admin tab below subscriptions; first goldcustom
card inside Base plans, two GB/day sliders/Toman, self or gift checkout. Defaults
OFF/no references until admin explicitly approves finite-total/no-reset plan+period.
Only A inferred; fixed formula and comma thousands contract unchanged. Current
local changes are durable handoff notes; inherited rootopenwrt/branch preserved.
No new sub-agent work: root owns remaining validation/deployment/documentation.

## Previous handoff - 2026-10-19 - Codex - owner steers new admin sidebar + first gold app card + custom gifts; build150 IN PROGRESS

Latest owner scope supersedes the old inside-/plan/separate-app-tab design.
Implement a real Tailored sidebar tab immediately BELOW subscription management
in BOTH umi.js/umi-fa.js; preserve existing tariff screen and production bundle
customizations with a maintained additive/fenced/idempotent patcher. App custom
card belongs FIRST in base plans, gold, two GB/day sliders, Toman from server
USD model/rate/rounding; explicit self/others intent for normal AND custom gifts.
Normal gift toggle was hidden while production capability false (old schema);
new UI must clearly explain unsupported gift checkout. No silent self purchase.

Implementation is UNCOMMITTED/UNVALIDATED. App new target2.2.4+150; root bumped
pubspec.yaml and owns shared handoffs/CI integration/commits/deployment. Backend
agent owns custom gift API/safe paid snapshot redemption + financial tests;
app agent owns purchase/model/payment/locales/tests and EXCLUSIVE Flutter slot;
admin UI agent owns both bundles/patcher/addon/browser tests. No public release.
Old149 test artifacts/105 PHP +15 browser results are HISTORICAL, not tests of
new source. Need new financial/browser/full app tests, independent review, exact
source CI + signed150 artifacts. New custom gift code must fit VARCHAR64.

Main backend deployment remains explicitly AUTHORIZED after this new source is
ready. NO functional deployment/DDL/restart/payment/settings/approval yet.
Actual prod fb1c64d at91.107.255.204; only dirty two umi bundles4883540bytes each,
SHA26135ef246076243d19e8fd03a44f5daa393fbe935612269d23ecef6a91019be.
Private olda377 source/config backup preparation was interrupted locally;
completion UNKNOWN pending metadata verification, no full DB backup yet.
Olda377 bundle is obsolete as deployment target; do not ship it as final scope.
Default custom pricing OFF/no references remains; admin approves finite-total,
no-reset, PLAN+PERIOD explicitly. Fixed shape/inferonlyA; commas thousands.
Public APP release/tag/assets/updater HOLD persists, no permission re-ask.

Validation update within active150 scope: backend124tests/1017assertions PASS,
21actualChrome checks PASS +2realcustomproductionbundle firstclick checks PASS;
app focused55PASS/0fail + scopedanalyzer0errors/0warnings/32infos. Independent
financial review and fullapp validation still PENDING. UI/sharedsource UNCOMMITTED.
Maintainedpatcher addsONLY665bytes peractualproductionbundle; strippingourfences
returns originalbytes, originalhash26135ef..., patchedhash951c7179d096082c206309f1311607ca643364328f41c69d1cd2a5a144dd52b9.
Olda377 private source/config backups now verifiedgzipOK, nofunctionalchanges.
NEWprivatebackup /root/drmobile-deploy-20261019-tailored-v150 source/configOK;
fullconsistentInnoDB DBbackup exit0,5352149gzipbytes/37577876rawbytes,
SHA5da6c05c9070d525897f384ba880261367dcfab58472a376fb12644024fc9c11.
DBMySQL5.7.44,53tablesALLInnoDB; backupgzipverification stillpending.
Rootignoredtestartifactinspector preservedverified149copy, addedONLYexplicit
2.2.4+150pair withstrictnormalreleaseinspectorunchanged;37offlinegate testsPASS.
No150buildoractualartifactchecks yet. NoDDL/code/runtime/settingschangeonprod.

Final new backend source READY FOR SCOPED COMMIT: independentfinancial/API/UI
review CLEAR (../audit/custom-gift-independent-review.md; finishingreport),
124tests/1017assertionsPASS,7PHPsyntaxPASS,21actualChromePASS+2customlivebundle
copychecksPASS, JSsyntax/patcheridempotence/diffchecksPASS. Normalcodesunchanged;
custom256bit64hex<=VARCHAR64, intentboundtoken/dedicatedsaveCustomGift, rawgift
price/noVIP/coupons, immutablewhitelistedrecipientcommercialsnapshot, paidreceipt,
retry/withdrawal/cancel/reserve/no-cash regressions. No newDDLbeyondold5columns.
Exactnewfiles: app/Http/Controllers/V1/User/CustomPlanController.php;
app/Http/Routes/V1/UserRoute.php; app/Services/{CustomPlanService,GiftOrderService,
CouponService}.php; tests/Feature/{CustomPlanGiftOrderTest,CustomPlanOrderTest}.php;
public/assets/admin/{umi.js,umi-fa.js,tailored-pricing.js,tailored-pricing.css};
resources/views/admin.blade.php; scripts/patch_admin_tailored_pricing.py;
tests/AdminTailoredPricingBrowserTest.cjs; .github/workflows/ci.yml;
docs/{tailored-pricing-admin.fa.md,tailored-gifts.md};AI_HANDOFF.md.
RootownsnewcustomgiftmandatoryCIfilter/UIagentownssyntaxguardstep. NewexactCI
stillPENDING; nofullsuitePASSclaim. Mainserverfunctionalstate stilloriginal.

Source progress: backend18filesCOMMITTED/PUSHED e9c745603cf5ee3f26a4833c1b1e924a87154e1d;
exactautomaticCI39289450940 running. IndependentreviewCLEAR; all124/1017financial,
21Chrome+2custombundlechecks verified. Private fullDBgzipintegrity PASS,0600file/
0700directory. Source-v150.bundle SHA f787e0c86ad5c3ae4d9398c2d741da1a99f6ca7d36e0df60ecb4ff35f0db94e6
116460bytes uploadedprivate; NOTapplied/imported/sourceactivated. Supervisor
rolloutreview foundoldHorizonQUITunsupported anddefault10secforcekillrisks;
preparinggracefuldrain/configTERM+600guard, no liveconfigedit/restartyet.
AppfirstFULLsuite1293PASS/3skip/1FAIL: newpurchaseListView physicsviolatedexisting
sharedscrollcontract. RootremovedONLYlocalAlwaysScrollableScrollPhysics override;
initialfailedlogpreserved. FinalfullsuiteRE-RUNNING onfixedsource+strengthened
lostgift-save/withdrawaltest. No appcommit/build150/productionactivationyet.

AppsourceCOMMITTED/PUSHED c2dc4f2b5bacb2d382d2699e08a3c0b65adddd9f;
exactworkflow39290407606 attempt1 started17:00:30Z withandroid/windowsTRUE,
releaseFALSE/emptytag. Full1294/3skip/0fail+analyzer0/30/1122 recorded; exactsame
30warningmultiset149. Noactual150artifactverification yet; PR15 staysDRAFT.

Productionattempt1 FAILED atmaintenanceHTTPguard: direct6600guest503 butTLSorigin
500whilemaintenance; expected503guardaborted BEFOREpause/signals/configedit/DDL/
sourcechange. RootguardedoriginalHEADfb/noDDL/noPreparedmarker, artisanUP,
TLS200restoredimmediately. Originalservicesstillrunning. Privatefirstsupervisor
configsbackedup, code/config/DBarchivesunchanged, stagedsource/overlayproofPASS.
Failedlogproduction-v150-rollout.log/exit1 preserved; scriptsbeingadjustedto
verifyactualinternalmaintenance503 andallowexactexistingprivatebackupsonretry.
NOfunctionalfeaturedeploymentyet. Do notclaimfailedguard asproductbugwithout
originrouting/errorhandlingevidence. No automaticdatabase/source rollbackused.

## Previous handoff - 2026-10-19 - Codex - admin editor committed; exact CI105/796 +15browser verified, NOT deployed

FINAL status-onlyfollowup, UNCOMMITTEDdocumentation: latestdocHEADa37721bb7a69e7849e4e133c75f1f39339cd8820
is COMMITTED/PUSHED, ONLYAI_HANDOFF.md afterimplementation687f771. Its actual
automaticCI39251532038 all4SUCCESS: bothPHP105/796strictPASS/full154/1083/
5samebaselineGoogleAutherrors; actualChrome15PASS0skip0fail+Node22+syntax.
All4checkoutmergeb783ccba treesd4137ccd equal latestHEAD; APIparentsverified.
Evidence ../audit/panel-admin-tailored-ci/latest-doc-ci/{SUMMARY.md,audit-result.json,4rawlogs}.
No code changes aftervalidated687, no additional manual workflowtrigger or
tests by root. No required source/UI/artifact work remains for this scope;
ownerphysicaltests and laterauthorizedbackend/schema rollout remain unrun.

Implementation COMMITTED/PUSHED687f771aa6d26cef3fa544b8a74cb341cb63f483
atopb73a69a in existing OPEN/DRAFT PR15. ExactCI39250910665 all4jobsSUCCESS;
bothPHP8.1/8.2 strict105tests/796assertionsPASS, PHP8.2 actualChromium15PASS/
0FAIL/0SKIP+Node22+JSsyntaxPASS. InformationalFULL154tests/1083assertions/
5samebaselineGoogleAuth missingSQLitev2_plan errors on each; NOTwholePASS.
All4syntheticcheckoutmergeb65a2f88 trees5fc758c5 equal actual687f771 source
tree, APIparentsverified. ../audit/panel-admin-tailored-ci/{SUMMARY.md,
audit-result.json,4rawlogs} retains actual evidence. This follow-up changes
ONLYAI_HANDOFF.md aftervalidatedimplementation, preparingdocumentationcommit.
Implemented INSIDE existing Laravel "مدیریت اشتراک‌ها" route, without editing
prebuilt umi bundles. Both FA/EN supported, existing tariff editor/table kept.
Owner's explicit PLAN+PERIOD approvals, finite-total/no-reset ONLY, defaultoff/
none, no automaticfallback, compatibleaccess, fixedshape/medianA all enforced.
Editable classifications/references/enabled flag; readonly model/constants;
draftpreview/curves/residuals and explicit optimistic save. Actual price edits
remain in existing tariff editor. BotSetting boundedJSON configuration, no new
settings migration; original custom/gift schema still needs future rollout.

Exact files: .github/workflows/ci.yml; AI_HANDOFF.md;
app/Http/Routes/V1/AdminRoute.php; app/Services/CustomPlanService.php;
app/Services/CustomPlanSettingsService.php;
app/Http/Controllers/V1/Admin/CustomPlanController.php;
resources/views/admin.blade.php;
public/assets/admin/tailored-pricing.{js,css};
tests/Feature/{CustomPlanOrderTest.php,CustomPlanAdminTest.php};
tests/AdminTailoredPricingBrowserTest.cjs; tests/fixtures/tailored-admin.cjs;
docs/tailored-pricing-admin.fa.md. CI adds strictCustomPlanAdminTest and Node22
actualChrome15browser checks onPHP8.2; actualremote results now verified above.

Final local strict mandatory105tests/796assertionsPASS (old87/634 +new18/162),
actual ../audit/custom-admin-financial-second.log. SixPHPsyntaxPASS, all3JS/CJS
syntaxPASS, gitdiffcheckPASS. ActualChrome15/15PASS,0skip/0fail
../audit/admin-tailored-browser-final.log. Parent viewed actualclean FA/EN
screenshots ../audit/admin-tailored-preview/, demo data ONLY, readable axes.
Independent boundedbackend+UI review CLEAR, ../audit/tailored-admin-review.md.
No Flutter source change; existing app44568deb2.2.4+149 API contract retained.

Four real review findings fixed with controls: unrelated classified deleted
plan blocking pricing; capability response misreported; zero-rounded Toman
curve NaN; deleted selected ref unremovable in tab. InitialPHPtests had16wrong
route-prefix404s, fixed fixture; first105suite45errors/1failure because helper
dropsnonIDSQLprimarykeys. Bothcustomfixtures now restore actualsettingskey
uniqueindex; productiongate NOTweakened. Failedlogs preserved. No new fullPHP
localpass claimed; actualnewCIfull154/1083/5baselineerrors remains NOTPASS.
Actualschema52fresh/53update, installer50/admin200/API403/aaPanelPASS; SQL/
lock/installer blobs unchanged. Bothdependency scans retain25sameadvisories,
no remediation. UpgradedUNIQUEindex not separatelyasserted; true simultaneous
MySQLsave race, livegateway/device and browserwithliveLaravel UNRUN. Browser
suite uses fixtureAPI; actual LaravelAPI/middleware/DB tested separately.

Existing app149 all5files strictlyverified232/232PASS from39242819273 exact
44568deb, releaseSKIPPED. This does not deploy backend. Sourcecommit/push and
exactCI audit completed; documentationfollowup only remains. No merge,
productionDDL/payments/deployment, releaseassets/publish/updater; HOLD persists.

## Previous handoff - 2026-10-19 - Codex - implementing admin editor/curves inside subscription tab, UNVALIDATED, HOLD

Owner explicitly requires admin changes EDITABLE INSIDE "مدیریت اشتراک‌ها"
with patterns/model/charts/curves visible. Implementing agreed PLAN+PERIOD
approval, ONLY structured finite-total/no-reset2 sources. Fixedformula/medianA
and moneythousands conventions retained. No source commit/push/deployment yet;
new financial/UI tests and independent review REQUIRED, priorCI notnewscope.
Root changed exactfile .github/workflows/ci.yml to include CustomPlanAdminTest
in strict mandatory financial suite; shared handoff updates root-owned.
Backend agent owns settingsservice/controller/adminroutes/CustomPlanService
and meaningfultests (CustomPlanAdminTest +updatedexplicitapprovalfixtures in
CustomPlanOrderTest). UIagent owns new maintained public/assets/admin/tailored-
pricing.js/CSS, resources/views/admin.blade.php integration and browsertests.
Exact newfiles/results will be recorded when produced. Reviewer read-only.

Agreed protectedadmin API /plan/custom/{fetch,preview,save}; boundedBotSetting
JSON enabled/classifications[{plan_id,quota_type}]/references[{plan_id,period}],
settings_revision+source_revision for CAS/source drift, defaultnone/disabled.
Unknown/unlimited/FUP/resetting/invalid/incompatibleaccess refs rejected with
reasons. Draft preview readonly/nohiddenplans/noorders/nodebits. Ordinary
prices continue in existing tariffeditor; no duplicateprice store. Newsave
quote withdrawal validation must follow existingtrade idempotence lookup.
UI addon lives inside existingplanroute, bothFA/EN, keeps originaltable,
showsdraftvsaved state/derivedA/fixedformula/sourcepoints/model residuals and
interactivevolume/timeUSD/Toman curves, explicitpreview/save. No bundlehack or
externalCDNdependency required. Root publication HOLD: no merge, production
DDL/payment/deploy, publicassets/release/updater. App2.2.4+149contract unchanged.

## Previous handoff - 2026-10-19 - Codex - new admin-approved reference requirement; scenario discussion first, NOT IMPLEMENTED

Owner asks to review price derivation BEFORE continuing and requires admin to
approve reference tariffs, excluding unlimited. READ-ONLY audit performed;
NO source changes or new tests for this requirement. Source0a40a87/docsHEAD
b73a69a still automatically selects candidates and is NOT final for new scope.
This handoff entry alone is UNCOMMITTED. Previous CI validates previouscode.

Actual CustomPlanService.php:77-104 filters show1/custom_keyNULL/capacityNULL/
GB1..1024, first sort/id effective access/reset cohort; every valid positive
30/90/180/365day price contributes. :106-109 takes median USD/fixedshape to
infer A. There is no admin allowlist, structured unlimited/FUP type, or live
tariff inspection. Numeric filtering alone cannot exclude an unlimited/FUP
product carrying finite in-range quota. Database install.sql:350-386 lacks
semantic quota type. Telegram Search.php:129-138 labels0unlimited while
UserService.php:148,156 requires positive remaining quota; do not infer one
universal zero convention. Source reset0/1/3/4 can participate today whereas
custom delivery is total/no-reset2; matching cohort does not solve that semantic
comparison. Do not silently multiply monthly quota by nominal period.

Admin extension points: Admin/PlanController fetch/save/update and requests
Admin/{PlanSave,PlanUpdate}; AdminRoute admin/log middleware. Form source is
prebuilt public/assets/admin/{umi.js,umi-fa.js}, around82622planfields,
82889reset labels,83070list. No corresponding React build source found.
ConfigController/ConfigSave and BotSetting offer central settings storage but
currently no tailored settings. Need maintained UI covering both languagepaths.
Owner ANSWERED: PLAN+PERIOD individual approval; ONLY finite-total NO-RESET
sources. No resetting-source conversion. Do not ask again. OnlyAinferred;
fixedshape/thousands conventions retained. New control NOTIMPLEMENTED. Proposed default
none selected, compatible access, no automaticfallback; admin preview included
points/A/curve before enabling. No productionDDL/payment/deploy/merge/release.

## Previous handoff - 2026-10-19 - Codex - tailored/gift backend exact CI audited; not deployed, HOLD

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
