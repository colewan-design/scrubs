# 01 — Requirements Analysis

Section numbers map to the client's *Updated Website Materials & Business Requirements* document.

Legend: ✅ clear and buildable · ⚠️ ambiguous, needs a decision · 🔴 blocking · 💡 recommendation

---

## §1 Business / Branding

✅ Business identity, Canadian market, Ontario base, dual retail + wholesale audience.
✅ Visual direction is unusually well specified: Faire-like, warm off-white, generous whitespace,
no large dark fill sections, black reserved for text/icons/borders/small buttons, homepage split
into WOMEN and MEN as the two primary paths.

🔴 **Logo not supplied.** Blocks final header, favicon, email templates, invoice/PDF header, OG images.
Mitigation: build against a temporary serif wordmark that occupies the same footprint, swap in one commit.

🔴 **Business email and phone not supplied.** Blocks contact page, transactional email `From`/reply-to
addresses, and sender-domain DNS verification (SPF/DKIM/DMARC), which takes lead time to propagate.

⚠️ **Social links "to be provided"** — footer will render only the links supplied; the component
handles an empty set gracefully.

💡 The "avoid large black or dark background-fill sections" instruction is a real constraint on the
design system, not a passing remark. It rules out the dark hero/dark footer pattern most scrub
retailers use. The design system in [04-ui-design-system.md](04-ui-design-system.md) treats
off-white-on-white layering plus hairline borders as the primary means of section separation.

---

## §2 Products

✅ Two launch categories (Women's, Men's) with a structure that accepts more later — handled by a
generic `categories` table with `parent_id`, not by hardcoded routes.
✅ Product page field list is complete and standard.
✅ Collapsible (+/−) sections for Description / Materials / Dimensions & Fit — an accordion component.
✅ Size/colour variants with **per-combination inventory**, manual admin stock adjustment,
automatic decrement on paid order, out-of-stock variants unpurchasable.

⚠️ **"Retail price ~$65/set, wholesale ~$45/set"** are per-*set* figures, but the schema needs to know
whether a "scrub set" is one sellable product (top + bottom sold together) or two products bundled.
This changes SKU structure, inventory, and how a customer picks sizes — a set may need *two* size
selections (top size and bottom size), which is common in scrubs retail.
**This is the single most under-specified product requirement in the document.**
See [06-open-questions.md](06-open-questions.md) Q1.

⚠️ **Size chart** — one global chart, one chart per category, or one per product? Assume per-category
with per-product override; cheap to build that way, expensive to change later.

⚠️ **"Prices and tier thresholds must be editable from the admin dashboard"** — confirms pricing lives
in the database, never in code or config. Drives the `settings` and `pricing_tiers` tables.

🔴 Product photos, final colour list, sizes, SKUs, and opening inventory are all outstanding. The
catalogue can be built and demoed with placeholder data, but **launch is gated** on this arriving.

---

## §3 Wholesale Pricing Access — the core of the build

✅ Public browsing with no account. Retail prices public. Wholesale prices hidden until signed in.
✅ "Unlock wholesale pricing" CTA on cards and product pages opening a lightweight signup/sign-in.
✅ Email/password + Google. Apple sign-in dropped from scope on 2026-09-04 — the brief marked it
optional and it carries a paid developer account plus a six-monthly secret rotation.
✅ No manual approval. MOQ *is* the qualification — explicitly, a customer does **not** need to prove
they run a registered business.
✅ Three tiers, cart auto-applies the correct one, below-threshold customers still buy at retail.
✅ Admin-editable thresholds. Opening MOQ target CAD $200. Free shipping target CAD $600+.
✅ Low-friction registration: name, email, phone, password, optional business name, city/province.

### ⚠️ Contradiction to resolve: what does signing in actually buy you?

The document says two things that pull apart:

- Wholesale prices are **hidden** from logged-out visitors (a *visibility* gate).
- Wholesale eligibility is decided **entirely by order value / MOQ** (an *eligibility* rule that has
  nothing to do with having an account).

Read literally, a logged-out visitor who fills a cart past $200 has met every stated eligibility
condition and should be charged wholesale — which makes the account gate cosmetic and removes the
incentive to sign up.

**Recommendation:** the account is required for wholesale pricing to be *applied*, not just displayed.
A guest cart that crosses the threshold shows a prominent inline prompt — *"You've qualified for
wholesale. Sign in to save $X on this order"* — with the discount applied the moment they authenticate.
This preserves the Faire mechanic, gives signup a concrete dollar value at the exact moment of highest
intent, and is what Faire itself does. **Needs client confirmation** ([06](06-open-questions.md) Q2).

### ⚠️ Contradiction to resolve: are tiers priced by dollars or by units?

"MOQ" (minimum order *quantity*) and "qualifying order value" are used interchangeably throughout §3.
They are different rules and produce different carts:

| Basis | Example | Consequence |
|---|---|---|
| Order value | Spend $200 → Tier 1 | Simple; but a customer buying 4 expensive sets qualifies while one buying 5 cheap sets does not |
| Unit quantity | Buy 10 sets → Tier 1 | Truer "wholesale"; predictable for the buyer; independent of price changes |
| Either (whichever qualifies) | $200 **or** 10 units | Most generous, best conversion, slightly more complex to explain |

**Recommendation:** model both columns (`min_subtotal_cents`, `min_qty`) on every tier and let the admin
leave either blank. Evaluate with OR. This costs almost nothing now and means a change of mind later is
a settings edit, not a migration. **Needs client confirmation** ([06](06-open-questions.md) Q3).

### 🔴 Circular-pricing trap

If tiers are evaluated on order value, and qualifying *lowers* the order value, a cart can oscillate:
$210 retail qualifies → wholesale drops it to $150 → no longer qualifies → back to retail → $210…

**This must be settled in the spec, not discovered in testing.** The rule: **tier qualification is
always evaluated against the pre-discount retail subtotal.** Once qualified, the cart stays qualified;
the discount never removes the qualification that produced it. Same rule for the free-shipping threshold.
Locked into the pricing engine in [03-data-model.md](03-data-model.md).

### ⚠️ Wholesale price definition

"$65 retail / $45 wholesale" implies wholesale is an **absolute price per product**, not a percentage
off. But three tiers over a whole catalogue is a lot of hand-entered numbers.

**Recommendation:** support both per tier — `discount_type` of `percent`, `fixed_amount_off`, or
`absolute_price` — with an optional per-product override table for products whose wholesale price
does not follow the percentage rule. Admin sets a catalogue-wide default and overrides the exceptions.

🔴 **Final three-tier thresholds and prices are outstanding.** The engine ships configurable and can be
demoed with the $200/$45 placeholders, but real numbers are needed before launch.

---

## §4 Orders & Checkout

✅ CAD. Visa/Mastercard/PayPal/wallets. Standard checkout field list. Secure payment, confirmation.
✅ "Architecture should allow BNPL later" — satisfied by choosing a processor that already offers it.

⚠️ **Payment processor is not named** and this is a launch-blocking commercial decision.

**Recommendation: Stripe.** One integration covers Visa/Mastercard/Amex, Apple Pay and Google Pay
(the "debit/wallet options" line), and PayPal as a payment method in CAD. It supports CAD natively,
is fully available to Canadian businesses, keeps card data entirely off our servers via hosted
Elements (PCI SAQ-A — satisfies §12's "card information should not be stored directly"), and lets
Affirm/Klarna be switched on later from a dashboard toggle rather than a rebuild, which is precisely
what §4 and §13 ask for.

Alternatives if the client prefers a Canadian acquirer: **Moneris** or **Helcim** (often lower CAD
interchange, but weaker developer tooling and no single-integration BNPL path).

⚠️ **Interac e-Transfer** — no practical API exists at this business size. Implement as an *offline
payment method*: the order is created in `Pending Payment` with e-Transfer instructions shown on the
confirmation page and emailed; an administrator marks it `Paid` when funds arrive, which triggers the
same downstream flow as a card payment. Inventory is reserved but not decremented until then. Flagged
in [06](06-open-questions.md) Q4 as it needs a business-process decision about hold duration.

⚠️ **Guest checkout** is not addressed anywhere in the brief. Recommendation: allow it for retail-only
carts (removes friction on small orders) while requiring an account for wholesale pricing, which
reinforces the unlock mechanic. Needs confirmation.

---

## §5 Shipping / Pickup

✅ Canada as launch market, live rates preferred, tracking attached to orders and notifications,
admin-configurable rules, free shipping at $600+ (editable), local pickup with post-order instructions.

⚠️ **Stallion Express integration is conditional** — "if their API is compatible with Christian's
proposed technology." It is: Stallion exposes a REST API over HTTPS with token auth, which Laravel's
HTTP client consumes without difficulty. There is no technical incompatibility.

The real risks are operational, not technical:
- 🔴 **API credentials required.** Cannot be built or tested without a live Stallion account and key.
- ⚠️ **Rate accuracy needs real parcel data.** Live rating requires per-variant **weight and package
  dimensions**, which are not in the outstanding-materials list and will need to be captured. Without
  them, live rates will be wrong. Added to [06](06-open-questions.md).
- ⚠️ Stallion's strength is cross-border and Canadian domestic parcel; service coverage and rate
  competitiveness for the client's actual mix should be validated commercially, not just technically.

**Recommendation:** build shipping behind a provider interface with two implementations —
`TableRateProvider` (admin-configured zone/weight matrix) and `StallionProvider` (live rates, label
creation, tracking). Table rates ship first so checkout is never blocked; Stallion slots in behind the
same interface once credentials exist, and remains a fallback if the API is unavailable at checkout time.
This directly answers the brief's "if direct integration is not practical, recommend the best alternative."

⚠️ **Local pickup** needs a pickup address, hours, and readiness lead time from the client.

---

## §6 Canadian Taxes

✅ Destination-based Canadian sales tax, GST/HST/PST/QST configurable, shown separately at checkout and
on invoices, business tax registration number printable on receipts.

⚠️ This is more of a *business* decision than a technical one. The technical work is a
province-keyed rate table and a tax service — straightforward. The hard part is the client confirming
**which provinces they are registered to collect in**, because PST in British Columbia, Saskatchewan and
Manitoba, and QST in Quebec, each carry separate registration obligations that depend on the client's
sales volume and nexus, not on our code.

**Recommendation:** build the rate table with all thirteen provinces/territories and an active flag per
tax type, defaulting to GST/HST everywhere plus Ontario HST at 13%. The client (with their accountant)
switches on PST/QST as they register. Also store the GST/HST number in settings for invoice printing —
§6 requires it. Flagged in [06](06-open-questions.md) Q6; **this needs the client's accountant, not us.**

---

## §7 Order Process & Statuses

✅ The described flow is standard and maps cleanly to the architecture.
✅ Eight statuses: Pending Payment, Paid, Processing, Ready for Pickup, Shipped, Completed, Cancelled, Refunded.

⚠️ These conflate *payment* state with *fulfillment* state — "Refunded" is a payment fact while
"Shipped" is a fulfillment fact, and an order can be both.

**Recommendation:** store two fields internally (`payment_status`, `fulfillment_status`) and derive the
single customer-facing label from them, keeping exactly the eight names the client asked for. The
customer and the admin list both see the client's vocabulary; the system keeps the precision it needs
for partial refunds and for a shipped-then-refunded order. No requirement is lost.

⚠️ **Inventory decrement timing.** §2 says "decrease after a successfully placed/paid order." Between
checkout start and payment confirmation there is a window where two customers can buy the last unit.
**Recommendation:** reserve stock when checkout begins (short TTL, released on abandonment), decrement
on confirmed payment. Detailed in [03-data-model.md](03-data-model.md).

---

## §8 Customer Account

✅ Sign in/out, password reset, Google sign-in, order history and status, tracking,
editable account and delivery details, wholesale pricing while logged in. All standard.

**Apple Sign In: dropped 2026-09-04.** It requires a paid Apple Developer Program membership plus a
Services ID, key, and domain verification, and its client secret is a signed JWT needing rotation
twice a year — recurring cost and recurring maintenance for a second button the brief marked
optional. The auth layer still resolves providers from configuration, so adding one later is a
driver and a config slot rather than a rewrite.

---

## §9 Admin Dashboard

✅ A complete and reasonable list: product/photo/category/colour/size/SKU/inventory CRUD, retail prices,
wholesale tiers and MOQ, shipping and free-shipping settings, customer management with suspend,
order management, status changes, tracking entry, cancellations and refunds, basic reporting, exports.

✅ **DECIDED (2026-09-02): the admin is built on Filament.** Hand-building ~15 admin screens with tables,
filters, bulk actions, image handling, validation, and exports is 4–6 weeks of work that produces
something worse than Filament gives us as configuration. It runs inside the same Laravel application,
against the same models and the same pricing engine, so there is no risk of the admin and the storefront
disagreeing about a price. Handover is a documented `/admin` login, satisfying §12.

⚠️ "Basic sales/order reporting" is underspecified. Proposal: revenue by period, order count, average
order value, retail-vs-wholesale split, top products by units and revenue, low-stock report. Confirm
whether anything else is needed at launch.

---

## §10 Notifications

✅ Verification/reset, order confirmation, payment confirmation, status updates, shipping/tracking,
admin new-order alert. All queued, all templated.

⚠️ "Reliable email-sending service appropriate to the stack" — **recommendation: Postmark or Resend**
for transactional mail. Both have excellent deliverability and Laravel drivers. Requires DNS access to
the client's domain for SPF/DKIM/DMARC, which has propagation lead time — start early.

---

## §11 Website Content / Pages

✅ Fifteen pages listed; all are either application routes or simple content pages.

🔴 **Policy wording for Returns/Refunds, Shipping, Privacy, and Terms is outstanding** and is a genuine
launch blocker — a Canadian storefront should not take payments without them. We build the pages and
the CMS; the client supplies the words (their lawyer's job, not ours).

💡 Two additions worth making: a **cookie/privacy notice** appropriate to PIPEDA, and the
**Wholesale / How It Works** page doing real work — it is the page that explains the tier table and
converts a browser into an account, so it deserves design attention rather than being treated as filler.

---

## §12 Technical / Ownership

✅ Mobile-responsive, HTTPS, no card storage, backups, and full client ownership of domain, hosting,
data, source, payment and third-party accounts, with credentials handed over.

💡 The ownership requirement is best served by the confirmed **VPS deployment** — one server the client
holds the root credentials to, no proprietary platform lock-in, database and code fully portable.
Every third-party account (Stripe, Stallion, email, DNS, storage) must be **created in the client's name
from day one** with us added as a collaborator, rather than created by us and transferred later.
Transfers get forgotten; that is how agencies accidentally end up holding a client's payment account.

💡 Backups need to be specified concretely, not left as a checkbox: nightly automated MySQL dump plus
uploaded-media sync to off-server storage, with a **documented and actually-tested restore procedure**.
An untested backup is not a backup.

---

## §13 Future Expansion

✅ BNPL, more payment methods, more categories, institutional accounts, custom large-account pricing,
coupons, analytics/pixels, email marketing and abandoned cart, expanded reporting, more shipping
integrations, possible mobile app.

💡 Three of these should influence V1 architecture even though they are not being built:
- **Coupons** — leave a `discount_id` seam on orders so a coupon system does not require reworking totals.
- **Custom pricing for large accounts** — the tier engine should resolve a *customer-specific* price list
  before falling back to global tiers, even if no customer has one at launch. One nullable column now.
- **Analytics/pixels** — a consent-gated script slot in the Nuxt app, so adding GA4 later is a settings
  entry rather than a deploy.

---

## Gaps: present in a real store, absent from this brief

Raising these now because each is cheaper to decide than to retrofit:

| Gap | Recommendation for V1 |
|---|---|
| Catalogue search | Include. MySQL full-text is sufficient at this catalogue size. |
| Filtering & sorting (size, colour, price) | Include on category pages — near-mandatory for apparel. |
| Guest checkout | Allow for retail carts; require account for wholesale. Confirm. |
| Returns/RMA handling | Policy page only in V1; refunds are recorded in admin. No self-serve returns. |
| Product reviews | Out of scope for V1. |
| Wishlist / saved carts | Out of scope, though "save cart" suits repeat wholesale buyers — note for V2. |
| SEO fundamentals | Include: SSR, per-page meta, canonical URLs, `Product` structured data, sitemap, robots. Nuxt SSR is chosen partly for this. |
| Accessibility (AODA / WCAG 2.0 AA) | Build to it from the start. The client is an Ontario business and this is both a legal consideration and materially cheaper now than later. |
| Rate limiting, bot protection on auth | Include — Laravel throttling plus a captcha on registration. |
| Order-number scheme | Human-readable sequential (`BSD-10001`), never the raw database id. |
| Abandoned-cart capture | Out of scope (§13), but persist carts server-side so V2 can use the data. |

---

## Risk register

| # | Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|---|
| R1 | Client-supplied materials (§14) arrive late | Delays launch directly | **High** | Front-load requests; build against realistic placeholders; make content the last thing swapped in |
| R2 | Tier basis (value vs quantity) changes after build | Rework of pricing engine + cart UX | Medium | Model both columns from day one; decision recorded before Phase 3 |
| R3 | Stallion credentials unavailable or rates unsuitable | Checkout shipping incomplete | Medium | Table-rate provider built first and kept permanently as fallback |
| R4 | Missing parcel weights/dimensions | Live rates inaccurate | **High** | Capture per-variant at catalogue load; validate before enabling live rates |
| R5 | PST/QST registration undecided | Incorrect tax collected — a compliance issue | Medium | Rate table with per-province toggles; client's accountant confirms before launch |
| R6 | Payment processor decision delayed | Blocks all of Phase 4 | Medium | Decide in Phase 0; abstract payments behind an interface regardless |
| R7 | Scope creep from §13 into V1 | Timeline and budget | Medium | §13 is explicitly out of scope; changes go through change control |
| R8 | Scrub "set" product structure misunderstood | Catalogue and SKU rework | Medium | Resolve Q1 before Phase 2 begins |
| R9 | Single VPS is a single point of failure | Downtime | Low–Medium | Automated off-server backups, tested restore, uptime monitoring, documented rebuild |
