# Tailored pricing and gifts: production rollout, 20 October 2026

The main Laravel backend now runs feature source
`e9c745603cf5ee3f26a4833c1b1e924a87154e1d`. Deployment completed at
07:00:17 UTC. The app's independently verified test build is **2.2.4+150** from
`c2dc4f2b5bacb2d382d2699e08a3c0b65adddd9f`; public app publication remains on hold.

The administrator has a **Tailored / دست‌دوز** sidebar entry directly below
subscription management in both Umi bundles. It supports explicit plan-and-period
reference selection, finite-total/no-reset classification, draft previews,
derived base coefficient, USD/Toman curves and revision-protected saves.
No reference or enabled setting was seeded. The administrator must approve
references and enable sales before customers can order tailored packages.

Build150 places the gold tailored card first inside Base plans, with volume/day
sliders and grouped Toman estimates. Both fixed and tailored subscriptions can
be purchased as gifts. Paid codes remain copyable in the buyer's Orders; the
recipient redeems through Profile → Gift card. Commercial snapshots preserve
the purchased quota, duration and access after price/reference changes.

## Applied changes and preservation

Only the five reviewed nullable/defaulted gift/custom columns and the two exact
unique indexes were added. Existing customer rows, balances, orders and prices
were preserved. Configuration and database backups were private and verified
before schema activation. No database import, broad updater or dependency
update was run.

Both customized production Umi bundles were patched additively: 665 bytes added
per file. Removing the two owned fenced regions reproduces the original bytes.
Installed bundles are 4,884,205 bytes each, SHA256
`951c7179d096082c206309f1311607ca643364328f41c69d1cd2a5a144dd52b9`.
Owner/group1000:1000 and mode0644 were preserved. Environment, Composer lock
and custom application rules were retained.

After financial work drained, all old application processes were confirmed
gone before schema/source activation. Horizon's supervisor stop signal was
corrected to TERM; WebMan retains QUIT, and both stop windows are600 seconds.
Only those two application groups were reloaded. Configuration, route and view
caches were cleared; queues, Redis sessions and queued jobs were retained.

## Validation and limits

- Exact backendCI39289450940: PHP8.1/8.2 mandatory **124 tests /1,017 assertions**
  pass, actual Chromium **21/21** pass. Two additional browser checks used
  copies of the actual customized production bundles.
- The informational whole PHP suite still reports **173 tests /1,304 assertions
  /5 existing GoogleAuth SQLite-fixture errors**. It is not a whole-suite pass.
- Live HTTPS/SNI: guest JSON200, admin HTML200 with addon includes, all four
  admin assets200 with matching hashes; private routes deny anonymous access403.
- Live schema/index definitions and default-disabled/no-reference state pass.
  Both application supervisor groups are RUNNING and maintenance is off.
- Actual app150 files passed **232/232** strict checks. Local Flutter tests:
  1,294 pass/3 skip; exact LinuxCI:1,287 pass/10 platform skips. Analyzer:
  zero errors,30 unchanged warnings,1,122 infos.

Physical Android/VPN behavior, installer execution, real payments and true
parallel MySQL purchases remain owner tests. Earlier rollout attempts stopped
before schema/source activation because of maintenance-response and runtime
drain guards; failed logs were retained. The successful resumed rollout
confirmed zero old application processes before switching source.

After any new gift/custom order is accepted, retain the new fulfilment handlers.
Disable future sales and repair forward if needed. Restoring an old database or
pre-feature fulfilment code after customer payments would discard or misdeliver
purchased entitlements.
