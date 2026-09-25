# 09 — Accounts, Credentials & Access Request

Everything the website needs from BulkScrubs Direct in the way of accounts, keys and access.

| Field | Detail |
|---|---|
| Prepared for | BulkScrubs Direct (Ontario, Canada) |
| Prepared by | Christian — Website Development |
| Date | 7 September 2026 |
| Companion to | [BulkScrubs Direct — Website Materials Request](../client/BulkScrubs-Direct-Materials-Request.docx), Section 8 |

Section 8 of the Materials Request said which accounts to open. This says what to send back from each
one. Most items take five to ten minutes. Send them as they come — no need to wait for a full set.

---

## How to send these safely

Please don't email credentials as plain text. Paste each value at `onetimesecret.com` and send the
link — it self-destructs after one view. A password manager share works equally well.

**I will never need** your password for any account, your banking details, or sole ownership of
anything. Every account is opened in the BulkScrubs Direct name with me added as a collaborator, so
nothing has to be transferred back to you at handover.

---

## 1. Summary

| # | Item | When | If it is late |
|---|---|---|---|
| 2 | Google app password — order emails | Week 1–2 | **Launch blocker** — no emails can send |
| 3 | Google OAuth client ID + secret — sign-in | Week 3 | Not blocking — button stays hidden |
| 4 | ~~Payment gateway keys~~ — PayPal received 2026-09-25, webhook ID received 2026-09-25 | Done | Rotate the secret |
| 5 | Domain / DNS access | Week 1 | **Launch blocker** — email lands in spam |
| 6 | Hosting account | Week 2 | **Launch blocker** — nothing can be deployed |
| 7 | Stallion Express API key | Week 5 | Not blocking — falls back to fixed rates |
| 8 | Backup storage bucket | Week 10 | Backups sit only on the server they protect |
| 9 | Business and tax values | Week 8–10 | Wrong tax, wrong invoices |

---

## 2. Google app password — so the website can send order emails

Order confirmations, shipping notices and password resets are all built. They cannot leave the server
without this.

**2-Step Verification must be on first** — app passwords do not appear as an option until it is. This
is the usual reason the menu item "isn't there".

1. `myaccount.google.com` → **Security** → turn on **2-Step Verification**.
2. Go to `myaccount.google.com/apppasswords`.
3. Name it **BulkScrubs Website** → **Create**.
4. Copy the 16-character password Google shows. It is shown once only.

**Send back:** the sending email address, the 16-character password, and whether the account is free
Gmail or Google Workspace.

### One decision attached to this

Free Gmail cannot authenticate mail for `bulkscrubsdirect.ca`, so order confirmations from that domain
will fail spam checks and a real share of them will land in junk folders. Free Gmail also rewrites the
sender address unless it is a verified alias, and caps out around 500 messages a day.

**Recommended:** Google Workspace (~$9 CAD per user per month) if you want everything in one place, or
Postmark (~$15 USD/month) if you would rather have delivery and bounce reporting.

☐ Google Workspace ☐ Postmark ☐ Free Gmail — understood, with the above

---

## 3. Google sign-in — the "Continue with Google" button

The only social sign-in provider; Apple was dropped on 4 September. The button stays hidden until
these exist, so nothing breaks in the meantime.

1. `console.cloud.google.com` → create a project named **BulkScrubs Direct**.
2. **APIs & Services** → **OAuth consent screen** → **External**. Fill in the app name, support email,
   logo, and the homepage, privacy and terms URLs.
3. **Credentials** → **Create credentials** → **OAuth client ID** → **Web application**.
4. Under **Authorised redirect URIs** add both of these exactly:

| Redirect URI |
|---|
| `https://bulkscrubsdirect.ca/api/v1/auth/google/callback` |
| `http://localhost:8000/api/v1/auth/google/callback` |

**Send back:** the Client ID and Client secret.

**Worth knowing:** Google will not release the consent screen from "testing" mode until the privacy
and terms pages are live at real URLs. Until then, sign-in works only for accounts you list. That makes
the policy wording in §7 of the Materials Request a dependency here, not only at launch.

---

## 4. Payments — PayPal

**Received and integrated (2026-09-25).** You supplied a PayPal REST client ID and secret, and
checkout now takes PayPal payments: the customer pays in a PayPal window, the amount is verified
against the order server-side, and the order is confirmed automatically. Interac e-Transfer stays
available alongside it for customers who prefer to pay by transfer.

**PayPal is switched OFF until you turn it on.** In the admin: **Store settings → Orders → PayPal →
"Accept PayPal at checkout"**. That section also states which PayPal account the site is connected to,
so you can confirm it before going live.

### The webhook — received 2026-09-25

The Webhook ID you sent (`41N89698JR803294M`) is now configured, so PayPal notifies us directly when a
capture completes. A payment no longer depends on the customer's browser coming back from PayPal: if
they close the tab after paying, the webhook still confirms the order.

Two things about it are worth confirming on your side, because both fail quietly rather than loudly:

- **It must be the webhook from the same (live) app** as the client ID and secret. A webhook ID
  created under the sandbox app is rejected when we check it against the live API, and the symptom is
  simply that deliveries stop being accepted.
- **The subscribed event types** should be `PAYMENT.CAPTURE.COMPLETED` plus `DENIED`, `REVERSED` and
  `REFUNDED`, and the URL should be `https://bulkscrubsdirect.ca/api/v1/webhooks/paypal`. The
  dashboard shows both on the webhook's own page.

### Two things still outstanding

**1. Rotate the secret — please do this first.** The client secret was sent to me as plain text, which
means it has existed in a chat log and in my machine's shell history. Nothing suggests it has been
misused, but a payment secret that has travelled in plain text should not stay in service. In the
PayPal developer dashboard: **Apps & Credentials → your app → Secrets → Generate new secret**, then
remove the old one and send me the replacement by the method in §1 of this document. Rotating it takes
about a minute and invalidates the copy that was exposed.

**2. Sandbox credentials.** The pair you sent is for the **live** account, so there is currently no way
to test a full payment without moving real money. A sandbox client ID and secret from the same
dashboard (**Apps & Credentials → Sandbox**) would let the flow be exercised end to end safely. If you
create a sandbox app, its webhook ID would be useful too — it belongs to that environment and cannot
be used against live.

### Cards — still open

PayPal covers PayPal balances and cards held inside a PayPal account. It does not put a card form on
our own checkout page. If you want customers to be able to pay by card without a PayPal account, that
is a separate integration (Stripe, or PayPal's Advanced Card Processing) with its own merchant
approval — worth deciding on, but it is not blocking launch now that PayPal works.

☐ Secret rotated and replacement sent ☑ Webhook created and ID sent ☐ Sandbox credentials sent
☐ Decision on a separate card processor

---

## 5. Domain and DNS

**Act on this in week 1.** DNS changes take up to 48 hours to propagate, and the email records need to
be live well before the first real order email is sent.

| Field | Notes |
|---|---|
| Registrar | GoDaddy, Namecheap, etc. |
| Is `bulkscrubsdirect.ca` registered? | If not, register it now, in the business name |
| Registered to whom? | Must be BulkScrubs Direct, per §12 of your requirements |
| Access | Add me as a collaborator, **or** say you would rather paste in records I send you |

Either arrangement is fine. Roughly six records are needed: an A record, a `www` CNAME, and SPF, DKIM
and DMARC so order emails authenticate as genuinely yours. The exact email records depend on your
answer in §2.

---

## 6. Hosting

| Field | Notes |
|---|---|
| Provider | Recommended: DigitalOcean, Vultr or Hetzner — Toronto region |
| Account | Opened by BulkScrubs Direct; add me as a team member |
| Server access | I will email an SSH public key to add — safe to email, it is the half meant to be public |

A Canadian region keeps customer data in Canada, which keeps the PIPEDA position simple.

---

## 7. Shipping — Stallion Express

**Not blocking.** Orders are rated from a table of fixed rates in the admin dashboard until a key
exists and live rating is switched on, so a late key costs accuracy, not launch.

`ship.stallionexpress.ca` → **Settings** → **API** → generate a token.

**Send back:** the API key, and which services to offer at checkout. Recommended: the cheapest
qualifying service plus one expedited option, rather than a long list.

---

## 8. Off-site backup storage

Needed by week 10. The nightly dump and weekly verification are already built; what is missing without
this is somewhere off the server to put them. A backup stored only on the machine it protects covers a
bad deployment and nothing else.

**Send back**, for an S3-compatible bucket in a **Canadian region** (DigitalOcean Spaces, Backblaze B2,
or AWS S3 `ca-central-1`):

| Field |
|---|
| Bucket name |
| Region |
| Endpoint URL |
| Access key ID |
| Secret access key |

Please scope the key to that bucket alone. Product images live on the web server, not here, so this
bucket holds database backups only and will cost a few dollars a month.

---

## 9. Business values

Not credentials — settings, all entered in the admin dashboard and all changeable later. Listed here
so everything needed to switch the site on is in one document.

| Value | Used for |
|---|---|
| GST/HST registration number | Printed on every invoice |
| Provinces you collect tax in | Tax calculation — **needs your accountant, not me** |
| Interac e-Transfer address, if offering it | Payment instructions emailed to the customer |
| How long to hold an unpaid e-Transfer order | Recommended: 3 business days, then auto-cancel |
| Email address for new-order alerts | Where staff notifications go |
| Store contact email and phone | Contact page and order confirmations |
| Local pickup address, hours, lead time | The pickup option at checkout |
| Instagram / Facebook / TikTok URLs | Website footer |

---

## 10. Checklist

Tick as sent. Nothing needs to arrive in order.

☐ **2** Sending address + app password + Workspace/Postmark/Gmail decision
☐ **3** Google OAuth Client ID + Client secret
☑ **4** PayPal client ID + secret — received 2026-09-25, integrated
☑ **4** PayPal webhook ID — received 2026-09-25, configured
☐ **4** PayPal secret rotated (it was sent in plain text) + sandbox credentials
☐ **5** Domain registrar + access arrangement
☐ **6** Hosting provider + team access
☐ **7** Stallion Express API key + service selection
☐ **8** Backup bucket details
☐ **9** Business and tax values

Any of these can be done together on a screen share in under half an hour — often faster than working
through it alone, particularly the Google Cloud console. Questions about why something is needed are
welcome; if an item looks like it is asking for more access than it should, say so and I will explain
or find a narrower way.
