# Tailored subscriptions as gifts

The base-plan purchase flow supports both personal and gift intent. A gift
uses the displayed raw tariff without a buyer's VIP discount or discount code.
The eligible buyer may pay from their wallet. Server USD model, exchange rate
and the existing thousand-Toman rounding still determine the tariff.

Authenticated routes:

- `GET /api/v1/user/plan/custom` advertises `supports_custom_gift` when enabled.
- `POST /api/v1/user/plan/custom/quote` accepts `is_gift: true`; its encrypted
  token and public `purpose: "gift"` / `is_gift: true` bind that intent.
- `POST /api/v1/user/order/saveCustomGift` saves that gift quote. The personal
  save route rejects gift quotes, and the gift route rejects personal quotes.

Payment issues a single-use 256-bit code, represented as 64 uppercase hex
characters so the existing `gift_code_snapshot VARCHAR(64)` stores it intact.
The owner's order receipt retains that code. Ordinary gift codes keep their
existing `GIFT-` format. A gift does not activate a buyer subscription.

The recipient uses the existing gift-card redemption endpoint. Delivery copies
only the purchased commercial snapshot: exact quota, days, access and pricing
provenance. Buyer activation/history metadata is excluded. Later reference
withdrawal does not revoke a paid entitlement. A current recipient subscription
uses the existing reservation rules, without creating cash surplus.

The new admin sidebar is `#/tailored-pricing`, immediately below subscription
management in both Umi bundles. No reference or enabled setting is seeded on
deployment; the administrator explicitly chooses each eligible plan and period.
