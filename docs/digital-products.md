# Digital products

Sell anything besides subscriptions: Apple IDs, gift cards, virtual or
dedicated servers, files. Code: `app/Services/ProductOrderService.php`.

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
