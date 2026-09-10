# 02 — Architecture

## Shape of the system

A **decoupled storefront**: Nuxt renders the customer experience, Laravel owns all business logic and
data, MySQL is the system of record. Both applications live on **one VPS** behind a single Nginx
instance, which keeps the deployment simple, keeps everything on one domain (avoiding cross-site cookie
problems), and gives the client the outright ownership §12 requires.

```
                         ┌──────────────────────────────┐
                         │        Cloudflare DNS        │
                         │    (proxy, TLS, WAF, cache)  │
                         └──────────────┬───────────────┘
                                        │
┌───────────────────────────────────────▼────────────────────────────────────────┐
│  VPS (Ubuntu 24.04 LTS · Canadian region)                                       │
│                                                                                 │
│   ┌─────────────────────────── Nginx ───────────────────────────┐              │
│   │  bulkscrubsdirect.com/*        → Nuxt (Nitro, Node :3000)   │              │
│   │  bulkscrubsdirect.com/api/*    → Laravel (PHP-FPM)          │              │
│   │  bulkscrubsdirect.com/admin/*  → Laravel Filament           │              │
│   └──────────────┬────────────────────────────┬─────────────────┘              │
│                  │                            │                                │
│      ┌───────────▼──────────┐     ┌───────────▼────────────┐                   │
│      │  Nuxt 4 · Vue 3 SSR  │     │  Laravel 12  (PHP 8.3) │                   │
│      │  Nitro node-server   │────▶│  REST API + Filament   │                   │
│      │  Pinia · Tailwind    │ HTTP│  Sanctum · Socialite   │                   │
│      └──────────────────────┘     └───────────┬────────────┘                   │
│                                               │                                │
│              ┌────────────────┬───────────────┼──────────────┐                 │
│              ▼                ▼               ▼              ▼                 │
│        ┌──────────┐    ┌──────────┐   ┌─────────────┐  ┌──────────┐            │
│        │ MySQL 8  │    │  Redis   │   │  Horizon    │  │  Cron    │            │
│        │  (data)  │    │cache/queue│   │ queue workers│  │scheduler│            │
│        └──────────┘    └──────────┘   └─────────────┘  └──────────┘            │
└─────────────────────────────────────────────────────────────────────────────────┘
                                        │
              ┌─────────────┬───────────┼────────────┬──────────────┐
              ▼             ▼           ▼            ▼              ▼
         ┌────────┐   ┌──────────┐ ┌─────────┐ ┌──────────┐  ┌───────────┐
         │ Stripe │   │ Stallion │ │Postmark │ │ Object   │  │  Backups  │
         │payments│   │ shipping │ │  email  │ │ storage  │  │ off-site  │
         └────────┘   └──────────┘ └─────────┘ └──────────┘  └───────────┘
```

**Why one domain and not `api.` on a subdomain:** Sanctum's cookie-based SPA authentication is
dramatically simpler when the frontend and API are same-origin. Serving Laravel under `/api` on the
same host means first-party cookies, no CORS configuration, no cross-site cookie restrictions to fight
in Safari, and no token storage in the browser. It is both simpler and more secure.

---

## Frontend — Nuxt 4 / Vue 3

**Rendering:** SSR via the Nitro `node-server` preset, running under a process manager on the VPS.

SSR is not optional here. Category and product pages have to be indexable — a wholesale scrub supplier
lives on organic search for terms like "bulk scrubs Canada". Client-side rendering would also hurt
first-paint on the image-heavy pages the Faire-inspired design calls for.

**Route rendering strategy:**

| Route | Mode | Reason |
|---|---|---|
| `/`, `/women`, `/men`, `/products/:slug` | SSR + cached | SEO-critical, high traffic, mostly static content |
| `/about`, `/contact`, policy pages | Prerendered | Content changes rarely |
| `/cart`, `/checkout`, `/account/**` | SSR, no cache | Per-user and price-sensitive |

**Key libraries**

| Concern | Choice | Rationale |
|---|---|---|
| Styling | Tailwind CSS v4 | Token-driven; maps directly onto the design system in [04](04-ui-design-system.md) |
| State | Pinia | Cart, session, and UI state |
| Data fetching | `useFetch` / `$fetch` with a typed API client | SSR-aware, forwards cookies correctly |
| Images | `@nuxt/image` | Responsive `srcset`, AVIF/WebP, lazy loading — the design is photography-heavy |
| Forms | VeeValidate + Zod | Schema shared conceptually with Laravel FormRequests |
| SEO | `@nuxtjs/seo` | Meta, canonicals, sitemap, robots, JSON-LD |
| Icons | Lucide | Thin, consistent line icons matching the reference |

**A hard rule: the frontend never calculates money.** Not subtotals, not tier discounts, not tax, not
shipping, not the free-shipping gap. Every figure shown is a value the API returned. This is the single
most effective defence against the storefront and the order disagreeing about what a customer owes.

---

## Backend — Laravel 12

**Two faces on one application:**
1. A **REST API** under `/api/v1` consumed by Nuxt.
2. A **Filament admin panel** at `/admin` for staff.

Both use the same Eloquent models, the same `PricingService`, the same `TaxService`. There is exactly
one implementation of every business rule.

**Layering**

```
routes/api.php
  └── Http/Controllers/Api/*        thin — validate, delegate, return a Resource
        └── Http/Requests/*         validation
        └── Services/*              all business logic lives here
              ├── PricingService        tiers, discounts, cart totals   ← critical path
              ├── TaxService            Canadian destination tax
              ├── ShippingService       provider interface + resolution
              ├── InventoryService      reservations, decrements, movements
              ├── CheckoutService       orchestrates order creation
              ├── PaymentService        gateway interface
              └── OrderService          status transitions, history
        └── Models/*                Eloquent
        └── Http/Resources/*        API response shaping
  Jobs/*        queued work — emails, label creation, webhook processing
  Events/*      OrderPaid, OrderShipped, StockLow …
  Listeners/*   notification dispatch, inventory decrement
```

**Interfaces for every third party.** `PaymentGateway`, `ShippingProvider`, and `TaxCalculator` are
contracts with concrete implementations bound in a service provider. Three benefits: Stripe can be
swapped for Moneris without touching checkout, Stallion can fall back to table rates at runtime, and
the whole checkout flow is testable without network access.

**Authentication**

| Mechanism | Use |
|---|---|
| Sanctum (cookie/SPA) | Customer sessions from Nuxt — first-party cookies, CSRF-protected |
| Socialite | Google OAuth. Apple dropped 2026-09-04; the provider list stays data-driven |
| Filament auth | Separate admin guard, distinct session, 2FA enabled |
| Signed URLs | Email verification, password reset, guest order lookup |

Customers and admins are separate guards. An admin session must never be reachable from a customer
session, and admin routes get IP-agnostic 2FA rather than relying on password strength.

---

## Data layer — MySQL 8

Single MySQL 8 instance, InnoDB, `utf8mb4_0900_ai_ci`. Full schema in
[03-data-model.md](03-data-model.md).

**Money is stored as `BIGINT` cents. Never floats.** All currency fields are `_cents` suffixed so a
float can never be introduced by accident. Formatting happens at the presentation edge only.

**Order records are immutable snapshots.** An `order_items` row copies the product name, SKU, unit
price, tier applied, and discount at the moment of purchase. When the admin later edits a price or
renames a product, historical orders and invoices must not silently change. This is a correctness
requirement, not an optimisation.

Redis handles cache, sessions, and queues. If the client would rather run one less service, Laravel's
database queue driver is an acceptable downgrade at this volume — but Redis is recommended and costs
nothing on a VPS.

---

## Admin dashboard — Filament v4

Delivering §9 as Filament resources rather than hand-built screens:

| §9 requirement | Filament resource |
|---|---|
| Products, photos, descriptions | `ProductResource` + variant relation manager + media library |
| Categories | `CategoryResource` (nested) |
| Colours, sizes | `ColorResource`, `SizeResource` |
| SKUs and inventory by variant | Variant relation manager + inline stock adjustment action |
| Retail prices | Product/variant form fields |
| Wholesale tiers / MOQ | `PricingTierResource` + per-product override manager |
| Free shipping, shipping settings | Settings page + `ShippingRateResource` |
| Customers, suspend/modify | `CustomerResource` with a status action |
| Orders, statuses, tracking | `OrderResource` with status and tracking actions |
| Cancellations / refunds | Refund action calling `PaymentService` |
| Reporting | Filament widgets — revenue, AOV, retail/wholesale split, top products, low stock |
| Exports | Built-in CSV/XLSX export actions |

Every price change made in Filament flows through the same models the storefront reads, so the two can
never disagree.

---

## Third-party services

| Service | Purpose | Notes |
|---|---|---|
| **Stripe** | Cards, Apple/Google Pay, PayPal, later BNPL | Recommended; pending client sign-off. Payment Intents + webhooks. Card data never touches our server |
| **Stallion Express** | Live rates, labels, tracking | Behind `ShippingProvider`; table rates as fallback. Blocked on credentials |
| **Postmark** *(or Resend)* | Transactional email | Needs DNS access for SPF/DKIM/DMARC — start early, propagation takes time |
| **Cloudflare** | DNS, TLS, WAF, CDN, image caching | Free tier is sufficient |
| **Object storage** (R2 / Spaces / S3) | Product images, invoices, backups | Keeps media off the VPS disk and survives a server rebuild |
| **Sentry** | Error tracking, both apps | Strongly recommended before launch |
| **Uptime monitoring** | Availability alerting | Mitigates R9 (single-VPS risk) |

**All accounts are created in BulkScrubs Direct's name with the client as owner**, and developer access
added as a collaborator — not created by us and transferred at handover. §12 requires the client to
control these; doing it in this order means it is true from day one rather than a task that can be
forgotten during handover.

---

## VPS specification and deployment

**Recommended machine:** 4 vCPU · 8 GB RAM · 100 GB NVMe, Ubuntu 24.04 LTS, in a **Canadian region**
(Toronto/Montreal are widely available). Canadian residency keeps customer data in-country, which is a
straightforward answer to any PIPEDA question and reduces latency for the launch market.
2 vCPU / 4 GB would run the site, but leaves nothing for build headroom, queue workers, and the traffic
spikes a promotion produces.

**Stack on the box**

| Component | Role |
|---|---|
| Nginx | TLS termination, static assets, reverse proxy to Nuxt and PHP-FPM |
| PHP 8.3-FPM + OPcache/JIT | Laravel |
| Node 22 + PM2 (or systemd) | Nuxt Nitro server, restart-on-boot |
| MySQL 8 | Database |
| Redis 7 | Cache, sessions, queues |
| Supervisor / Horizon | Queue workers |
| Cron | Laravel scheduler, backups, reservation cleanup |
| Certbot | Let's Encrypt TLS, auto-renewal |
| UFW + Fail2ban | Firewall, SSH brute-force protection |

**Provisioning:** **Laravel Forge** (or Ploi) is recommended over hand-configuration. It provisions the
whole stack, manages deployments, certificates, queue workers, and scheduled jobs, and — importantly for
§12 — it manages *the client's own server*, so there is no lock-in. If the client would rather avoid the
subscription, the same setup is scripted and documented instead; it is a cost/convenience tradeoff, not
an architectural one.

**Deployment flow**

```
git push main
  → GitHub Actions: PHPUnit/Pest, Vitest, ESLint, PHPStan
  → on green, deploy to VPS:
      composer install --no-dev -o
      php artisan migrate --force
      php artisan config:cache route:cache view:cache
      npm ci && npm run build          (Nuxt)
      pm2 reload bulkscrubs-web        (zero-downtime)
      php artisan queue:restart
      php artisan up
```

Staging and production are separate environments — either two VPS instances or, to control cost, a
staging site on the same box under a subdomain with its own database. Staging exists so the client can
do UAT and so migrations are never first run against live data.

**Environments:** `local` (developer machines) → `staging` (client UAT, Stripe test mode, noindex) →
`production`.

---

## Security

- HTTPS everywhere, HSTS, TLS 1.2+ only, auto-renewing certificates (§12).
- **No card data ever reaches our server or database** — Stripe Elements tokenises in the browser; we
  store only a payment-intent reference and the last four digits (§12, §4).
- Sanctum first-party cookies: `HttpOnly`, `Secure`, `SameSite=Lax`; CSRF on all mutating requests.
- Rate limiting on auth, password reset, checkout, and webhooks; captcha on registration.
- All webhooks signature-verified (Stripe) and idempotent — a replayed webhook must never double-decrement
  inventory or double-send an email.
- Filament admin behind 2FA, separate guard, restricted roles.
- Server-side authorisation via Policies on every order and account endpoint — a customer must never be
  able to read another customer's order by changing an id.
- Secrets in `.env` only, never committed; rotated at handover.
- Dependabot/`composer audit` in CI.
- Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy.

## Backups and recovery (§12)

- **Nightly** automated `mysqldump`, encrypted, pushed **off-server** to object storage; 30-day retention.
- **Media** synced to object storage continuously — it is the storage of record, not the VPS disk.
- **Weekly full server snapshot** via the VPS provider.
- **A restore drill during Phase 7, with the result documented.** An untested backup is not a backup;
  this is the difference between a checkbox and an actual recovery capability.
- Runbook covering: restore database, rebuild server from scratch, rotate credentials.

## Performance targets

| Metric | Target |
|---|---|
| Largest Contentful Paint (mobile, product page) | < 2.5 s |
| Cumulative Layout Shift | < 0.1 |
| Interaction to Next Paint | < 200 ms |
| API p95 (catalogue reads) | < 200 ms |
| Lighthouse Performance / Accessibility (mobile) | ≥ 90 / ≥ 95 |

Achieved through SSR with route caching, Cloudflare CDN, AVIF/WebP responsive images, HTTP caching on
catalogue endpoints, Redis object caching, and disciplined database indexing. The design is image-heavy
by intent — image strategy is the main performance lever, which is why `@nuxt/image` and CDN delivery
are non-negotiable rather than nice-to-have.
