# BulkScrubs Direct — Website Build Plan

**Client:** BulkScrubs Direct (Ontario, Canada)
**Prepared by:** Christian
**Source:** *BulkScrubs Direct — Updated Website Materials & Business Requirements*
**Date:** 2026-09-02
**Stack:** Nuxt 4 (Vue 3, SSR) · Laravel 12 (REST API) · MySQL 8

---

## Document set

| Doc | Purpose |
|---|---|
| [01-requirements-analysis.md](docs/01-requirements-analysis.md) | Requirement-by-requirement analysis, contradictions, gaps, open decisions |
| [02-architecture.md](docs/02-architecture.md) | System architecture, technology choices, third-party services, hosting |
| [03-data-model.md](docs/03-data-model.md) | MySQL schema, pricing engine, tax engine, inventory rules |
| [04-ui-design-system.md](docs/04-ui-design-system.md) | Design tokens and component specs derived from the Faire reference screenshots |
| [05-roadmap.md](docs/05-roadmap.md) | Phased delivery plan, estimates, dependencies, acceptance criteria |
| [06-open-questions.md](docs/06-open-questions.md) | Consolidated list of what the client must decide or supply, ranked by urgency |
| [07-signin-and-accounts.md](docs/07-signin-and-accounts.md) | §1 answered question by question: signup, Google sign-in, accounts, admin controls — including the costing that led to dropping Apple |
| [08-requirements-coverage.md](docs/08-requirements-coverage.md) | Audit of all 15 brief sections against the code as built, with the ranked list of what is feasible and still outstanding |
| [09-credentials-and-access-request.md](docs/09-credentials-and-access-request.md) | Every account, key and access grant needed from the client — how to generate each one, how to send it safely, and what it blocks. Rendered for the client as `client/BulkScrubs-Direct-Credentials-Request.docx` |

---

## Executive summary

The build is a **two-audience storefront**: retail shoppers and volume buyers, served by one catalogue
and one cart. The differentiator is a Faire-style *"Unlock wholesale pricing"* gate — wholesale prices
are hidden from logged-out visitors, but eligibility itself is decided by **order value / MOQ**, not by
a manual reseller approval. That single decision removes the entire approval-workflow subsystem from V1
and is the reason this scope is achievable.

**The critical path is the pricing engine.** Everything else (catalogue, checkout, shipping, admin) is
well-trodden e-commerce work. The tier logic is where the requirements are ambiguous, where the money is,
and where a wrong assumption is expensive to unwind. It is specified in detail in
[03-data-model.md](docs/03-data-model.md) and must be a single server-side service used by cart,
checkout, and order creation alike — the frontend must never compute a price.

### The four decisions that block work

1. **Tier basis** — are the three wholesale tiers triggered by cart *dollar value*, *unit quantity*, or either? The document uses "MOQ" and "order value" interchangeably. Schema and cart UX both depend on the answer.
2. **Guest wholesale** — if a logged-out visitor reaches $200, do they get wholesale pricing at checkout, or must they sign in first? Recommendation: require sign-in (it is the whole point of the unlock mechanic).
3. **Payment processor** — recommendation is **Stripe** (cards + Apple/Google Pay + PayPal + later BNPL through one integration). Needs client sign-off because it determines merchant account setup.
4. **Stallion Express account + API key** — cannot validate the integration without live credentials. A table-rate shipping fallback is being built regardless so this never blocks launch.

### Decisions locked

| Decision | Status | Recorded |
|---|---|---|
| Stack: Nuxt + Vue + Laravel + MySQL | **Confirmed** | 2026-09-02 |
| Hosting: single VPS, Canadian region | **Confirmed** | 2026-09-02 |
| Admin dashboard built on **Filament**, not a custom Nuxt SPA | **Confirmed** | 2026-09-02 |
| Social sign-in: **Google only — Apple Sign In dropped** | **Confirmed** | 2026-09-04 |

Filament runs inside the same Laravel application, against the same models and the same
`PricingService` as the storefront, so the admin and the shop can never disagree about a price.
It delivers §9's fifteen management areas as configured resources rather than hand-built screens —
roughly 4–6 weeks saved. The customer-facing storefront remains 100% Nuxt. Handover is a documented
`/admin` login, satisfying §12.

### Recommendations still open

- **Treat Interac e-Transfer as an offline payment method,** not an integration. There is no practical e-Transfer API at this business size. Implement it as an order that sits in *Pending Payment* until an administrator marks it paid.
- **Plan for AODA/WCAG 2.0 AA from the start.** The client is an Ontario business; accessibility is cheap to build in and expensive to retrofit.

### Indicative timeline

**~14 working weeks** to launch across 8 phases, assuming the client supplies catalogue data, logo,
policy wording, and payment/shipping account access on the schedule in
[05-roadmap.md](docs/05-roadmap.md). Client-supplied material is the single largest schedule risk —
Section 14 of the brief lists fourteen outstanding items, several of which gate launch.

### Visual direction

The two Faire screenshots are the reference. What they actually establish, distilled:
warm off-white grounds, near-black type, a **serif display face paired with a clean sans for body**,
generous whitespace, hairline neutral borders instead of shadows, pill-shaped controls, and product
photography carrying all of the colour. Full token set and component specs in
[04-ui-design-system.md](docs/04-ui-design-system.md).
