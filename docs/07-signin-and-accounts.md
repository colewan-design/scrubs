# 07 — Sign-in and customer accounts

Covers §1 of the materials request: signup, login, social sign-in, account self-service, and the
admin's control over a customer account. Written as the answer to each of the nine questions on that
checklist, with what was built and what is still waiting on the client.

## Answers to §1, in order

| # | Question | Answer |
|---|---|---|
| 1 | Browse without an account, prompted at "Unlock wholesale pricing"? | **Yes.** The whole catalogue is public; only the wholesale *prices* are gated. Every lock opens one shared dialog. |
| 2 | Normal email/password signup? | **Yes.** Four required fields — name, email, phone, password. |
| 3 | "Continue with Google" using Google's official authentication? | **Yes**, built on Laravel Socialite. Needs one client-supplied OAuth client ID and secret to switch on. |
| 4 | "Continue with Apple" too? | **No — dropped on 2026-09-04.** A paid Apple Developer account and a client secret that has to be regenerated twice a year, for a second button. The costing is [below](#apple-sign-in--why-it-was-dropped). |
| 5 | Auto-create the account on first Google login? | **Yes**, and it links to an existing password account with the same address rather than creating a second one. |
| 6 | Wholesale pricing and the three tiers without manual approval? | **Yes.** Signing in reveals wholesale prices; the tier itself is decided by order size. There is no approval workflow to wait on. |
| 7 | Order history, saved addresses, contact information, account status? | **Yes** — all four, self-service at `/account`. |
| 8 | Admin able to suspend, disable, or modify an account? | **Yes**, in Filament. Three distinct verbs — see [Admin](#what-the-admin-can-do). |
| 9 | Password reset and email verification? | **Both built.** Both are inert until the client switches order emails on (§10), and both say so rather than pretending to have sent something. |

## How the unlock gate works

Prices are hidden server-side, not with CSS. `ProductCardResource` and `ProductDetailResource` omit
the wholesale figures entirely for a logged-out request, so there is no wholesale price in the HTML
for anyone to read out of the page source. The lock is the honest state of the response.

The dialog itself asks for an email address and nothing else, then carries it to
`/account/register` so it is typed exactly once — matching the Faire flow the brief points at. A
shopper who would rather use Google never sees the form at all: the same dialog offers
"Sign up with Google".

## Social sign-in

`GET /api/v1/auth/providers` reports which providers are actually usable, and the storefront renders
only those buttons. Google is the only provider. It stays a list rather than one hardcoded button
because Google is equally invisible until credentials exist, and a provider is "usable" only when
it has credentials **and** a driver installed — without that second check, credentials for a
provider Socialite does not know would turn a graceful "not configured yet" into an exception
thrown mid-redirect.

**Account linking** matches on the provider's verified email. Google has already proven the customer
controls that address, so an existing password account with the same address is the same person.
Refusing to link would strand them with two accounts and one order history. A customer who later
changes their email on our side still lands on their own account, because the provider link is
checked before the address is.

An account created this way has **no password at all** (the column is nullable). `/account/profile`
offers such a customer "Set a password" rather than "Change password", and does not ask for a current
one that does not exist.

### Google — what the client needs to supply

1. A Google Cloud project with the OAuth consent screen configured (app name, support email, logo,
   privacy policy and terms URLs — the last two are blocked on §7's policy wording).
2. An **OAuth 2.0 Client ID** of type *Web application*.
3. This exact authorised redirect URI: `https://<domain>/api/v1/auth/google/callback`.

Then set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`. No deployment is needed — sign-in appears
once the values are present.

Verification by Google is only required if the consent screen requests sensitive scopes. This
integration requests `email` and `profile`, which are not sensitive, so it can go live without
review.

### Apple sign-in — why it was dropped

This was the "please advise" the brief asks for. **Decided 2026-09-04: not building it.** Apple
sign-in is buildable, but it is not free and it is not fire-and-forget:

| Requirement | Detail |
|---|---|
| **Apple Developer Program** | **US$99 per year**, ongoing. There is no free tier for Sign in with Apple. |
| **Extra identifiers** | An App ID, a Services ID, and a private key (`.p8`) created in the developer portal. |
| **Client secret is a signed JWT** | Not a static string. It is generated from the private key and **expires after at most six months**, so somebody has to regenerate it twice a year or sign-in silently breaks. |
| **Extra package** | Socialite does not ship an Apple driver; `socialiteproviders/apple` would have to be added. |
| **Private email relay** | Apple lets customers hide their real address behind a `@privaterelay.appleid.com` forwarder. Order emails still reach them, but the address in the admin is not one the client can phone-match against a customer. |
| **Name arrives once** | Apple returns the customer's name **only on the first authorisation**. Miss it and the account is nameless forever. |

**Outcome: Google only.** Google covers the overwhelming majority of Canadian desktop and Android
shoppers, costs nothing, and needs no maintenance. The `apple` provider, its config slot and its
button have been removed rather than left switched off, so there is no half-wired path to keep
working.

If iOS signup drop-off ever justifies revisiting it, `provider` is a plain string throughout — the
database, the API and the storefront's provider list all accept a new value without a migration —
so reinstating it is the driver package, a config slot and a button, not a rewrite.

## What the customer can do

All at `/account`, behind the `auth` route guard:

| Page | What it does |
|---|---|
| `/account` | Contact summary, account status, wholesale standing, recent orders, default address |
| `/account/orders` | Full order history with status and tracking |
| `/account/addresses` | Address book — add, edit, delete, choose defaults |
| `/account/profile` | Edit contact details; change or set a password |

**Saved addresses** prefill checkout. Editing one never rewrites an order that has already been
placed: checkout freezes its own copy onto the order (`order_addresses`), which is what keeps an
invoice from silently changing months later. The first address saved becomes the default
automatically, because an address book with no default prefills nothing.

## Email verification

Deliberately **soft**. The brief asks for a fast, low-friction signup and makes wholesale eligibility
a function of order size, so an unconfirmed address gates nothing — not the catalogue, not the cart,
not wholesale pricing. What it gates is trust in the address itself: the customer sees a prompt, and
the admin can see at a glance which addresses have been proven.

The link is a **signed API URL**, not a storefront one. The signature is HMAC'd with the application
key and carries a 24-hour expiry, so the customer can click it from their phone, from webmail, or
from a browser that has never held a session, and it still works. Changing the email address
invalidates any link still in flight for the old one, because the link's hash is derived from the
address it was issued for.

Accounts created through Google arrive already verified — the provider has done the proving.

## What the admin can do

Filament, under **Customers**. Three verbs that mean three different things:

| Verb | What happens | Reversible |
|---|---|---|
| **Suspend** | Sets status to suspended. Blocks sign-in, and kills the session the customer is *already holding* on their very next request. Orders and addresses untouched. | Yes — Reactivate |
| **Disable** | The soft delete. The account stops existing for the storefront, but its order history survives, which Canadian record-keeping requires. | Yes — the trashed filter |
| **Modify** | Edit contact details, role, status, custom price list, and the saved address book. | n/a |

Suspension being enforced on the *request* path rather than only at login is the part worth noting.
An administrator suspends a customer who is very likely signed in at that moment; checking only at
login would let that session keep shopping until it expired.

Support can also send a password reset link, resend an email confirmation, or mark an address
confirmed manually — for the case where it was verified over the phone instead.

An administrator cannot suspend themselves. That would lock the panel behind them.

## Open items for the client

| Item | Blocks | Urgency |
|---|---|---|
| Google OAuth client ID and secret | The Google button appearing at all | **High** — it is the launch-preferred provider |
| Consent screen privacy policy and terms URLs | Google consent screen setup | **High** — depends on §7 policy wording |
| Switching on "Send order emails" in Store settings | Password reset and email confirmation actually sending | **High** — reset is unusable until then |

Until email is switched on, `/auth/forgot-password` returns a 503 saying so and pointing the customer
at support, rather than claiming a link is on its way. That is a deliberate choice: a customer
waiting on an email that was never going to arrive is worse than being told to phone.
