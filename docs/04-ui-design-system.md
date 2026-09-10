# 04 — UI Design System

Derived from the two Faire reference screenshots supplied by the client, reconciled with §1's stated
brand direction: *"Clean, bright, modern, premium, easy to shop… warm white/off-white backgrounds,
generous whitespace, subtle neutral borders, refined typography, clean product cards, strong product
photography. Avoid large black or dark background-fill sections."*

---

## What the reference actually establishes

Reading the two screenshots as a system rather than as pictures, seven decisions do all the work:

1. **Two type families, sharply divided.** A serif carries the wordmark, every section heading, and the
   hero. A clean sans carries body copy, labels, buttons, and navigation. Nothing else varies — there is
   only one display face and one text face, and the serif is what makes the page read as "premium"
   rather than "generic e-commerce".
2. **Warm neutrals, not grey neutrals.** The off-white is cream-tinted (a hint of yellow/red), not a cool
   grey. This single choice is most of the perceived warmth. A cool `#F5F5F5` in place of a warm
   `#FAF5EF` would read as a different brand entirely.
3. **Separation by tone and hairline, never by shadow.** Sections are distinguished by a cream panel
   against white and 1px borders at very low contrast. There are effectively no drop shadows in the
   reference. This is the mechanism that lets the design stay bright while remaining legible — and it is
   why the client's "no large dark fill sections" rule does not leave the page flat.
4. **Photography supplies all the colour.** The chrome is almost monochrome. Every saturated pixel comes
   from a product image. This is stated explicitly in §1 and is visibly true in both screenshots.
5. **Restrained radii.** Cards, buttons, badges and inputs sit around 4px — enough to feel modern, small
   enough to stay formal. The one exception is the search field, which is a full pill. That contrast is
   deliberate and makes search the most inviting control on the page.
6. **Generous, consistent vertical rhythm.** Roughly 64–80px between major sections, with the section
   heading always the same size and always left-aligned above its grid.
7. **A persistent conversion bar pinned to the bottom.** In the reference it offers a first-order
   discount. **For BulkScrubs Direct this is the natural home for "Unlock wholesale pricing"** — the
   highest-value real estate on the page, always visible, never blocking content.

### Where we should deliberately diverge

- The reference hero uses a **saturated brown fill** behind white text. The client explicitly asked to
  avoid large dark background-fill sections. Our heroes use **photography with a soft light scrim** or a
  **pale warm tint**, keeping headline text dark on light. This respects §1 while keeping the reference's
  editorial composition.
- The reference is a hundred-thousand-brand marketplace, so its homepage is dense with discovery modules.
  BulkScrubs has **two categories at launch**. Copying that density would make a small catalogue look
  empty. We keep the visual language and reduce the module count — §1's "homepage should emphasise two
  primary shopping paths: WOMEN and MEN" is the organising instruction, and the layout should make those
  two paths feel deliberate rather than sparse.

---

## Design tokens

### Colour

```css
/* Surfaces — warm, never cool grey */
--surface-page:        #FFFFFF;   /* default page ground */
--surface-warm:        #FAF5EF;   /* cream panels, category tiles, announcement bar */
--surface-warm-deep:   #F3EBE1;   /* hover state on cream tiles */
--surface-sunken:      #FBF8F4;   /* image placeholders, empty states */

/* Ink — black reserved for text/icons/borders/small buttons per §1 */
--ink-900:             #1A1A1A;   /* headings, primary buttons */
--ink-700:             #3D3A37;   /* body copy */
--ink-500:             #6B6560;   /* secondary, captions, placeholders */
--ink-400:             #9A938C;   /* disabled, meta */

/* Borders — hairline, very low contrast */
--border-subtle:       #EDE7DF;   /* panel and card edges */
--border-default:      #DDD5CB;   /* inputs, dividers */
--border-strong:       #1A1A1A;   /* focused/selected states */

/* Accent — used sparingly, functional only */
--accent-sage:         #4A6B5D;   /* wholesale/unlock affordance, success */
--accent-sage-soft:    #EDF2EF;   /* sage-tinted background wash */
--accent-clay:         #8A5A44;   /* hero tint, editorial accents */

/* Status */
--status-success:      #2F6B4F;
--status-warning:      #9A6B18;
--status-error:        #A33A2E;
--status-info:         #3A5A7A;
```

**On the accent:** the reference is essentially colourless in its chrome. We introduce exactly one muted
sage as a *functional* colour — it marks wholesale/unlock affordances and nothing else. Sage reads as
clinical-adjacent without being the medical-scrubs cliché of saturated teal, and being desaturated it
never competes with product photography. Every wholesale signal on the site uses this one colour, so
customers learn its meaning in a single visit.

### Typography

Two families, matching the reference's split:

```css
--font-display: 'Fraunces', 'Playfair Display', Georgia, serif;
--font-body:    'Inter', -apple-system, 'Segoe UI', sans-serif;
```

*Fraunces* is the closest freely available match to the reference's warm, slightly editorial serif;
Playfair Display is the fallback if the client prefers a more classical tone. Final selection should be
confirmed once the logo arrives, since the wordmark sets the tone the display face has to match.

| Token | Family | Size / line-height | Weight | Use |
|---|---|---|---|---|
| `display-xl` | display | 48 / 1.1 | 400 | Hero headline |
| `display-lg` | display | 36 / 1.15 | 400 | Page titles |
| `display-md` | display | 28 / 1.2 | 400 | Section headings |
| `display-sm` | display | 22 / 1.3 | 400 | Card and panel titles |
| `body-lg` | body | 17 / 1.6 | 400 | Intro copy |
| `body-md` | body | 15 / 1.6 | 400 | Default body |
| `body-sm` | body | 13 / 1.5 | 400 | Captions, meta |
| `label` | body | 13 / 1.4 | 500, `0.02em` | Buttons, form labels, nav |
| `overline` | body | 11 / 1.3 | 600, `0.08em`, uppercase | Badges, eyebrows |

Mobile scales `display-xl` → 32px and `display-lg` → 26px; body sizes hold.

**Rule:** the serif is never used below 22px, and never for UI controls. Mixing it into buttons or labels
is the fastest way to lose the reference's discipline.

The wordmark follows the reference: uppercase, letter-spaced ~`0.22em`, serif. This holds as a temporary
treatment until the final logo file arrives (§14).

### Spacing, radius, elevation

```css
--space: 4 8 12 16 24 32 48 64 80 120 (px scale)
--radius-sm:   4px;    /* buttons, badges, inputs, cards — the default */
--radius-md:   8px;    /* hero, modals, large panels */
--radius-full: 999px;  /* search field, filter pills, avatars only */

--shadow-none: none;                              /* the default everywhere */
--shadow-pop:  0 4px 16px rgba(26,26,26,.08);     /* dropdowns, modals, sticky bar only */
```

Elevation is used almost nowhere. Cards do **not** get shadows — they get a hairline border or a cream
ground. This is the most commonly broken rule when implementing this style, and the one that most
degrades it.

### Layout

```css
--container-max: 1440px;   /* content */
--container-wide: 1760px;  /* full-bleed heroes and carousels */
--gutter-desktop: 32px;
--gutter-mobile: 16px;
--section-gap: 80px;       /* 56px on mobile */
```

Breakpoints: `sm 640` · `md 768` · `lg 1024` · `xl 1280` · `2xl 1536`.

Grid behaviour, mirroring the reference:

| Module | 2xl | lg | md | sm |
|---|---|---|---|---|
| Product cards | 6 | 4 | 3 | 2 |
| Category tiles | 3 | 3 | 2 | 1 |
| Editorial mosaic | 1 feature + 4×2 | 1 feature + 3×2 | 2×3 | 1×n |
| Search-term tiles | 4 | 3 | 2 | 1 |

**Two product cards per row on mobile, not one.** §1 calls mobile important; a two-up grid keeps browsing
efficient on a phone and matches how apparel shoppers actually scan.

---

## Components

### Header
Three stacked rows, exactly as the reference:

1. **Announcement bar** — `--surface-warm`, centred `body-sm`, one underlined inline link.
   BulkScrubs copy: *"Free shipping on orders over $600. Sign up to unlock wholesale pricing."*
2. **Main bar** — white, 72px. Wordmark left · "All categories" with chevron · pill search (flex-grow,
   max 720px, `--radius-full`, `--border-default`, 44px, leading magnifier icon) · right cluster of text
   links (`Wholesale`, `About`, `Contact`) · account icon · cart with count · a solid `--ink-900`
   **"Unlock wholesale pricing"** button at `--radius-sm`.
3. **Category nav** — centred links at `label` size, `--border-subtle` underneath.
   `New Arrivals · Women · Men · Scrub Sets · Tops · Bottoms · Wholesale`.

On scroll the announcement bar detaches and rows 2–3 stick — the behaviour visible between the two
reference screenshots. Mobile collapses to hamburger · wordmark · search icon · cart, with a full-screen
drawer.

### Hero
Full-width, `--radius-md`, ~380px desktop / 280px mobile. Photography right, content left, with a warm
scrim rather than a dark fill (see divergence note). `display-xl` headline, `body-lg` subhead, one
primary CTA. Homepage runs a **split hero — WOMEN | MEN, two equal panels** — because §1 names those as
the two primary shopping paths and the split makes that structural rather than merely stated.

### Product card
The most-repeated component; it must be right.

```
┌──────────────────────┐
│ [New]                │  badge, top-left, overline, white pill or --ink-900
│                      │
│    product image     │  4:5, --surface-sunken placeholder, hover → alt image
│                      │
├──────────────────────┤
│ Cherokee Scrub Set   │  body-md, --ink-900, 2-line clamp
│ ● ● ● ● +2           │  colour swatches, 14px circles, +N overflow
│ $65.00               │  label weight 500
│ 🔒 Unlock wholesale  │  --accent-sage, body-sm  (logged out)
│ $45.00 · Tier 1      │  --accent-sage, body-sm  (logged in, qualified)
└──────────────────────┘
```

No border, no shadow — the image is the card. Only the wholesale line changes between states, so the
grid never reflows on login. Out-of-stock variants show a `Sold out` overline and desaturate the image
to ~60%.

### The wholesale lock — the signature interaction

The single most important custom pattern on the site, and the one thing not directly answerable from
the reference. §3's requirement is that wholesale prices stay hidden but feel *present and attainable*.

**Logged out:** the wholesale price is replaced by a lock icon and sage `Unlock wholesale pricing` link —
never a blurred or obfuscated number. Blurred text invites people to try to read it, and reads as a
dark pattern; a clean lock reads as a membership benefit.

**Logged out, qualifying cart:** an inline sage panel in the cart —
*"Your order qualifies for wholesale. Sign in to save $118.00."* This is the highest-intent moment in the
entire funnel and deserves the most prominent treatment on the page.

**Logged in, below threshold:** a progress meter toward the next tier —
*"Add $46.00 more to reach Tier 2 and save $120.00."* A thin sage bar over `--surface-warm`.

**Logged in, qualified:** a sage `Tier 2 applied` chip beside the total, with per-line savings shown.

### Tier ladder
On the *Wholesale / How It Works* page (§11) and in the cart drawer: three horizontal bands showing
threshold, price, and saving, with the customer's current position marked. This is the page that
converts a browser into an account, so it gets real design attention rather than being treated as filler.

### Buttons

| Variant | Fill | Text | Border | Use |
|---|---|---|---|---|
| Primary | `--ink-900` | white | none | Add to cart, checkout, unlock |
| Secondary | white | `--ink-900` | `--border-strong` | Continue shopping |
| Tertiary | transparent | `--ink-700` | none, underline on hover | Inline links |
| Wholesale | `--accent-sage` | white | none | Unlock CTAs only |

Heights 44 / 40 / 36px, `--radius-sm`, `label` type. Every button is ≥44px on touch. §1's "black should
be used for small buttons" is honoured — primary buttons are dark but compact, never full-bleed dark bands.

### Product page
Two columns at `lg`+: gallery left (sticky, thumbnail rail, zoom), buying panel right — title,
SKU, price block with the wholesale state, colour swatches, size selector with a `Size chart` link
opening a modal, quantity stepper, stock line, add to cart, then the **accordion** (+/−) for
Description · Materials · Dimensions & Fit exactly as §2 specifies. Single column on mobile, gallery
first, buying panel second, accordion last.

### Category page
Breadcrumb · `display-lg` title · short intro · a filter row (colour, size, price, availability) as
`--radius-full` pills with a sort control right · product grid · pagination. Filters become a bottom
sheet on mobile.

### Sticky conversion bar
Reference's most transferable pattern. White, `--shadow-pop`, top hairline, dismissible, ~72px:
*"Sign up to unlock wholesale pricing — save up to 30% on orders over $200"* + email field + dark button.
Hidden once authenticated; replaced in-cart by the tier-progress meter.

### Forms
44px inputs, `--radius-sm`, `--border-default`, focus ring `--border-strong` at 2px offset. Labels above,
never placeholder-as-label. Errors in `--status-error` beneath the field with an icon. Registration keeps
§3's short list — name, email, phone, password, optional business name, city/province — on a single
screen, because the requirement is explicitly a fast, low-friction signup.

### Empty, loading, error states
Skeletons use `--surface-sunken` blocks at the exact final dimensions — no spinners, no layout shift.
Empty states get a one-line explanation and one action. This matters more than usual on a launch
catalogue of two categories, where empty filter results will be common.

---

## Accessibility (AODA / WCAG 2.0 AA)

The client is an Ontario business; this is treated as a requirement, not a nicety.

- Body text meets 4.5:1 — `--ink-700` on white is ~10:1, `--ink-500` on `--surface-warm` ~5.4:1.
  `--ink-400` is metadata only, never body copy.
- **Colour never carries meaning alone.** Wholesale state always pairs sage with a lock icon and text;
  stock state always pairs with a label. Colour-blind users lose nothing.
- Visible focus on every interactive element — the 2px `--border-strong` ring, never `outline: none`.
- Full keyboard operability: accordions, size selectors, modals, carousels, filter sheets.
- Semantic landmarks, one `h1` per page, ordered headings.
- Alt text on every product image, populated from the admin `alt_text` field (§9) — not auto-generated.
- Carousels expose real prev/next buttons (as the reference does) and never auto-advance.
- `prefers-reduced-motion` respected on all transitions.
- Touch targets ≥44×44px.

## Motion

Restrained, as befits the reference. 150ms `ease-out` on hover, 200ms on accordions and dropdowns,
250ms on drawers and modals. Product image cross-fade on hover at 200ms. Nothing else moves, and nothing
animates on scroll.

---

## Tailwind theme

```js
// tailwind.config — mirrors the tokens above
theme: {
  extend: {
    colors: {
      surface: { page:'#FFFFFF', warm:'#FAF5EF', warmDeep:'#F3EBE1', sunken:'#FBF8F4' },
      ink:     { 900:'#1A1A1A', 700:'#3D3A37', 500:'#6B6560', 400:'#9A938C' },
      edge:    { subtle:'#EDE7DF', DEFAULT:'#DDD5CB', strong:'#1A1A1A' },
      sage:    { DEFAULT:'#4A6B5D', soft:'#EDF2EF' },
      clay:    '#8A5A44',
    },
    fontFamily: {
      display: ['Fraunces','Playfair Display','Georgia','serif'],
      body:    ['Inter','-apple-system','Segoe UI','sans-serif'],
    },
    borderRadius: { sm:'4px', md:'8px', full:'999px' },
    maxWidth:     { container:'1440px', wide:'1760px' },
    boxShadow:    { pop:'0 4px 16px rgba(26,26,26,.08)' },
  }
}
```

## Build order for the component library

Built in Phase 1 as a Storybook-documented library, before any page work, so pages assemble from a
finished kit rather than each page inventing its own variants:

**Primitives** → Button · Input · Select · Checkbox · Radio · Badge · Chip · Icon · Skeleton
**Composites** → ProductCard · CategoryTile · PriceBlock · WholesaleLock · TierProgress · Accordion ·
QuantityStepper · ColorSwatches · SizeSelector · Gallery · Carousel · FilterBar
**Layout** → Header · Footer · StickyBar · PageSection · Breadcrumb · Modal · Drawer · Toast
