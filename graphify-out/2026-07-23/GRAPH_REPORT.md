# Graph Report - Irboard  (2026-07-22)

## Corpus Check
- 394 files · ~1,770,993 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 4106 nodes · 14045 edges · 188 communities (131 shown, 57 thin omitted)
- Extraction: 77% EXTRACTED · 23% INFERRED · 0% AMBIGUOUS · INFERRED: 3283 edges (avg confidence: 0.6)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `d62929b5`
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
- KnowledgeCategorySave
- V2rayN
- V2rayNG
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
- extra
- Wn
- restore.sh
- GoogleAuthService
- CardPaymentService
- Card2Card
- MysqlLoggerHandler
- Passwall
- SagerNet
- SSRPlus
- General

## God Nodes (most connected - your core abstractions)
1. `Controller` - 150 edges
2. `t()` - 134 edges
3. `t()` - 134 edges
4. `r()` - 110 edges
5. `r()` - 110 edges
6. `Helper` - 106 edges
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

## Communities (188 total, 57 thin omitted)

### Community 0 - "Illuminate\Http\Request"
Cohesion: 0.03
Nodes (22): AppStoreController, ConfigController, PaymentController, ManageController, RouteController, ServerConfigController, ServerTrafficController, StaffController (+14 more)

### Community 1 - "admin/umi.js"
Cohesion: 0.04
Nodes (131): Ai(), api(), ar(), asAuth(), asInject(), asLoad(), asRenderApps(), asRenderBanners() (+123 more)

### Community 2 - "setState"
Cohesion: 0.03
Nodes (19): e(), EncryptionSettings, er(), g(), getEmailTemplate(), getThemeTemplate(), jr(), m() (+11 more)

### Community 3 - "umi-fa.js"
Cohesion: 0.04
Nodes (111): adminNode(), Ai(), api(), ar(), asAuth(), asInject(), asLoad(), asRenderApps() (+103 more)

### Community 4 - "setState"
Cohesion: 0.04
Nodes (12): e(), EncryptionSettings, er(), p(), setState(), v(), w(), wAnyTLS (+4 more)

### Community 5 - "assets/components.async.js"
Cohesion: 0.05
Nodes (113): a(), ac(), ae(), an(), at(), b(), bc(), be() (+105 more)

### Community 6 - "assets/vendors.async.js"
Cohesion: 0.04
Nodes (111): aa(), Al(), ao(), as(), Au(), ba(), Bl(), bo() (+103 more)

### Community 7 - "Log"
Cohesion: 0.13
Nodes (3): ReservedPlan, OrderService, User

### Community 8 - "t"
Cohesion: 0.08
Nodes (68): a(), addFilter(), ae(), An(), Be(), Bt(), c(), cancel() (+60 more)

### Community 9 - "admin/vendors.async.js"
Cohesion: 0.04
Nodes (87): aa(), ai(), Al(), an(), ao(), au(), ba(), bn() (+79 more)

### Community 10 - "Illuminate\Database\Eloquent\Model"
Cohesion: 0.04
Nodes (27): ResetLog, V2boardStatistics, AnyTLSController, HysteriaController, MdnsController, ShadowsocksController, TuicController, V2nodeController (+19 more)

### Community 11 - "Controller"
Cohesion: 0.05
Nodes (17): Kernel, Controller, ClientController, ExchangeRateController, TelegramAuthController, TelegramBotController, PlanController, CommController (+9 more)

### Community 12 - "TelegramBotService"
Cohesion: 0.06
Nodes (7): SmartBotController, BotChannel, BotPanel, BotText, User, TelegramBotService, TelegramOrderService

### Community 13 - "assets/umi.js"
Cohesion: 0.09
Nodes (72): a(), ae(), An(), at(), c(), cancel(), changePassword(), check() (+64 more)

### Community 14 - "admin/components.async.js"
Cohesion: 0.08
Nodes (81): A(), ae(), at(), b(), Be(), Bt(), C(), cc() (+73 more)

### Community 15 - "Plan"
Cohesion: 0.07
Nodes (12): CheckRenewal, User, PlanController, Plan, User, PlanObserver, PlanObserverProvider, MailService (+4 more)

### Community 16 - "Illuminate\Console\Command"
Cohesion: 0.04
Nodes (21): AuditPayments, CheckServer, CleanupPaymentTracks, ClearUser, ExpireOldTracksCommand, ExpirePendingCardPayments, FixLogsPermissionsCommand, PaymentHealthCheckCommand (+13 more)

### Community 17 - "r"
Cohesion: 0.06
Nodes (37): _, allDel(), assign(), ban(), changeTable(), checkLogin(), Cn(), copy() (+29 more)

### Community 18 - "t"
Cohesion: 0.11
Nodes (49): a(), ae(), An(), Be(), c(), cancel(), ce(), De() (+41 more)

### Community 19 - "n"
Cohesion: 0.18
Nodes (70): Qn(), Qn(), a(), ae(), at(), b(), c(), cn() (+62 more)

### Community 20 - "i"
Cohesion: 0.06
Nodes (34): _, allDel(), assign(), ban(), changeTable(), checkLogin(), copy(), delUser() (+26 more)

### Community 21 - "t"
Cohesion: 0.08
Nodes (50): Be(), bn(), bt(), ce(), Cn(), ct(), de(), dt() (+42 more)

### Community 22 - "m"
Cohesion: 0.05
Nodes (16): addFilter(), b(), close(), decorate(), emailForKey(), g(), getEmailTemplate(), getThemeTemplate() (+8 more)

### Community 23 - "l"
Cohesion: 0.07
Nodes (4): j(), l(), setState(), w()

### Community 24 - "Illuminate\Database\Migrations\Migration"
Cohesion: 0.05
Nodes (12): CreateFailedJobsTable, AddUsdPricesToV2PlanTable, CreateSettingsTable, CreatePaymentTracksTable, IncreaseUriLengthInV2LogTable, CreatePlanPricesTable, AddExchangeRateToOrdersTable, CreateBotPanelsTable (+4 more)

### Community 25 - "mu"
Cohesion: 0.10
Nodes (42): Di(), ai(), Ar(), bi(), Bn(), br(), ci(), di() (+34 more)

### Community 26 - "Helper"
Cohesion: 0.06
Nodes (3): User, Setting, Helper

### Community 27 - "i"
Cohesion: 0.32
Nodes (45): A(), ae(), b(), Be(), C(), D(), De(), E() (+37 more)

### Community 28 - "gs"
Cohesion: 0.10
Nodes (45): As(), Bl(), bo(), Cl(), du(), ea(), El(), Fl() (+37 more)

### Community 29 - "ot"
Cohesion: 0.13
Nodes (41): at(), bt(), ct(), Dr(), dt(), er(), et(), ft() (+33 more)

### Community 31 - "Ticket"
Cohesion: 0.09
Nodes (9): CheckTicket, TicketController, TicketController, TicketController, TicketSave, TicketWithdraw, Ticket, TicketMessage (+1 more)

### Community 32 - "SendEmailJob.php"
Cohesion: 0.16
Nodes (13): CheckOrder, OrderHandleJob, SendEmailJob, SendTelegramJob, StatServerJob, StatUserJob, TrafficFetchJob, MailLog (+5 more)

### Community 33 - "Closure"
Cohesion: 0.07
Nodes (12): AdapterManServerVars, Admin, ApiKeyAuth, CompressResponse, CORS, ForceJson, Language, RedirectIfAuthenticated (+4 more)

### Community 34 - "self"
Cohesion: 0.12
Nodes (4): PaymentTrack, Clash, Collection, self

### Community 35 - "TestCase"
Cohesion: 0.11
Nodes (10): CreatesApplication, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, PHPUnit\Runner\AfterLastTestHook, PHPUnit\Runner\BeforeFirstTestHook, Bootstrap, ExampleTest, GoogleAuthTest (+2 more)

### Community 36 - "j"
Cohesion: 0.10
Nodes (15): adminNode(), buildDetail(), dot(), drow(), el(), expTxt(), fmt(), fmtDate() (+7 more)

### Community 37 - "e"
Cohesion: 0.08
Nodes (6): ne(), qc(), e(), logout(), m(), v()

### Community 38 - "r"
Cohesion: 0.15
Nodes (34): wi(), an(), bt(), ct(), et(), ft(), gn(), ht() (+26 more)

### Community 39 - "ServerGroup"
Cohesion: 0.09
Nodes (9): GroupPricingController, GroupController, User, UserGroupController, AddonController, ServerGroup, UserGroup, AddonBillingService (+1 more)

### Community 40 - "d"
Cohesion: 0.11
Nodes (3): d(), empty(), y()

### Community 41 - "d"
Cohesion: 0.12
Nodes (3): d(), empty(), y()

### Community 42 - "Illuminate\Contracts\Routing\Registrar"
Cohesion: 0.10
Nodes (10): AdminRoute, ClientRoute, GuestRoute, PassportRoute, ServerRoute, StaffRoute, TestRoute, UserRoute (+2 more)

### Community 43 - "OrderService"
Cohesion: 0.04
Nodes (14): UserController, CommController, UserController, InviteController, KnowledgeController, PlanController, UserController, Client (+6 more)

### Community 44 - "ServerVmess"
Cohesion: 0.14
Nodes (5): VmessController, DeepbworkController, ServerVmessSave, ServerVmessUpdate, ServerVmess

### Community 45 - "Telegram"
Cohesion: 0.10
Nodes (7): Bind, GetLatestUrl, Rebind, ReplyTicket, Traffic, UnBind, Telegram

### Community 46 - "b"
Cohesion: 0.12
Nodes (7): b(), close(), decorate(), emailForKey(), k(), tick(), w()

### Community 47 - "Xn"
Cohesion: 0.10
Nodes (24): ar(), br(), ca(), ce(), cr(), gi(), gr(), hr() (+16 more)

### Community 48 - "x"
Cohesion: 0.16
Nodes (3): b(), q(), x()

### Community 49 - "ExchangeService"
Cohesion: 0.11
Nodes (3): SyncPlanPrices, ExchangeRateAdminController, ExchangeService

### Community 50 - "Manager"
Cohesion: 0.21
Nodes (3): CircuitBreaker, Logger, Manager

### Community 52 - "z"
Cohesion: 0.11
Nodes (3): CardPaymentController, CardPaymentController, CardPayment

### Community 54 - "require"
Cohesion: 0.11
Nodes (18): require, fideloper/proxy, firebase/php-jwt, fruitcake/laravel-cors, google/recaptcha, guzzlehttp/guzzle, joanhey/adapterman, laravel/framework (+10 more)

### Community 55 - "Illuminate\Foundation\Http\FormRequest"
Cohesion: 0.17
Nodes (4): KnowledgeController, KnowledgeSave, KnowledgeSort, Knowledge

### Community 61 - "Notice"
Cohesion: 0.20
Nodes (4): NoticeController, NoticeController, NoticeSave, Notice

### Community 64 - "install.sh"
Cohesion: 0.35
Nodes (12): backup(), die(), is_supervised(), ok(), probe(), remove_program(), say(), install.sh script (+4 more)

### Community 66 - "Ve"
Cohesion: 0.22
Nodes (18): Be(), ce(), De(), Fe(), ge(), hs(), je(), Le() (+10 more)

### Community 67 - "BotSetting"
Cohesion: 0.19
Nodes (4): SendScheduledBackup, BotSettingController, BotSetting, Backup

### Community 71 - "h"
Cohesion: 0.21
Nodes (3): h(), on(), rn()

### Community 72 - "readme.md"
Cohesion: 0.15
Nodes (12): 📊 آمار پروژه, 🛡️ امنیت, 🔧 بک‌اند پشتیبانی شده, 💖 حامیان, 🎯 عملکرد, 📦 مراحل مهاجرت, 📖 مستندات, 🤝 مشارکت (+4 more)

### Community 73 - "Coupon"
Cohesion: 0.11
Nodes (19): boot(), doToggle(), esc(), fmt(), inject(), j(), keyOf(), load() (+11 more)

### Community 74 - "Payment"
Cohesion: 0.15
Nodes (4): TelegramController, GoogleAuthController, User, Log

### Community 75 - "ServerTrojan"
Cohesion: 0.29
Nodes (3): TrojanController, TrojanTidalabController, ServerTrojan

### Community 78 - "i18n-fa.js"
Cohesion: 0.33
Nodes (10): checkNavigation(), fmtDate(), makeBtn(), openPopup(), schedule(), startObserver(), translateAll(), translateNode() (+2 more)

### Community 79 - "bn"
Cohesion: 0.27
Nodes (11): dn(), es(), fn(), gn(), Hn(), In(), mn(), Os() (+3 more)

### Community 83 - "composer.json"
Cohesion: 0.20
Nodes (9): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+1 more)

### Community 84 - "Clash"
Cohesion: 0.28
Nodes (3): CheckCommission, CheckPendingPayments, Order

### Community 87 - "CouponService"
Cohesion: 0.21
Nodes (3): CouponController, CouponGenerate, Coupon

### Community 90 - "Illuminate\Support\ServiceProvider"
Cohesion: 0.32
Nodes (3): AppServiceProvider, BroadcastServiceProvider, Illuminate\Support\ServiceProvider

### Community 91 - "scripts"
Cohesion: 0.25
Nodes (8): scripts, post-autoload-dump, post-create-project-cmd, post-root-package-install, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, @php artisan key:generate --ansi, @php artisan package:discover --ansi, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 93 - "Handler"
Cohesion: 0.43
Nodes (3): Handler, Illuminate\Foundation\Exceptions\Handler, Throwable

### Community 96 - "autoload"
Cohesion: 0.29
Nodes (7): autoload, classmap, psr-4, App\\, Library\\, database/factories, database/seeds

### Community 97 - "update.sh"
Cohesion: 0.67
Nodes (6): die(), restart_webman(), say(), self_update(), update.sh script, warn()

### Community 103 - "keywords"
Cohesion: 0.33
Nodes (6): keywords, laravel, shadowsocks, trojan, v2board, v2ray

### Community 104 - "require-dev"
Cohesion: 0.33
Nodes (6): require-dev, facade/ignition, fakerphp/faker, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 105 - "Yn"
Cohesion: 0.16
Nodes (3): scopeSetFilterAllowKeys(), PaymentService, TelegramService

### Community 107 - "OrderUpdate"
Cohesion: 1.00
Nodes (3): Un(), Wn(), Gn()

### Community 111 - "init.sh"
Cohesion: 0.70
Nodes (4): die(), say(), init.sh script, warn()

### Community 114 - "KnowledgeCategorySave"
Cohesion: 0.02
Nodes (29): OrderController, AuthController, ConfigSave, GiftcardGenerate, KnowledgeCategorySave, KnowledgeCategorySort, MailSend, OrderAssign (+21 more)

### Community 144 - "config"
Cohesion: 0.50
Nodes (4): config, optimize-autoloader, preferred-install, sort-packages

### Community 145 - "packagist"
Cohesion: 0.50
Nodes (4): type, url, repositories, packagist

### Community 147 - "server_config_agent.sh"
Cohesion: 0.83
Nodes (3): open_port(), server_config_agent.sh script, update_status()

### Community 155 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 156 - "Wn"
Cohesion: 0.18
Nodes (3): UpdatePlanPrices, PlanPriceApiController, ExchangeRateService

## Knowledge Gaps
- **61 isolated node(s):** `restore.sh script`, `Dict`, `name`, `type`, `description` (+56 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **57 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `L()` connect `i` to `admin/umi.js`, `setState`, `setState`, `j`, `t`, `Coupon`, `d`, `d`, `admin/vendors.async.js`, `b`, `admin/components.async.js`, `Xn`, `r`, `i`, `m`, `ot`?**
  _High betweenness centrality (0.036) - this node is a cross-community bridge._
- **Why does `Helper` connect `Helper` to `Illuminate\Http\Request`, `Illuminate\Database\Eloquent\Model`, `Controller`, `V2rayN`, `V2rayNG`, `TelegramBotService`, `Plan`, `Illuminate\Console\Command`, `OrderService`, `Telegram`, `Admin`, `Singbox`, `AuthService`, `ClashNyanpasu`, `ClashVerge`, `Search`, `ClashMeta`, `SingboxOld`, `Payment`, `Stash`, `Loon`, `QuantumultX`, `Surge`, `CouponService`, `Passwall`, `SagerNet`, `SSRPlus`, `General`, `Surfboard`, `v2RayTun`, `V2boardInstall`, `Giftcard`, `Shadowrocket`, `KnowledgeCategorySave`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Why does `W()` connect `n` to `setState`, `j`, `assets/vendors.async.js`, `e`, `h`, `b`, `admin/components.async.js`, `i`, `mu`, `i`?**
  _High betweenness centrality (0.020) - this node is a cross-community bridge._
- **Are the 51 inferred relationships involving `t()` (e.g. with `a()` and `ae()`) actually correct?**
  _`t()` has 51 INFERRED edges - model-reasoned connections that need verification._
- **Are the 51 inferred relationships involving `t()` (e.g. with `a()` and `ae()`) actually correct?**
  _`t()` has 51 INFERRED edges - model-reasoned connections that need verification._
- **Are the 56 inferred relationships involving `r()` (e.g. with `a()` and `b()`) actually correct?**
  _`r()` has 56 INFERRED edges - model-reasoned connections that need verification._
- **What connects `restore.sh script`, `Dict`, `name` to the rest of the system?**
  _61 weakly-connected nodes found - possible documentation gaps or missing edges._