# Graph Report - Irboard  (2026-07-29)

## Corpus Check
- 396 files · ~1,775,903 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 4227 nodes · 14184 edges · 186 communities (136 shown, 50 thin omitted)
- Extraction: 77% EXTRACTED · 23% INFERRED · 0% AMBIGUOUS · INFERRED: 3287 edges (avg confidence: 0.6)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `a9e59197`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Illuminate\Http\Request
- admin/umi.js
- setState
- umi-fa.js
- setState
- assets/components.async.js
- assets/vendors.async.js
- Log
- t
- admin/vendors.async.js
- Illuminate\Database\Eloquent\Model
- Controller
- TelegramBotService
- assets/umi.js
- admin/components.async.js
- Plan
- Illuminate\Console\Command
- r
- t
- n
- i
- t
- m
- l
- Illuminate\Database\Migrations\Migration
- mu
- Helper
- i
- gs
- ot
- .request
- Ticket
- SendEmailJob.php
- Closure
- self
- TestCase
- j
- e
- r
- ServerGroup
- d
- d
- Illuminate\Contracts\Routing\Registrar
- OrderService
- ServerVmess
- Telegram
- b
- Xn
- x
- ExchangeService
- Manager
- Admin
- z
- Singbox
- require
- Illuminate\Foundation\Http\FormRequest
- StatisticalService
- UserService
- Edit
- AlipayF2F
- AuthService
- Notice
- ClashNyanpasu
- ClashVerge
- install.sh
- g
- Ve
- BotSetting
- Search
- ClashMeta
- SingboxOld
- h
- readme.md
- Coupon
- Payment
- ServerTrojan
- Stash
- CLI
- i18n-fa.js
- bn
- SystemController
- ZibalPayment
- Loon
- composer.json
- Clash
- QuantumultX
- Surge
- CouponService
- ResetTraffic
- Surfboard
- Illuminate\Support\ServiceProvider
- scripts
- V2boardUpdate
- Handler
- v2RayTun
- RouteServiceProvider
- autoload
- update.sh
- V2boardInstall
- Console/Kernel.php
- Giftcard
- Start
- HorizonServiceProvider
- keywords
- require-dev
- Yn
- ConfigSave
- OrderUpdate
- Menu
- Shadowrocket
- Shadowsocks
- init.sh
- Authenticate
- keywords
- KnowledgeCategorySave
- ThemeController
- Menu
- Shadowrocket
- Shadowsocks
- init.sh
- Authenticate
- Passwall
- SagerNet
- AuthServiceProvider
- EventServiceProvider
- config
- packagist
- DatabaseSeeder
- server_config_agent.sh
- Kernel
- CheckForMaintenanceMode
- EncryptCookies
- TrimStrings
- TrustProxies
- VerifyCsrfToken
- MysqlLogger
- AuthServiceProvider
- config

## God Nodes (most connected - your core abstractions)
1. `Controller` - 150 edges
2. `t()` - 134 edges
3. `t()` - 134 edges
4. `r()` - 110 edges
5. `r()` - 110 edges
6. `Helper` - 108 edges
7. `setState()` - 106 edges
8. `setState()` - 106 edges
9. `n()` - 103 edges
10. `i()` - 103 edges

## Surprising Connections (you probably didn't know these)
- `st()` --indirect_call--> `wt()`  [INFERRED]
  public/theme/default/assets/vendors.async.js → public/theme/default/assets/umi.js
- `C()` --indirect_call--> `D()`  [INFERRED]
  public/assets/admin/components.async.js → public/assets/admin/vendors.async.js
- `C()` --indirect_call--> `L()`  [INFERRED]
  public/assets/admin/components.async.js → public/assets/admin/vendors.async.js
- `C()` --indirect_call--> `P()`  [INFERRED]
  public/assets/admin/components.async.js → public/assets/admin/vendors.async.js
- `C()` --indirect_call--> `W()`  [INFERRED]
  public/assets/admin/components.async.js → public/theme/default/assets/vendors.async.js

## Import Cycles
- None detected.

## Communities (186 total, 50 thin omitted)

### Community 0 - "Illuminate\Http\Request"
Cohesion: 0.04
Nodes (111): adminNode(), Ai(), api(), ar(), asAuth(), asInject(), asLoad(), asRenderApps() (+103 more)

### Community 1 - "admin/umi.js"
Cohesion: 0.04
Nodes (17): addFilter(), e(), EncryptionSettings, er(), getEmailTemplate(), getThemeTemplate(), p(), setState() (+9 more)

### Community 2 - "setState"
Cohesion: 0.03
Nodes (31): AppStoreController, ConfigController, OrderController, ManageController, RouteController, VlessController, ServerConfigController, ServerTrafficController (+23 more)

### Community 3 - "umi-fa.js"
Cohesion: 0.03
Nodes (19): e(), EncryptionSettings, er(), g(), getEmailTemplate(), getThemeTemplate(), jr(), m() (+11 more)

### Community 4 - "setState"
Cohesion: 0.05
Nodes (113): a(), ac(), ae(), an(), at(), b(), bc(), be() (+105 more)

### Community 5 - "assets/components.async.js"
Cohesion: 0.04
Nodes (108): aa(), Al(), ao(), as(), Au(), ba(), Bl(), bo() (+100 more)

### Community 6 - "assets/vendors.async.js"
Cohesion: 0.02
Nodes (30): GiftcardController, ConfigSave, GiftcardGenerate, KnowledgeCategorySave, KnowledgeCategorySort, MailSend, OrderAssign, OrderFetch (+22 more)

### Community 7 - "Log"
Cohesion: 0.04
Nodes (13): Controller, ClientController, ExchangeRateController, TelegramAuthController, TelegramBotController, PlanController, CommController, NoticeController (+5 more)

### Community 8 - "t"
Cohesion: 0.04
Nodes (96): aa(), ai(), Al(), ao(), As(), Bl(), bo(), bs() (+88 more)

### Community 9 - "admin/vendors.async.js"
Cohesion: 0.04
Nodes (108): adminNode(), Ai(), api(), ar(), asAuth(), asInject(), asLoad(), asRenderApps() (+100 more)

### Community 10 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.06
Nodes (7): SmartBotController, BotChannel, BotPanel, BotText, User, TelegramBotService, TelegramOrderService

### Community 11 - "Controller"
Cohesion: 0.11
Nodes (49): a(), ae(), An(), Be(), c(), cancel(), ce(), De() (+41 more)

### Community 12 - "TelegramBotService"
Cohesion: 0.09
Nodes (72): a(), ae(), An(), at(), c(), cancel(), changePassword(), check() (+64 more)

### Community 13 - "assets/umi.js"
Cohesion: 0.07
Nodes (12): ResetLog, V2boardStatistics, StatController, StatController, CardPaymentConfig, ServerLog, ServerRoute, ServerStat (+4 more)

### Community 14 - "admin/components.async.js"
Cohesion: 0.08
Nodes (81): A(), ae(), at(), b(), Be(), Bt(), C(), cc() (+73 more)

### Community 15 - "Plan"
Cohesion: 0.07
Nodes (74): a(), addFilter(), ae(), An(), Be(), Bt(), c(), cancel() (+66 more)

### Community 16 - "Illuminate\Console\Command"
Cohesion: 0.07
Nodes (28): _, allDel(), assign(), ban(), changeTable(), checkLogin(), copy(), delUser() (+20 more)

### Community 17 - "r"
Cohesion: 0.18
Nodes (70): Qn(), Qn(), a(), ae(), at(), b(), c(), cn() (+62 more)

### Community 18 - "t"
Cohesion: 0.07
Nodes (5): d(), empty(), j(), q(), y()

### Community 19 - "n"
Cohesion: 0.07
Nodes (4): j(), l(), setState(), w()

### Community 20 - "i"
Cohesion: 0.04
Nodes (22): AuditPayments, CheckOrder, CheckServer, CleanupPaymentTracks, ClearUser, ExpireOldTracksCommand, ExpirePendingCardPayments, FixLogsPermissionsCommand (+14 more)

### Community 21 - "t"
Cohesion: 0.05
Nodes (5): General, SSRPlus, V2rayN, V2rayNG, Helper

### Community 22 - "m"
Cohesion: 0.08
Nodes (12): CheckRenewal, User, UpdatePlanPrices, PlanPriceApiController, PlanController, Plan, User, PlanObserver (+4 more)

### Community 23 - "l"
Cohesion: 0.04
Nodes (49): `failed_jobs`, `migrations`, `payment_tracks`, `settings`, `sms_logs`, `v2_bot_channels`, `v2_bot_panels`, `v2_bot_settings` (+41 more)

### Community 24 - "Illuminate\Database\Migrations\Migration"
Cohesion: 0.04
Nodes (49): `failed_jobs`, `migrations`, `payment_tracks`, `settings`, `sms_logs`, `v2_bot_channels`, `v2_bot_panels`, `v2_bot_settings` (+41 more)

### Community 25 - "mu"
Cohesion: 0.08
Nodes (50): Be(), bn(), bt(), ce(), Cn(), ct(), de(), dt() (+42 more)

### Community 26 - "Helper"
Cohesion: 0.05
Nodes (12): CreateFailedJobsTable, AddUsdPricesToV2PlanTable, CreateSettingsTable, CreatePaymentTracksTable, IncreaseUriLengthInV2LogTable, CreatePlanPricesTable, AddExchangeRateToOrdersTable, CreateBotPanelsTable (+4 more)

### Community 27 - "i"
Cohesion: 0.06
Nodes (37): _, allDel(), assign(), ban(), changeTable(), checkLogin(), Cn(), copy() (+29 more)

### Community 28 - "gs"
Cohesion: 0.34
Nodes (44): A(), ae(), b(), Be(), C(), D(), De(), E() (+36 more)

### Community 29 - "ot"
Cohesion: 0.11
Nodes (19): boot(), doToggle(), esc(), fmt(), inject(), j(), keyOf(), load() (+11 more)

### Community 30 - ".request"
Cohesion: 0.15
Nodes (28): Di(), ai(), bi(), Bn(), ci(), di(), du(), Ei() (+20 more)

### Community 31 - "Ticket"
Cohesion: 0.22
Nodes (4): ActivateReservedPlans, CheckCommission, CheckPendingPayments, Order

### Community 32 - "SendEmailJob.php"
Cohesion: 0.14
Nodes (36): an(), at(), bt(), ct(), dt(), en(), et(), ft() (+28 more)

### Community 33 - "Closure"
Cohesion: 0.13
Nodes (7): CheckTicket, TicketController, TicketController, TicketController, Ticket, TicketMessage, TicketService

### Community 34 - "self"
Cohesion: 0.14
Nodes (36): at(), Bt(), ci(), di(), dt(), en(), ft(), gt() (+28 more)

### Community 35 - "TestCase"
Cohesion: 0.12
Nodes (7): b(), close(), decorate(), emailForKey(), k(), tick(), w()

### Community 36 - "j"
Cohesion: 0.09
Nodes (4): CardPaymentController, CardPaymentController, CardPayment, CardPaymentService

### Community 37 - "e"
Cohesion: 0.07
Nodes (12): AdapterManServerVars, Admin, ApiKeyAuth, CompressResponse, CORS, ForceJson, Language, RedirectIfAuthenticated (+4 more)

### Community 38 - "r"
Cohesion: 0.16
Nodes (13): OrderHandleJob, SendEmailJob, SendTelegramJob, StatServerJob, StatUserJob, TrafficFetchJob, MailLog, MailService (+5 more)

### Community 39 - "ServerGroup"
Cohesion: 0.16
Nodes (18): Ar(), br(), Dr(), H(), hr(), jr(), kr(), lr() (+10 more)

### Community 40 - "d"
Cohesion: 0.15
Nodes (34): wi(), an(), bt(), ct(), et(), ft(), gn(), ht() (+26 more)

### Community 41 - "d"
Cohesion: 0.12
Nodes (3): d(), empty(), y()

### Community 42 - "Illuminate\Contracts\Routing\Registrar"
Cohesion: 0.05
Nodes (21): Kernel, AnyTLSController, HysteriaController, MdnsController, TrojanController, TuicController, V2nodeController, SystemController (+13 more)

### Community 43 - "OrderService"
Cohesion: 0.16
Nodes (3): b(), q(), x()

### Community 44 - "ServerVmess"
Cohesion: 0.10
Nodes (10): AdminRoute, ClientRoute, GuestRoute, PassportRoute, ServerRoute, StaffRoute, TestRoute, UserRoute (+2 more)

### Community 45 - "Telegram"
Cohesion: 0.12
Nodes (4): PaymentTrack, Clash, Collection, self

### Community 46 - "b"
Cohesion: 0.13
Nodes (3): ReservedPlan, OrderService, User

### Community 47 - "Xn"
Cohesion: 0.14
Nodes (5): VmessController, DeepbworkController, ServerVmessSave, ServerVmessUpdate, ServerVmess

### Community 48 - "x"
Cohesion: 0.10
Nodes (7): Bind, GetLatestUrl, Rebind, ReplyTicket, Traffic, UnBind, Telegram

### Community 49 - "ExchangeService"
Cohesion: 0.11
Nodes (10): CreatesApplication, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, PHPUnit\Runner\AfterLastTestHook, PHPUnit\Runner\BeforeFirstTestHook, Bootstrap, ExampleTest, GoogleAuthTest (+2 more)

### Community 50 - "Manager"
Cohesion: 0.17
Nodes (4): TelegramController, GoogleAuthController, User, Log

### Community 51 - "Admin"
Cohesion: 0.07
Nodes (38): ar(), br(), ca(), ce(), cr(), Dr(), er(), fr() (+30 more)

### Community 52 - "z"
Cohesion: 0.09
Nodes (9): GroupPricingController, GroupController, User, UserGroupController, AddonController, ServerGroup, UserGroup, AddonBillingService (+1 more)

### Community 53 - "Singbox"
Cohesion: 0.11
Nodes (3): SyncPlanPrices, ExchangeRateAdminController, ExchangeService

### Community 54 - "require"
Cohesion: 0.05
Nodes (11): b(), close(), decorate(), emailForKey(), g(), jr(), k(), m() (+3 more)

### Community 55 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.21
Nodes (3): CircuitBreaker, Logger, Manager

### Community 57 - "UserService"
Cohesion: 0.12
Nodes (5): MysqlLoggerHandler, scopeSetFilterAllowKeys(), PaymentService, TelegramService, Monolog\Handler\AbstractProcessingHandler

### Community 58 - "Edit"
Cohesion: 0.15
Nodes (5): ShadowsocksController, ShadowsocksTidalabController, ServerShadowsocksSave, ServerShadowsocksUpdate, ServerShadowsocks

### Community 60 - "AuthService"
Cohesion: 0.07
Nodes (4): User, Setting, AuthService, User

### Community 61 - "Notice"
Cohesion: 0.16
Nodes (4): CouponController, CouponController, CouponGenerate, Coupon

### Community 62 - "ClashNyanpasu"
Cohesion: 0.14
Nodes (5): KnowledgeController, KnowledgeController, KnowledgeSave, KnowledgeSort, Knowledge

### Community 64 - "install.sh"
Cohesion: 0.11
Nodes (18): require, fideloper/proxy, firebase/php-jwt, fruitcake/laravel-cors, google/recaptcha, guzzlehttp/guzzle, joanhey/adapterman, laravel/framework (+10 more)

### Community 65 - "g"
Cohesion: 0.24
Nodes (17): Be(), ce(), De(), Fe(), ge(), je(), Le(), Lu() (+9 more)

### Community 68 - "Search"
Cohesion: 0.21
Nodes (14): ba(), da(), ei(), fa(), ia(), ka(), ma(), na() (+6 more)

### Community 71 - "h"
Cohesion: 0.16
Nodes (3): PaymentController, OrderController, Payment

### Community 72 - "readme.md"
Cohesion: 0.20
Nodes (4): NoticeController, NoticeController, NoticeSave, Notice

### Community 75 - "ServerTrojan"
Cohesion: 0.35
Nodes (12): backup(), die(), is_supervised(), ok(), probe(), remove_program(), say(), install.sh script (+4 more)

### Community 77 - "CLI"
Cohesion: 0.17
Nodes (4): SendScheduledBackup, BotSettingController, BotSetting, Backup

### Community 78 - "i18n-fa.js"
Cohesion: 0.24
Nodes (13): au(), bu(), iu(), lu(), nu(), ou(), Qa(), ru() (+5 more)

### Community 82 - "Loon"
Cohesion: 0.21
Nodes (3): h(), on(), rn()

### Community 83 - "composer.json"
Cohesion: 0.13
Nodes (14): 📊 آمار پروژه, 🔄 آپدیت پنل, 🛡️ امنیت, 🔧 بک‌اند پشتیبانی شده, 💖 حامیان, 🎯 عملکرد, 📖 مستندات, 🤝 مشارکت (+6 more)

### Community 84 - "Clash"
Cohesion: 0.33
Nodes (5): Path, js(), main(), patch(), A JS string literal body with every non-ASCII char as a \\uXXXX escape.

### Community 87 - "CouponService"
Cohesion: 0.33
Nodes (10): checkNavigation(), fmtDate(), makeBtn(), openPopup(), schedule(), startObserver(), translateAll(), translateNode() (+2 more)

### Community 88 - "ResetTraffic"
Cohesion: 0.20
Nodes (15): bn(), dn(), es(), fn(), gn(), Hn(), it(), mn() (+7 more)

### Community 89 - "Surfboard"
Cohesion: 0.08
Nodes (6): ne(), qc(), e(), logout(), m(), v()

### Community 94 - "v2RayTun"
Cohesion: 0.20
Nodes (9): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+1 more)

### Community 101 - "Start"
Cohesion: 0.32
Nodes (3): AppServiceProvider, BroadcastServiceProvider, Illuminate\Support\ServiceProvider

### Community 102 - "HorizonServiceProvider"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-create-project-cmd, post-root-package-install, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, @php artisan key:generate --ansi, @php artisan package:discover --ansi, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 104 - "require-dev"
Cohesion: 0.43
Nodes (3): Handler, Illuminate\Foundation\Exceptions\Handler, Throwable

### Community 107 - "OrderUpdate"
Cohesion: 0.29
Nodes (7): autoload, classmap, psr-4, App\\, Library\\, database/factories, database/seeds

### Community 108 - "Menu"
Cohesion: 0.67
Nodes (6): die(), restart_webman(), say(), self_update(), update.sh script, warn()

### Community 113 - "keywords"
Cohesion: 0.33
Nodes (6): keywords, laravel, shadowsocks, trojan, v2board, v2ray

### Community 114 - "KnowledgeCategorySave"
Cohesion: 0.33
Nodes (6): require-dev, facade/ignition, fakerphp/faker, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 120 - "init.sh"
Cohesion: 0.70
Nodes (4): die(), say(), init.sh script, warn()

### Community 129 - "config"
Cohesion: 0.50
Nodes (4): config, optimize-autoloader, preferred-install, sort-packages

### Community 130 - "packagist"
Cohesion: 0.50
Nodes (4): type, url, repositories, packagist

### Community 132 - "server_config_agent.sh"
Cohesion: 0.83
Nodes (3): open_port(), server_config_agent.sh script, update_status()

### Community 142 - "AuthServiceProvider"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **161 isolated node(s):** `restore.sh script`, `Dict`, `name`, `type`, `description` (+156 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **50 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `L()` connect `gs` to `SendEmailJob.php`, `admin/umi.js`, `self`, `TestCase`, `umi-fa.js`, `t`, `d`, `admin/components.async.js`, `Plan`, `Illuminate\Console\Command`, `t`, `Admin`, `require`, `i`, `ot`?**
  _High betweenness centrality (0.036) - this node is a cross-community bridge._
- **Why does `Helper` connect `t` to `setState`, `assets/vendors.async.js`, `Log`, `Illuminate\Database\Eloquent\Model`, `i`, `m`, `Illuminate\Contracts\Routing\Registrar`, `x`, `Manager`, `StatisticalService`, `AlipayF2F`, `AuthService`, `Notice`, `ClashNyanpasu`, `h`, `Coupon`, `Payment`, `bn`, `SystemController`, `ZibalPayment`, `QuantumultX`, `Illuminate\Support\ServiceProvider`, `scripts`, `Handler`, `autoload`, `update.sh`, `Giftcard`, `Yn`, `Shadowrocket`, `Shadowrocket`, `Passwall`, `SagerNet`?**
  _High betweenness centrality (0.021) - this node is a cross-community bridge._
- **Why does `W()` connect `r` to `admin/umi.js`, `TestCase`, `assets/components.async.js`, `ServerGroup`, `admin/components.async.js`, `Illuminate\Console\Command`, `t`, `Loon`, `Surfboard`, `gs`?**
  _High betweenness centrality (0.019) - this node is a cross-community bridge._
- **Are the 51 inferred relationships involving `t()` (e.g. with `a()` and `ae()`) actually correct?**
  _`t()` has 51 INFERRED edges - model-reasoned connections that need verification._
- **Are the 51 inferred relationships involving `t()` (e.g. with `a()` and `ae()`) actually correct?**
  _`t()` has 51 INFERRED edges - model-reasoned connections that need verification._
- **Are the 56 inferred relationships involving `r()` (e.g. with `a()` and `b()`) actually correct?**
  _`r()` has 56 INFERRED edges - model-reasoned connections that need verification._
- **What connects `restore.sh script`, `Dict`, `name` to the rest of the system?**
  _161 weakly-connected nodes found - possible documentation gaps or missing edges._