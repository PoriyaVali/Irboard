# The store and digital products

The store shows three things, on the website and in the app's store tab
alike, all managed on one admin page (**Store**, `#/store`):

- **banners**, each leading to a product, a category of products, a web
  address or a page of the site;
- **digital products**: anything besides subscriptions (Apple IDs, gift
  cards, virtual or dedicated servers, files). Code:
  `app/Services/ProductOrderService.php`;
- **apps** to download, by platform.

Banners and apps are kept by `app/Services/StoreCatalog.php` in
`storage/appstore/apps.json`. The website reads them from
`/user/appstore/list`; the app receives the same list as `store` in its
config document (`guest/comm/appConfig`), whose ETag follows the list. The
config file's own `store` section is only used while the Store page has
nothing in it.

## Concepts

- **Product** (`v2_product`): name, category, description, image (https),
  price in toman, a **field list** and a **delivery mode**.
- **Field list**: what one unit of the product carries, e.g. for an Apple ID
  `email`, `password` (secret), `birthday`, `questions`. Types: `text`,
  `secret`, `date`, `link`, `note`. At most 20 fields.
- **Delivery modes**
  - `stock`: units are imported into `v2_product_item`; each paid order takes
    one. Ordering reserves a unit so two buyers cannot pay for the last one;
    cancelling gives it back.
  - `manual`: the order waits until an admin fills in the fields (a server).
  - `unlimited`: every buyer receives the same content (a file link).

A product order is an ordinary order (`period = product`, `plan_id = 0`,
`product_id`, `product_snapshot`), so gateways, card-to-card, the wallet and
admin "mark paid" all work unchanged. On payment `OrderService::openOnce()`
hands it to `ProductOrderService::fulfil()`. A stock order whose unit is gone
(paid after cancelling, for instance) waits for the admin like a manual one.

Unit data, unlimited content and what each buyer received are encrypted with
the application key. They are returned only to the buyer of that order and to
admins, and never appear in the store listing.

## Database

Run the panel update (`php artisan v2board:update`). It adds `v2_product`,
`v2_product_item`, `v2_product_delivery` and two columns on `v2_order`. Until
then the store returns an empty list and the admin API answers 503.

## User API (`/api/v1/user`)

| Method | Path | |
|---|---|---|
| GET | `/product/fetch` | Visible products: fields (labels only), `in_stock`, `remaining` when low |
| POST | `/product/order` | `product_id`, `use_wallet` (default true) → `trade_no`; pay it with `/order/checkout` |
| GET | `/product/purchases` | Paid product orders of this account, without their data |
| GET | `/product/delivery` | `trade_no` → the delivered fields, or `waiting` |

`/order/fetch` and `/order/detail` mark product orders with `is_product`,
name them after the product, and `/order/detail` includes `delivery`.

## Admin API (secure path)

| Method | Path | |
|---|---|---|
| GET | `/product/fetch` | Products with stock counts, waiting orders, low-stock flag |
| POST | `/product/save` | Create or update (`id` to update) |
| POST | `/product/drop` | Delete; a product with sales is hidden instead |
| GET | `/product/items` | Units of a product with their data (`status`, paging) |
| POST | `/product/import` | `product_id`, `text`: one unit per line, values in field order separated by `\|` or a tab, or a JSON object per line |
| POST | `/product/item/update` | Correct or withdraw a unit still on the shelf |
| POST | `/product/item/drop` | Delete a unit still on the shelf |
| GET | `/product/deliveries` | Paid product orders; `status=0` for those waiting |
| POST | `/product/deliver` | `id`, `data` (by field key), `note`: deliver a waiting order |

## Web admin

The "فروشگاه" page (`#/store`) is added to both admin bundles by
`scripts/patch_admin_products.py` (fenced and idempotent; CI runs it with
`--check`). It replaces the earlier "app store" menu entry, and the old
`#/products` and `#/app-store` addresses lead to it. Tabs:

- products with their stock: a field editor and presets (Apple ID, gift card,
  virtual and dedicated server, file), bulk stock import, units;
- orders waiting to be delivered, and delivered ones;
- banners: title, colour, optional https image, and where a click leads
  (`product:12`, `category:apple-id`, an https address or `#/...`);
- apps by platform (android, windows, ios, macos, linux).

## Store admin API (secure path)

| Method | Path | |
|---|---|---|
| GET | `/appstore/list` | Banners and app categories |
| POST | `/appstore/save-banner` | `id` to update; `title` or `image` required; invalid `link` is refused |
| POST | `/appstore/delete-banner` | `id` |
| POST | `/appstore/save-app` | `category`, `name`, `downloadUrl` (`id` made from the name if left out); moving to another category removes it from the old one |
| POST | `/appstore/delete-app` | `category`, `app_id`; an emptied category is removed |

