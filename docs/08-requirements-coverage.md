# 08 — Requirements coverage audit

Audited against *BulkScrubs Direct — Updated Website Materials & Business Requirements*, section by
section, on **2026-09-03**. Every ✅ below was verified against code in this repository, not against
the plan. Where something is partial or missing it says so, and says whether it is blocked on us, on
the client, or on a third party.

> **Updated 2026-09-03**, after the audit: the two gaps it found that were ours alone to close —
> the tax registration number on receipts (§6) and the backup process (§12) — have been implemented
> and are covered by tests. The sections below reflect that.

## Verdict in one paragraph

**Fourteen of the fifteen sections are functionally complete.** The §15 launch flow —
*browse → unlock → sign up → see tiers → reach MOQ → checkout → shipping/pickup → tracking* — works
end to end today with one break in it: **there is no payment processor**. Orders are placed as
*Pending Payment* and settled out of band. That is the only gap that blocks taking real money, and it
is blocked on a client decision plus a merchant account, not on development time. Everything else
outstanding is either a client-supplied material (§14) or a small finish-off item listed at the
bottom.

## Section-by-section

### §1 Business / Branding — ✅ complete, pending assets

| Item | State |
|---|---|
| Name, concept, two-audience storefront | ✅ |
| Faire-style visual direction; warm off-white, generous whitespace, neutral borders | ✅ design tokens in `app/assets/css/main.css` |
| No large dark background fills; black reserved for text/icons/borders/small buttons | ✅ enforced in `BaseButton.vue` |
| Homepage emphasises WOMEN and MEN | ✅ split hero, `pages/index.vue` |
| Mobile-first, clean navigation | ✅ |
| Logo | ⏳ client-supplied (§14). `BrandMark.vue` is a wordmark placeholder |
| Business email, phone, social links | ⏳ fields are editable in **Store settings → Store details**; values not yet supplied |

### §2 Products — ✅ complete

Every field §2 lists is on the product page: name, SKU, multiple photos, retail price, wholesale tier
pricing when signed in, colours, sizes, stock availability, quantity selector, description, materials,
dimensions & fit, size chart. Description / Materials / Dimensions & Fit render as the **collapsible
+/− panels** the brief asks for (`UiBaseAccordion`).

Variants track inventory **per size/colour combination**. An administrator can adjust stock by hand
with a reason and a note, and every movement is written to an audit trail (`inventory_movements`).
Stock decrements automatically when an order is marked paid, and out-of-stock variants are visibly
present but unselectable — so a shopper learns the colour exists and is unavailable, rather than
wondering whether it is offered at all.

Prices and tier thresholds are editable from the admin with no code change. **Placeholder tiers are
seeded**; the final three thresholds and prices are still to be supplied (§14).

### §3 Wholesale pricing access — ✅ complete

The updated Faire-style model is fully implemented, including the reversal of the old reseller
concept: **no application, no manual approval**. Wholesale prices are omitted server-side for
logged-out visitors, so they are not merely hidden with CSS — there is no wholesale figure in the
page source to read. The cart determines and applies the correct tier automatically, and a customer
below the threshold still checks out at retail. Detail in
[07-signin-and-accounts.md](07-signin-and-accounts.md).

### §4 Orders & Checkout — ⚠️ **the one real gap**

| Item | State |
|---|---|
| CAD as primary currency | ✅ |
| Contact info, shipping/billing address, ship-or-pickup, Canadian taxes, shipping charge, wholesale discount, subtotal and total, order confirmation | ✅ all present at checkout |
| **Visa / Mastercard / PayPal / wallet payment** | ❌ **not implemented** |
| Interac e-Transfer / manual payment | ✅ order is placed as *Pending Payment* with instructions from admin settings |
| BNPL not required at launch, architecture allows it later | ✅ the `payments` table is provider-agnostic (`stripe` / `etransfer` / `manual`) and stores only a provider reference plus last four digits |

**What "not implemented" means concretely.** There is no payment SDK in `composer.json`, no
payment controller, no webhook route, and no card form in the storefront. Checkout says so plainly
rather than pretending. The schema, the refund flow, the admin "Mark paid" action and the payment
notification are all built and waiting — what is missing is the integration itself.

**Why it is not built.** It is blocked on two client decisions, both flagged as open since
2026-09-02: which processor, and a merchant account. Recommendation remains **Stripe** — one
integration covers Visa/Mastercard/Amex, Apple Pay, Google Pay and PayPal, with BNPL available later
without re-architecting. Estimated **1.5–2 weeks** once the account exists.

### §5 Shipping / Pickup — ✅ complete, one optional item deferred

| Item | State |
|---|---|
| Automatic rate calculation at checkout by destination | ✅ |
| Postal code, weight, dimensions and service used to rate | ✅ (`weights_complete` flags an order whose products lack weights) |
| Stallion Express integration | ⚠️ **rate lookup built but never validated against the live API** — no account or key supplied |
| Table-rate fallback so shipping never blocks launch | ✅ admin-managed zones and rates |
| Passing shipment/order data *to* Stallion to reduce manual entry | ❌ not built — rating only, no label purchase |
| Tracking attachable to the order | ✅ carrier, number and link, entered when marking shipped |
| Tracking included in shipping notifications | ✅ verified — the email falls back to the order's latest shipment |
| Free-shipping threshold editable | ✅ currently $600 |
| Local pickup with post-order instructions | ✅ |

Label creation is the "if supported" half of §5. It cannot even be designed without live credentials,
since Stallion's create-shipment payload has to be read from their API rather than guessed.

### §6 Canadian taxes — ✅ complete, one small gap

GST/HST is seeded **active for every province** at the correct rate, applied to goods and to freight,
and never compounded. Tax is shown as separate lines at checkout and on the order confirmation.

Two things to note:

- **PST (BC/SK/MB) and QST (QC) are seeded but switched off.** That is deliberate: charging
  provincial tax the business is not registered to collect is worse than not charging it. The client
  needs to confirm their registration status, then it is a toggle in the admin.
- ✅ **The business tax registration number now prints on the receipt** — on the order confirmation
  page and in the confirmation email. It is **frozen onto the order at placement**, not read live at
  render time, for the same reason the addresses and unit prices are: a February receipt must not
  grow a number the business only obtained in March. An order placed while the business held no
  number shows none, permanently.

### §7 Order process & statuses — ✅ complete

All eight preferred statuses exist exactly as named — Pending Payment, Paid, Processing, Ready for
Pickup, Shipped, Completed, Cancelled, Refunded. Status is *derived* from payment and fulfilment
state rather than set by hand, so the two can never disagree. The full customer/admin flow §7
describes is implemented, including inventory updating and the admin receiving the order.

### §8 Customer account — ✅ complete

Sign in/out, password reset, Google sign-in, order history, order status, tracking, saved and
editable account and delivery information, and wholesale pricing while signed in. Apple sign-in was
dropped on 2026-09-04 — the costing behind that call is in
[07-signin-and-accounts.md](07-signin-and-accounts.md).

### §9 Admin dashboard — ✅ complete, all fifteen items

Products, photos, categories, colours, sizes, SKUs and per-variant inventory, retail prices,
wholesale tiers and MOQ thresholds, free-shipping and shipping settings, customer accounts,
suspend/disable/modify, orders, order statuses, tracking, cancellations and refunds, basic sales
reporting (dashboard widgets), and CSV export of orders, customers and products.

Routine operations need no developer, which is what §9 actually asks for.

### §10 Notifications — ✅ built, ⏸️ switched off

All six are implemented: account verification, password reset, order confirmation, payment
confirmation, order-status updates, shipping/tracking, plus the administrator new-order alert.

**Nothing sends yet.** Every notification sits behind the "Send order emails" switch in Store
settings, and `MAIL_MAILER` still needs pointing at a real service (Postmark or Resend recommended).
This is deliberate — a half-configured mailer that throws mid-checkout is worse than silence — but it
does mean password reset is currently unusable, and the storefront says so rather than promising an
email that will not arrive.

### §11 Website content / pages — ✅ all fifteen present

Home, Women's, Men's, product pages, cart, checkout, login/create account, account/orders, About Us,
Contact, Shipping/Pickup info, Returns/Refund policy, Wholesale/How It Works, Privacy Policy, Terms &
Conditions. The four policy pages render from admin-editable settings and **honestly say a policy is
pending** rather than displaying invented legal text. Final wording is outstanding (§14) and gates
launch.

### §12 Technical / ownership — ✅ mostly, one deployment item

| Item | State |
|---|---|
| Mobile-responsive | ✅ |
| HTTPS/SSL | ⏳ deployment-time; specified in [02-architecture.md](02-architecture.md) |
| Card data never stored | ✅ schema holds only a provider reference and last four digits (PCI SAQ-A) |
| **Backup process** | ✅ `backup:run`, scheduled nightly, with a **weekly `--verify` pass that actually restores the dump** into a scratch database and counts the tables. Needs `BACKUP_DISK` set to a Canadian bucket — see below |
| Client owns domain, hosting, data, source, payment and third-party accounts | ✅ by design; handover documented |
| Structured for future additions without rebuilding | ✅ |

Two things about the backup that are deployment steps rather than code, and both matter more than the
command itself:

1. **`BACKUP_DISK` must be set.** Until it is, dumps live on the same disk as the database they back
   up, which protects against a bad migration and nothing else. The command warns on every run while
   it is unset. Point it at an S3-compatible bucket in a Canadian region.
2. **Laravel's cron entry must exist on the VPS** —
   `* * * * * cd /var/www/bulkscrubs/api && php artisan schedule:run` — or nothing is scheduled at
   all. Failures are emailed rather than sent to `/dev/null`, because a backup that stops running
   silently is the entire failure mode worth guarding against.

### §13 Future expansion — correctly not built

Nothing here is required at launch and nothing has been built speculatively, with one exception worth
naming: **custom pricing for very large wholesale accounts** has a seam in place
(`customer_price_lists`, resolved by `PricingService` ahead of the global tiers), because retrofitting
it later would have meant reopening the pricing engine — the one part of the system where a wrong
assumption is expensive.

### §14 Materials still to be supplied — ⏳ 14 items outstanding

Unchanged and still the largest schedule risk. The launch-blocking ones are the **policy wording**
(also blocks the Google consent screen), the **final three wholesale tiers**, and the **product
catalogue with photos, SKUs, colours, sizes and opening inventory**.

### §15 Version 1 priorities — ✅ except payment

`Browse → Unlock → Signup/Sign in → See tiers → Reach MOQ → Checkout → **Payment** → Shipping/Pickup
→ Tracking`. Every arrow works today except the bolded one.

## Feasible and not yet done

Ranked by what actually holds up launch.

| # | Gap | Section | Blocked on | Effort |
|---|---|---|---|---|
| 1 | **Payment processing** | §4 | Client: processor choice + merchant account | 1.5–2 weeks |
| 2 | Switch on transactional email (mailer + toggle) | §10 | Client: mail service account | ~half a day |
| 3 | Confirm PST/QST registration, then activate those rates | §6 | Client: registration status | minutes once known |
| 4 | Set `BACKUP_DISK` and add the scheduler cron entry | §12 | Deployment: object-storage bucket | ~1 hour at deploy |
| 5 | Stallion live-rate validation | §5 | Client: account + API key | ~2 days once keyed |
| 6 | Stallion label creation / shipment push | §5 | Same key; "if supported" in the brief | ~3 days, optional |

**Nothing on this list is now blocked on development work alone.** The two items that were —
printing the tax registration number, and the backup process — were completed on 2026-09-03 and are
covered by tests. Everything remaining waits on the client, a third party, or the production deploy.
