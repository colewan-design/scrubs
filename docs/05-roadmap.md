# 05 — Delivery Roadmap

**Indicative total: ~14 working weeks to launch**, assuming a single full-time developer and that
client-supplied materials arrive on the schedule below. Estimates are ranges, not commitments — the
dominant variable is client input, not engineering (Risk R1).

---

## Phase overview

| # | Phase | Duration | Depends on |
|---|---|---|---|
| 0 | Discovery, decisions & setup | 1 wk | Client answers to Q1–Q4 |
| 1 | Foundations: auth, design system, schema | 2 wks | Phase 0 |
| 2 | Catalogue & product experience | 2 wks | Phase 1, Q1 resolved |
| 3 | Pricing engine & cart | 1.5 wks | Phase 2, Q2/Q3 resolved |
| 4 | Checkout: shipping, tax, payments | 2.5 wks | Phase 3, payment + Stallion accounts |
| 5 | Accounts, orders & notifications | 1.5 wks | Phase 4, email DNS |
| 6 | Admin completion & content pages | 1.5 wks | Phase 5, policy wording |
| 7 | Hardening, UAT & launch | 2 wks | All; real catalogue data |

---

## Phase 0 — Discovery, decisions & setup · 1 week

Nothing here is code, and it is the highest-leverage week in the project.

- Walk the client through [06-open-questions.md](06-open-questions.md); get Q1–Q4 answered in writing.
- Confirm the payment processor and open the merchant account (**this has approval lead time — start
  day one**).
- Open the Stallion Express account, obtain API credentials.
- Provision the VPS in a Canadian region; DNS onto Cloudflare.
- Create every third-party account **in the client's name** with developer access added (§12).
- Start email sender-domain verification — SPF/DKIM/DMARC propagation is measured in days, not minutes.
- Repositories, CI pipeline, staging environment, project board.
- Confirm the display typeface against the logo direction.

**Exit:** Q1–Q4 answered · payment processor chosen · VPS and staging reachable · CI green on an empty app.

---

## Phase 1 — Foundations · 2 weeks

- Laravel 12 skeleton: layered structure, service interfaces, Pest, PHPStan.
- Nuxt 4 skeleton: SSR, Tailwind wired to the [design tokens](04-ui-design-system.md), Pinia, typed API client.
- **Full MySQL schema and migrations** from [03-data-model.md](03-data-model.md), with factories and seeders.
- Auth end-to-end: Sanctum cookie sessions, registration (§3's short field list), login, password reset,
  email verification, **Google OAuth via Socialite**, rate limiting and captcha.
- Filament installed, admin guard, 2FA, roles.
- **Component library in Storybook** — primitives and composites per [04](04-ui-design-system.md),
  including `WholesaleLock` and `TierProgress` even though pricing lands in Phase 3.
- Header, footer, sticky conversion bar, page shell.

**Exit:** a user can register with email or Google on staging · design system documented and reviewed by
the client · schema migrates cleanly.

**Client review gate:** the component library and header/hero are the first visual checkpoint. Getting
sign-off here is far cheaper than reworking the look after twenty pages exist.

---

## Phase 2 — Catalogue & product experience · 2 weeks

- Filament: Product, Category, Colour, Size, Variant resources with image management, inline stock
  adjustment, and the inventory-movement ledger (§9).
- Catalogue API: category listing with filter/sort/paginate, product detail, search (MySQL full-text),
  variant availability.
- Nuxt: homepage with the WOMEN/MEN split hero, category pages with filter bar, product detail with
  gallery, colour/size selectors, quantity stepper, size-chart modal, and the +/− accordion (§2).
- SEO: SSR meta, canonicals, `Product` JSON-LD, sitemap, robots.
- Placeholder catalogue seeded so the client can review real layouts before their photography exists.

**Exit:** a full browse → product → variant-selection journey works on staging, retail pricing only.

> **Gated on Q1** (scrub-set structure). Starting this phase without that answer risks reworking the
> variant schema and every selector built on it.

---

## Phase 3 — Pricing engine & cart · 1.5 weeks

The commercial heart of the build.

- `PricingService` with the full resolution order and the three inviolable rules from
  [03](03-data-model.md) — including qualification always evaluated on the **pre-discount retail
  subtotal**.
- `pricing_tiers` + `product_tier_prices` Filament resources; thresholds and prices editable without
  code (§3, §9).
- Server-side cart: add/update/remove, guest→account merge on login, live re-quoting.
- The wholesale lock UX across card, product page, and cart; tier-progress meter; next-tier upsell.
- Wholesale / How It Works page with the tier ladder (§11).
- **Heavy automated test coverage on the pricing engine** — tier boundaries, exactly-at-threshold carts,
  the oscillation case, mixed retail/wholesale lines, guest vs authenticated visibility.

**Exit:** a cart crossing $200 (or the confirmed MOQ) applies the correct tier for an authenticated user,
hides wholesale figures from guests, and the engine's test suite passes at boundary values.

**Client review gate:** demo the tier behaviour live. This is where a misunderstanding about Q2/Q3
becomes visible, and it must surface here rather than during UAT.

---

## Phase 4 — Checkout · 2.5 weeks

The longest and highest-risk phase; three external integrations land together.

- `TaxService` + province rate table with per-type toggles; itemised tax lines (§6).
- `ShippingProvider` interface · `TableRateProvider` with admin-managed zones and rates ·
  **`StallionProvider`** for live rates, labels and tracking, with automatic fallback to table rates
  if the API errors or times out (§5).
- Local pickup as a fulfilment type with instructions from settings (§5).
- Free-shipping threshold applied on the retail subtotal (§5).
- `PaymentGateway` interface + Stripe: Payment Intents, hosted Elements (no card data on our servers),
  Apple/Google Pay, PayPal, **signature-verified idempotent webhooks** (§4, §12).
- Optional e-Transfer offline flow if the client confirms it (Q4).
- `CheckoutService`: address capture, quote re-validation, **stock reservation with row locking**, order
  creation inside a transaction, price snapshotting, inventory decrement on confirmed payment (§7).
- Checkout UI: contact, address, shipping/pickup selection, live totals, payment, confirmation page.

**Exit:** a real test transaction completes end-to-end with correct tax, shipping, tier discount and
inventory decrement; a replayed webhook changes nothing.

---

## Phase 5 — Accounts, orders & notifications · 1.5 weeks

- Customer account: profile, saved addresses, order history, order detail with status timeline and
  tracking (§8).
- Order status machine with history and legal-transition enforcement; Filament actions for status
  changes, tracking entry, cancellation and refund (§7, §9).
- All transactional email, queued and templated to the design system: verification, password reset,
  order confirmation, payment confirmation, status updates, shipping/tracking, admin new-order alert (§10).
- Guest order lookup via signed URL.

**Exit:** the full §7 lifecycle is exercisable from admin, and each transition delivers the right email.

---

## Phase 6 — Admin completion & content · 1.5 weeks

- Reporting widgets: revenue by period, order count, AOV, retail-vs-wholesale split, top products,
  low stock (§9).
- CSV/XLSX exports for orders, customers, products (§9).
- Customer management including suspend/disable (§9).
- Settings screens: MOQ, tier thresholds, free-shipping threshold, tax numbers, pickup details, store
  contact, social links (§9).
- Content pages: About, Contact with working form, Shipping/Pickup, Returns/Refunds, Privacy, Terms,
  plus a PIPEDA-appropriate cookie notice (§11).
- Consent-gated analytics slot for later GA4 (§13).

**Exit:** staff can run every routine operation in §9 without a developer — the phase's actual test is
the client performing these tasks unaided.

---

## Phase 7 — Hardening, UAT & launch · 2 weeks

- **Real catalogue load**: products, photos, colours, sizes, SKUs, prices, opening inventory, and
  **per-variant weights and dimensions** (needed for live rates — Risk R4).
- Final tier thresholds and prices; final shipping rules; confirmed tax jurisdictions.
- Cross-browser and real-device testing; mobile is a named priority in §1.
- Accessibility audit against WCAG 2.0 AA; keyboard and screen-reader passes.
- Performance: Lighthouse ≥90 mobile, image pipeline, caching, query profiling.
- Security review: headers, rate limits, authorisation policies on every order endpoint, dependency audit.
- **Backup and restore drill, documented** (§12) — an untested backup does not count.
- Sentry and uptime monitoring live.
- Client UAT on staging; fix cycle.
- Production cutover: DNS, TLS, Stripe live keys, smoke test with a real low-value order.
- **Handover**: admin credentials, all third-party account ownership confirmed, source and database
  transferred, runbook and admin guide, walkthrough session (§12).

**Exit:** the client holds every credential, has been trained, and a real order has completed in production.

---

## Client-supplied materials — deadlines

Section 14's list, ordered by when it actually blocks work. **This is the schedule's critical path.**

| Needed by | Item | Blocks |
|---|---|---|
| Phase 0 | Answers to Q1–Q4 | Schema, cart UX, all of Phase 4 |
| Phase 0 | Payment processor decision + merchant account | Phase 4 (approval lead time) |
| Phase 0 | Stallion account + API key | Live shipping rates |
| Phase 0 | DNS access | Email deliverability (propagation lead time) |
| Phase 1 | Final logo file | Header, favicon, emails, invoices, OG images |
| Phase 1 | Business email + phone | Contact page, email sender identity |
| Phase 2 | Product names, SKUs, colours, sizes, descriptions | Real catalogue |
| Phase 2 | Product photography | Everything visual — the design is photography-led |
| Phase 3 | **Final three tier thresholds and prices** | Pricing configuration |
| Phase 4 | Confirmed tax jurisdictions (with their accountant) | Correct tax collection |
| Phase 4 | Shipping rules/rates if live rating is not used | Table-rate fallback |
| Phase 4 | Pickup address, hours, lead time | Pickup option |
| Phase 6 | Policy wording — returns, shipping, privacy, terms | **Legal blocker for launch** |
| Phase 7 | Opening inventory + per-variant weights/dimensions | Stock accuracy, live rates |
| Phase 7 | Social links | Footer |

---

## Definition of done

Every phase must satisfy all of:

- Feature complete against the referenced requirement sections
- Automated tests passing (Pest for services, Vitest for components); pricing, tax and inventory carry
  the heaviest coverage
- Responsive from 360px through 1920px
- WCAG 2.0 AA on new UI
- No N+1 queries on any list endpoint
- Deployed to staging and demonstrated to the client
- Admin-facing behaviour documented in the handover guide

## Change control

§13 is explicitly out of scope for V1. Anything from that list, or any new requirement, is estimated and
scheduled separately rather than absorbed. The architecture already leaves seams for coupons, customer
price lists, BNPL and analytics precisely so those additions are cheap later — which is the best reason
not to build them now.
