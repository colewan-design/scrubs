# 03 — Data Model, Pricing, Tax & Inventory

Conventions used throughout:

- All money is `BIGINT` **cents**, column names suffixed `_cents`. No floats anywhere in the money path.
- All tables carry `id BIGINT UNSIGNED AUTO_INCREMENT`, `created_at`, `updated_at`.
- Customer-facing records that can be soft-removed carry `deleted_at`.
- Enums are stored as short strings, not MySQL `ENUM`, so adding a value is not a schema migration.

---

## Entity overview

```
users ──< addresses
  │
  └──< orders ──< order_items          (immutable price snapshots)
         │    ├──< order_taxes
         │    ├──< order_status_history
         │    ├──< shipments
         │    ├──< payments ──< refunds
         │    └──── order_addresses
         │
carts ──< cart_items                    (server-side, survives session)

categories ──< products ──< product_variants ──< inventory_movements
                   │              │
                   ├──< product_images
                   └──< product_tier_prices  ──┐
                                                ├── pricing_tiers
                       customer_price_lists  ──┘   (V2 seam, §13)

settings          tax_rates        shipping_zones ──< shipping_rates
```

---

## Catalogue

### `categories`
| Column | Type | Notes |
|---|---|---|
| `parent_id` | FK nullable | Supports future nesting without redesign (§2) |
| `name`, `slug` | string, unique | `women`, `men` at launch |
| `description` | text nullable | Category landing copy |
| `image_path` | string nullable | Tile image for the category grid |
| `size_chart_id` | FK nullable | Per-category size chart, overridable per product |
| `position`, `is_active` | int, bool | Manual ordering in admin |

### `products`
| Column | Type | Notes |
|---|---|---|
| `category_id` | FK | |
| `name`, `slug` | string | |
| `base_sku` | string unique | Variant SKUs derive from it |
| `short_description` | text | Card and meta description |
| `description` | longtext | Accordion panel 1 (§2) |
| `materials` | longtext | Accordion panel 2 |
| `dimensions_fit` | longtext | Accordion panel 3 |
| `size_chart_id` | FK nullable | Overrides the category chart |
| `retail_price_cents` | bigint | Default; a variant may override |
| `wholesale_base_price_cents` | bigint nullable | The "~$45/set" reference figure |
| `product_type` | string | `set` / `top` / `bottom` — see Q1 |
| `is_active`, `is_featured` | bool | |
| `meta_title`, `meta_description` | string nullable | SEO |
| `published_at` | timestamp nullable | Scheduled publishing |

### `colors` / `sizes`
Admin-managed lookup tables (§9). `colors` holds `name`, `slug`, `hex` (for the swatch UI seen in the
reference design) and `position`. `sizes` holds `name` (`XS`…`3XL`), `slug`, `position` — position
matters because sizes must sort by garment order, never alphabetically.

### `product_variants` — inventory is tracked here (§2)
| Column | Type | Notes |
|---|---|---|
| `product_id`, `color_id`, `size_id` | FK | Unique together |
| `sku` | string unique | |
| `retail_price_cents` | bigint nullable | Overrides the product price |
| `stock_qty` | int | On-hand |
| `reserved_qty` | int | Held by in-flight checkouts |
| `low_stock_threshold` | int | Drives the admin low-stock report |
| `weight_grams` | int nullable | **Required for live Stallion rates** (Risk R4) |
| `length_mm`,`width_mm`,`height_mm` | int nullable | Same |
| `is_active` | bool | |

**Available to sell = `stock_qty - reserved_qty`.** A variant at zero available is rendered as
selectable-but-disabled with an "Out of stock" label, never hidden — a shopper needs to see that their
size exists but is unavailable (§2).

> **Open question Q1 affects this table.** If a scrub *set* requires independent top and bottom sizing,
> the variant key becomes `(color, top_size, bottom_size)` and this schema needs a second size column.
> Must be resolved before Phase 2.

### `product_images`
`product_id`, `color_id` (nullable — lets the gallery switch with the selected colour), `path`,
`alt_text`, `position`, `is_primary`.

---

## Pricing — the critical path

### `pricing_tiers` (§3, admin-editable)
| Column | Type | Notes |
|---|---|---|
| `name` | string | "Tier 1", or client's naming |
| `min_subtotal_cents` | bigint nullable | Value-based qualification |
| `min_qty` | int nullable | Quantity-based qualification (MOQ) |
| `qualify_mode` | string | `any` (OR) or `all` (AND) — resolves Q3 without a migration |
| `discount_type` | string | `percent` · `fixed_amount_off` · `absolute_price` |
| `discount_value` | bigint | Basis points if percent, cents otherwise |
| `priority` | int | Highest qualifying priority wins |
| `is_active` | bool | |

Both `min_subtotal_cents` and `min_qty` exist deliberately. The brief uses "MOQ" and "order value"
interchangeably; carrying both columns means the client's eventual answer to Q3 is a settings change,
not a rebuild.

### `product_tier_prices` — per-product overrides
`product_id` (or `variant_id`), `pricing_tier_id`, `price_cents`. Used where a product's wholesale price
does not follow the catalogue-wide rule. The "$65 retail / $45 wholesale" figures are exactly this shape.

### `customer_price_lists` — V2 seam
A nullable `price_list_id` on `users`, resolved before global tiers. Not built in V1, but the column and
the resolution order exist now so §13's "custom pricing for very large wholesale accounts" is an
addition rather than a refactor.

### `PricingService` — the one place prices are decided

```php
PricingService::quote(Collection $lines, ?User $user): PriceQuote
```

Resolution order, executed server-side only:

1. **Compute the retail subtotal** — sum of `retail_price × qty` across all lines, and the total unit count.
2. **Determine visibility** — wholesale figures are only ever included in the response for an
   authenticated user (§3). A guest receives retail numbers plus an `unlock_prompt` payload
   describing what they *would* save, with no wholesale prices in it.
3. **Match a tier** — evaluate every active tier against the **retail** subtotal and quantity using its
   `qualify_mode`; select the highest `priority` match.
4. **Resolve the unit price** per line: customer price list → product tier override → tier discount rule
   → retail. First match wins.
5. **Return a `PriceQuote`** containing per-line unit prices, the retail subtotal, discount total, the
   matched tier, the *next* tier with the gap to reach it, and the free-shipping gap.

### Three rules the engine must never break

1. **Qualification is evaluated on the pre-discount retail subtotal.** This is what prevents the
   oscillation described in [01](01-requirements-analysis.md#-circular-pricing-trap): qualify → discount
   → fall below threshold → un-qualify → re-qualify. The discount can never revoke the qualification
   that produced it. The same rule applies to the $600 free-shipping threshold.
2. **The frontend never computes money.** The cart displays what `PriceQuote` returned.
3. **Prices are re-quoted at order creation** inside the checkout transaction, then snapshotted onto
   `order_items`. A cart that has sat open for two days must not be able to charge stale prices.

The `next_tier` gap returned in step 5 is what powers the strongest merchandising moment in the whole
site — *"Add $46 more to unlock Tier 2 and save $120"* — turning the tier system from a pricing rule
into an active upsell.

---

## Cart

`carts`: `user_id` nullable, `session_token` for guests, `expires_at`. Server-side so a guest cart
survives, merges into the account on login, and remains available for §13's abandoned-cart work.

`cart_items`: `cart_id`, `product_variant_id`, `qty`, `added_at`. **No price columns** — prices are
always live-quoted, never cached on the row.

---

## Orders

### `orders`
| Column | Notes |
|---|---|
| `order_number` | Human-readable `BSD-10001`; never expose the database id |
| `user_id` | Nullable if guest checkout is permitted |
| `email`, `phone` | Snapshot at purchase |
| `status` | The customer-facing label — the eight names from §7 |
| `payment_status` | `pending` · `paid` · `partially_refunded` · `refunded` · `failed` |
| `fulfillment_status` | `unfulfilled` · `processing` · `ready_for_pickup` · `shipped` · `completed` · `cancelled` |
| `fulfillment_type` | `ship` · `pickup` (§5) |
| `pricing_tier_id` | Nullable — which tier applied |
| `subtotal_cents` | Retail subtotal before discount |
| `discount_cents` | Wholesale saving |
| `shipping_cents`, `tax_cents`, `grand_total_cents` | |
| `currency` | `CAD` (§4) |
| `placed_at`, `paid_at`, `shipped_at`, `completed_at`, `cancelled_at` | |
| `admin_notes`, `customer_note` | |

`status` is stored for display and reporting, but is **derived** from `payment_status` +
`fulfillment_status` by `OrderService` rather than set by hand. This keeps exactly the eight names §7
asks for while letting the system represent a shipped-then-refunded order correctly.

### `order_items` — immutable snapshot
`order_id`, `product_variant_id` (nullable if later deleted), and copies of `product_name`, `variant_sku`,
`color_name`, `size_name`, `qty`, `unit_retail_cents`, `unit_price_cents`, `line_discount_cents`,
`line_total_cents`, `pricing_tier_name`.

The duplication is intentional. Editing a price or renaming a product in admin must never alter a
historical order or a previously issued invoice.

### Supporting tables
- `order_addresses` — `type` (`shipping`/`billing`), full address snapshot.
- `order_status_history` — `from`, `to`, `changed_by`, `note`, `created_at`. Feeds the customer's order
  timeline and gives admin an audit trail (§7, §9).
- `payments` — `provider`, `provider_reference`, `method`, `status`, `amount_cents`, `raw_response` JSON.
- `refunds` — `payment_id`, `amount_cents`, `reason`, `provider_reference`, `created_by` (§9).
- `shipments` — `carrier`, `service`, `tracking_number`, `tracking_url`, `cost_cents`, `label_path`,
  `provider_shipment_id`, `shipped_at` (§5). Multiple rows allow split shipments later.

### Status flow (§7)

```
                    ┌──────────────► Cancelled
                    │
Pending Payment ────┼──► Paid ──► Processing ──┬──► Ready for Pickup ──► Completed
                    │                          └──► Shipped ───────────► Completed
                    │
                    └──► (e-Transfer: admin marks Paid manually)

Any paid state ──► Refunded / Partially Refunded
```

Transitions run through `OrderService` so every change writes history and fires the right notification.
Illegal transitions are rejected rather than silently accepted.

---

## Inventory (§2, §7)

`inventory_movements` — an append-only ledger: `product_variant_id`, `delta`, `reason`
(`purchase` · `manual_adjustment` · `refund_restock` · `cancellation` · `correction`), `reference_type`,
`reference_id`, `user_id`, `note`. `stock_qty` is the running total; the ledger explains every change.
When stock is wrong, this table is how anyone finds out why.

**Reservation lifecycle** — resolving the oversell window §2/§7 leave open:

| Event | Effect |
|---|---|
| Checkout begins | `reserved_qty += n` on each variant, TTL ~20 min |
| Payment confirmed | `stock_qty -= n`, `reserved_qty -= n`, ledger row written |
| Checkout abandoned / TTL expires | `reserved_qty -= n` by scheduled job |
| Order cancelled before fulfilment | Stock returned, ledger row written |
| Refund with restock | Stock returned, ledger row written |

Stock mutations run inside a database transaction with `SELECT … FOR UPDATE` on the variant rows.
Without row locking, two simultaneous checkouts for the last unit will both succeed — the classic
oversell bug, and one that only appears under exactly the traffic a successful promotion produces.

Webhook handlers are **idempotent**, keyed on the provider event id. A replayed Stripe webhook must
never decrement stock twice or send a second confirmation email.

---

## Tax (§6)

`tax_rates`: `province_code`, `tax_type` (`GST`/`HST`/`PST`/`QST`), `rate_bps` (basis points — 13% = 1300),
`applies_to` (`goods`/`shipping`), `is_active`, `effective_from`, `effective_to`.

`order_taxes`: one row per applied tax, so checkout and invoices can itemise (`HST 13% — $8.45`) exactly
as §6 requires.

`TaxService::calculate(destination, lineTotals, shippingTotal)` resolves by **destination province**,
returns itemised lines, and is called at quote time and again at order creation.

**Whether shipping is taxable, and at what rate, varies by province** — it is configured per rate row,
not hardcoded.

The GST/HST registration number lives in `settings` and prints on invoices and receipts (§6).

Rate rows are effective-dated so a rate change is a new row, and reprinting an old invoice still produces
the tax that was actually charged.

> Which provinces the client is *registered* to collect in is a business decision requiring their
> accountant — see [06](06-open-questions.md) Q6. We ship the table with all thirteen jurisdictions and
> per-type toggles, defaulting to GST/HST with Ontario HST at 13%.

---

## Shipping (§5)

`shipping_zones` — name plus a list of province codes.
`shipping_rates` — `zone_id`, `name`, `min_weight_grams`/`max_weight_grams`,
`min_subtotal_cents`/`max_subtotal_cents`, `rate_cents`, `is_free`, `position`.

Together these are the `TableRateProvider` that guarantees checkout works with or without Stallion.

`ShippingProvider` interface: `quote(destination, parcel): ShippingOption[]` ·
`createShipment(order): Shipment` · `track(trackingNumber): TrackingStatus`.

Implementations: `TableRateProvider` (built first, permanent fallback) and `StallionProvider` (live
rates, label creation, tracking — added once credentials arrive). If a live rate call fails or times out
at checkout, the service falls back to table rates rather than blocking the sale.

**Local pickup** is a `fulfillment_type`, bypassing rate calculation entirely and surfacing pickup
instructions from settings on the confirmation page and email (§5).

---

## `settings` — everything §9 requires editable without code

Key/value with JSON values and a typed accessor:

| Key | Default | Requirement |
|---|---|---|
| `wholesale.min_order_cents` | `20000` ($200) | §3 |
| `shipping.free_threshold_cents` | `60000` ($600) | §3, §5 |
| `shipping.provider` | `table_rate` \| `stallion` | §5 |
| `tax.gst_number` | — | §6 |
| `pickup.address`, `pickup.hours`, `pickup.lead_time` | — | §5 |
| `store.email`, `store.phone`, `store.social.*` | — | §1 |
| `orders.reservation_ttl_minutes` | `20` | §7 |
| `orders.etransfer_enabled`, `orders.etransfer_instructions` | `false` | §4 |
| `inventory.low_stock_threshold` | `5` | §9 |

---

## Indexing

Beyond primary and foreign keys:

- `products`: `(slug)` unique · `(category_id, is_active, published_at)`
- `product_variants`: `(product_id, color_id, size_id)` unique · `(sku)` unique · `(is_active, stock_qty)`
- `orders`: `(order_number)` unique · `(user_id, created_at)` · `(status, created_at)` · `(email)`
- `order_items`: `(order_id)` · `(product_variant_id)`
- `carts`: `(session_token)` · `(user_id)` · `(expires_at)` for the cleanup job
- `inventory_movements`: `(product_variant_id, created_at)`
- `tax_rates`: `(province_code, tax_type, is_active)`
- Full-text on `products(name, short_description)` for catalogue search
