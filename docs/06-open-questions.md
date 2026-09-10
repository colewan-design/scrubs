# 06 — Open Questions for BulkScrubs Direct

Everything that needs a client decision, ordered by urgency. Q1–Q4 should be answered before development
starts; the rest have later deadlines but all block launch.

Each carries a **recommended default** — if no answer arrives by the deadline, we proceed on the default
and record the assumption, rather than stalling the build.

---

## Blocking — needed in Phase 0

### Q1. What exactly is a "scrub set"? 🔴
The brief prices "~$65 per scrub set", but never defines the product structure. Three possibilities,
each with a different schema:

- **(a)** A set is one product; the customer picks one size that applies to both pieces.
- **(b)** A set is one product, but the customer picks **top size and bottom size independently**
  (common in scrubs retail — sizing frequently differs between pieces).
- **(c)** Tops and bottoms are sold separately *and* bundled as sets.

**Why it matters:** option (b) makes the variant key `(colour, top size, bottom size)`, adds a second
size column, and changes the product-page size selector. Retrofitting it after the catalogue is built
means migrating every variant and SKU.

**Recommended default: (b)** — it is the most common arrangement in this category and can represent (a)
by simply offering matched sizes. **Blocks Phase 2.**

### Q2. Does a *guest* who reaches the MOQ get wholesale pricing? 🔴
§3 says wholesale prices are hidden from logged-out visitors, but also that MOQ alone determines
eligibility. Read literally, a guest over $200 has qualified — which makes the account gate cosmetic.

**Recommended default: no.** An account is required for wholesale pricing to be *applied*. A qualifying
guest cart shows *"You've qualified for wholesale — sign in to save $X"*, applied instantly on login.
This is how Faire works and it makes signup worth something at the moment of highest intent.
**Blocks Phase 3.**

### Q3. Are tiers triggered by dollar value, unit quantity, or either? 🔴
"MOQ" and "qualifying order value" are used interchangeably throughout §3. They behave differently:
$200-spend lets four expensive sets qualify while five cheap ones do not; a 10-unit MOQ is predictable
and price-independent.

**Recommended default: either.** Both columns exist on every tier, evaluated with OR, so the client can
change their mind in settings rather than in a migration. We still need the actual thresholds by Phase 3.
**Blocks Phase 3 configuration.**

### Q4. Payment processor? 🔴
Not named in the brief, and merchant-account approval has real lead time.

**Recommended default: Stripe** — one integration covers Visa/Mastercard, Apple Pay and Google Pay (§4's
"debit/wallet options"), PayPal in CAD, and later BNPL (§4, §13) as a dashboard toggle. Card data never
touches our servers (§12). Canadian alternatives — Moneris, Helcim — may offer better CAD interchange
but weaker tooling and no single-integration BNPL path.

Also needed: **is Interac e-Transfer wanted at launch?** There is no practical API; it would be an
offline flow where an order waits in *Pending Payment* until an admin confirms funds. If yes, we need
the e-Transfer address and how long an unpaid order should be held before auto-cancelling.
**Blocks Phase 4.**

---

## Needed by Phase 2–3

### Q5. Is guest checkout allowed?
Not addressed anywhere in the brief.
**Recommended default:** yes for retail-only carts (less friction on small orders), account required for
wholesale pricing — which reinforces the unlock mechanic rather than undermining it.

### Q6. Which provinces are you registered to collect tax in? ⚠️
**This one needs the client's accountant, not us.** GST/HST is one question; PST in British Columbia,
Saskatchewan and Manitoba, and QST in Quebec, each carry separate registration obligations that depend
on the client's sales volume and nexus. We build the table and the toggles; only the client can say
which are switched on. Also needed: **the GST/HST registration number** for printing on invoices (§6).

**Recommended default:** GST/HST collected on all destinations, Ontario HST at 13%, PST/QST off until
the client confirms registration.

### Q7. How is wholesale pricing expressed — percentage or fixed price?
"$65 retail / $45 wholesale" reads as an absolute price, but hand-entering three tier prices for every
product is significant admin work as the catalogue grows.
**Recommended default:** a catalogue-wide percentage per tier, with per-product overrides for exceptions.
Both are supported; this is about which the admin uses day to day.

### Q8. Size chart — one, per category, or per product?
**Recommended default:** per category with a per-product override.

### Q9. Any brand assets beyond the logo?
Fonts, brand guidelines, an existing colour palette, or photography direction. If none exist, the
[design system](04-ui-design-system.md) proposal stands as the brand definition — worth the client
knowing that, since it means those choices become theirs by default.

---

## Needed by Phase 4–5

### Q10. Stallion Express — account, credentials, and service selection
API key required to build against. Also: which Stallion services should be offered at checkout, and
should the customer choose between speeds or be shown one rate?
**Recommended default:** show the cheapest qualifying service plus one expedited option.

### Q11. Per-variant weights and dimensions ⚠️
Live rate calculation is impossible without them, and they are **not** in §14's list of materials to be
supplied. If unavailable, we use a per-product average and accept some rate inaccuracy — worth the
client understanding that tradeoff, since inaccurate rates cost real money on every order.

### Q12. Local pickup details
Address, hours, lead time before an order is ready, and whether pickup should be limited by order size
or region.

### Q13. Free shipping — retail only, or wholesale too?
$600 is above the $200 wholesale MOQ, so wholesale customers will hit it routinely.
**Recommended default:** applies to everyone; threshold editable in admin (§5).

### Q14. Notification recipients
Which address receives admin new-order alerts, and should there be separate addresses for orders versus
contact-form submissions?

---

## Needed by Phase 6–7

### Q15. Policy wording 🔴
Returns/refunds, shipping, privacy, and terms. **We build the pages; the client's lawyer supplies the
words.** A Canadian storefront should not take payments without them — this is a genuine launch blocker,
not a formality.

### Q16. What does "basic sales reporting" need to cover?
**Recommended default:** revenue by period, order count, average order value, retail-vs-wholesale split,
top products by units and revenue, low-stock report. Confirm whether anything else is expected at launch.

### Q17. Returns handling in V1
The brief mentions a returns *policy* page but no returns *process*.
**Recommended default:** no self-serve returns in V1. Customers contact support; admin records refunds
and restocks manually. A customer-facing RMA flow is a V2 candidate.

### Q18. Domain and hosting ownership
Is the domain already registered, and in whose name? §12 requires the client to own it. Confirming early
avoids an awkward transfer at handover.

### Q19. Launch date or event to work toward?
Not stated. If there is a trade show, seasonal window, or marketing commitment driving the date, it
changes how we sequence Phases 6–7 and what could be deferred to a fast-follow release.
