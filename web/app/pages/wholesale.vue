<script setup lang="ts">
import {
  ArrowRight,
  Check,
  ChartColumnIncreasing,
  Heart,
  Hospital,
  Lock,
  PackageCheck,
  Repeat,
  Search,
  ShieldCheck,
  ShoppingCart,
  Store,
  Tags,
  Truck,
  UserPlus,
  Users,
} from 'lucide-vue-next'

/**
 * §11's "Wholesale / How It Works" page.
 *
 * This is the page that converts a browser into an account, so it gets real
 * design attention rather than being treated as filler.
 *
 * Every number on it — the minimum, the free-shipping threshold, each tier's
 * threshold and its price — comes from the API. None of it is typed into this
 * template, because all of it is admin-editable and copy that drifts from what
 * the cart actually charges is worse than no copy at all. It shares the
 * header's useAsyncData key, so the page costs no extra request.
 */
const auth = useAuthStore()
const { tiers, minOrder, freeShipping } = await useWholesaleSummary()

useSeoMeta({
  title: 'Wholesale Pricing',
  description:
    'Unlock wholesale scrub pricing with a free account. No application, no approval — your order size sets your price.',
})

/** Read as "CAD $200.00": currency stated, never assumed. See ProductCard. */
const money = (m: { currency: string; formatted: string } | null | undefined) =>
  m ? `${m.currency} ${m.formatted}` : null

const promises = [
  { icon: Users, body: 'Everyone can browse and shop at retail prices.' },
  { icon: Tags, body: 'Create an account to unlock wholesale pricing.' },
  { icon: ChartColumnIncreasing, body: 'Larger orders automatically get better pricing.' },
]

const steps = computed(() => [
  {
    icon: Search,
    title: 'Browse Products',
    body: 'Anyone can browse and shop our full range of scrubs at retail prices.',
  },
  {
    icon: UserPlus,
    title: 'Create Account',
    body: 'Sign up for a free account — it only takes a minute.',
  },
  {
    icon: Tags,
    title: 'See Wholesale Tiers',
    body: 'Get access to our tiered pricing based on your order volume.',
  },
  {
    icon: PackageCheck,
    title: 'Reach the Minimum',
    body: minOrder.value
      ? `Place an order that meets the minimum order value (${money(minOrder.value)}) to unlock wholesale pricing.`
      : 'Place an order that meets the minimum order value to unlock wholesale pricing.',
  },
  {
    icon: ShoppingCart,
    title: 'Checkout',
    body: 'Your wholesale pricing is applied automatically at checkout — no codes to enter.',
  },
  {
    icon: Truck,
    title: 'Track Order',
    body: 'We get your order ready and keep you updated with tracking from our Canadian locations.',
  },
])

const flexible = [
  { icon: Users, title: 'Group ordering', body: 'Outfit your team with ease.' },
  { icon: Hospital, title: 'Clinic outfitting', body: 'Everything you need in one place.' },
  { icon: Store, title: 'Reseller support', body: 'Reliable supply and competitive pricing.' },
  { icon: Repeat, title: 'Easy reorders', body: 'Reorder a past order in a couple of clicks.' },
]

/**
 * One tint per rung, in the order the mockup draws them: blue, violet, then the
 * house green on the best price. Beyond three tiers it cycles rather than
 * inventing colours — the ladder reads as a progression either way, and sage
 * still lands on the top rung because sage means wholesale on this site.
 */
const TIER_TINTS = [
  { card: 'border-status-info/20 bg-tint-blue', icon: 'text-status-info' },
  { card: 'border-violet/20 bg-tint-violet', icon: 'text-violet' },
  { card: 'border-sage/30 bg-sage-soft', icon: 'text-sage' },
]

const tintFor = (i: number) => TIER_TINTS[i % TIER_TINTS.length]!

const trust = [
  { icon: ShieldCheck, label: 'Trusted brands' },
  { icon: Users, label: 'Better teams' },
  { icon: Heart, label: 'Healthier communities' },
]

/**
 * Placeholder photography, like most of the shots on the site (§14 / materials
 * request §3) — these are the scraped development shots and get swapped for the
 * client's own art here.
 */
const heroImages = [
  {
    src: '/placeholders/products/womens-cropped-sydney-outerwear--purple-haze.jpg',
    alt: 'Healthcare worker in lavender scrubs',
  },
  {
    src: '/placeholders/products/mens-stratton-henley-scrub-top--navy.jpg',
    alt: 'Healthcare worker in navy scrubs',
  },
]
</script>

<template>
  <div>
    <!-- ------------------------------------------------------------- hero -->
    <section
      class="relative overflow-hidden bg-surface-warm sm:flex sm:min-h-[420px] sm:items-center
             lg:min-h-[520px]"
    >
      <!-- On a phone the photographs are a band above the copy: sharing the
           width with them leaves the headline three words per line and breaks
           the buttons in half. -->
      <div class="flex h-52 w-full sm:hidden" aria-hidden="true">
        <img
          v-for="image in heroImages"
          :key="image.src"
          :src="image.src"
          :alt="image.alt"
          class="h-full w-1/2 object-cover object-top"
        >
      </div>

      <!-- From sm up, the same treatment as the home hero: the photographs'
           left edge is dissolved into the ground by a mask on the pixels
           themselves, so the studio backdrop never shows as a hard rectangle.
           A scrim laid over the top instead would wash the garments out. -->
      <div
        class="absolute inset-y-0 right-0 hidden h-full w-[48%] sm:flex lg:w-[52%]"
        style="
          mask-image: linear-gradient(to right, transparent 0%, rgb(0 0 0 / 0.65) 14%, #000 34%);
          -webkit-mask-image: linear-gradient(to right, transparent 0%, rgb(0 0 0 / 0.65) 14%, #000 34%);
        "
        aria-hidden="true"
      >
        <img
          v-for="image in heroImages"
          :key="image.src"
          :src="image.src"
          :alt="image.alt"
          class="h-full w-1/2 object-cover object-top"
        >
      </div>

      <div class="relative w-full">
        <div class="container-content">
          <div class="py-10 sm:max-w-[26rem] sm:py-12 lg:max-w-[34rem] lg:py-20">
            <p class="text-[11px] font-semibold tracking-[0.14em] text-ink-500 uppercase">
              Wholesale / How It Works
            </p>
            <h1 class="mt-3 font-display text-[36px] leading-[1.05] text-ink-900 lg:text-[52px]">
              Wholesale Made Simple.
            </h1>
            <p class="mt-4 max-w-[46ch] text-[15px] leading-relaxed text-ink-700 lg:text-[17px]">
              Quality scrubs. Better teams. Greater impact. Our wholesale program makes it easy for
              clinics, businesses and organizations to save on the scrubs they need — with a simple,
              transparent process.
            </p>

            <div class="mt-7 flex flex-wrap gap-2">
              <UiBaseButton v-if="!auth.isAuthenticated" to="/account/register" size="lg">
                Create an Account
                <ArrowRight :size="16" aria-hidden="true" />
              </UiBaseButton>
              <UiBaseButton
                to="/products"
                :variant="auth.isAuthenticated ? 'primary' : 'secondary'"
                size="lg"
              >
                Shop All Scrubs
                <ArrowRight :size="16" aria-hidden="true" />
              </UiBaseButton>
            </div>
          </div>
        </div>

        <!-- The flourish, drawn as on the home hero. Decorative: it repeats
             nothing and announces nothing, so it is hidden from assistive tech
             rather than read out as three orphaned words. -->
        <div
          class="pointer-events-none absolute top-10 right-10 hidden w-48 -rotate-6 text-right xl:block"
          aria-hidden="true"
        >
          <p class="font-script text-[26px] leading-[1.2] text-ink-900">
            Stronger<br>Healthcare<br>Together.
          </p>
          <svg
            class="mt-1 ml-auto w-32 text-status-info/45"
            viewBox="0 0 150 12"
            fill="none"
            preserveAspectRatio="none"
          >
            <path
              d="M2 8C28 3 52 2 74 4c22 2 46 5 74 1"
              stroke="currentColor"
              stroke-width="4"
              stroke-linecap="round"
            />
          </svg>
        </div>
      </div>
    </section>

    <!-- The one sentence a first-time visitor has to leave with, in three
         parts: anyone can buy, an account changes the price, size sets the tier. -->
    <section class="border-y border-edge-subtle bg-surface-warm-deep">
      <ul class="container-content grid gap-4 py-5 sm:grid-cols-3 sm:gap-8">
        <li v-for="item in promises" :key="item.body" class="flex items-center gap-3">
          <component :is="item.icon" :size="22" class="shrink-0 text-ink-700" aria-hidden="true" />
          <p class="text-[14px] leading-snug text-ink-900">{{ item.body }}</p>
        </li>
      </ul>
    </section>

    <!-- ------------------------------------------------------ how it works -->
    <section class="container-content pt-14">
      <h2 class="font-display text-[30px] text-ink-900">How It Works</h2>
      <p class="mt-1.5 text-[15px] text-ink-500">
        From browsing to delivery, wholesale is easy with BulkScrubs Direct.
      </p>

      <!-- An ordered list, because the order is the point. The arrows between
           steps are decoration on top of that, and only at a width where six
           columns actually fit. -->
      <ol class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 xl:gap-0">
        <li
          v-for="(step, i) in steps"
          :key="step.title"
          class="relative rounded-md bg-surface-sunken p-5 xl:mr-6"
        >
          <span
            class="grid size-8 place-items-center rounded-full bg-surface-warm-deep text-[13px]
                   font-semibold text-ink-700"
            aria-hidden="true"
          >
            {{ i + 1 }}
          </span>
          <component :is="step.icon" :size="24" class="mt-4 text-ink-900" aria-hidden="true" />
          <h3 class="mt-3 font-body text-[15px] font-medium text-ink-900">{{ step.title }}</h3>
          <p class="mt-1.5 text-[13px] leading-relaxed text-ink-700">{{ step.body }}</p>

          <ArrowRight
            v-if="i < steps.length - 1"
            :size="16"
            class="absolute top-1/2 -right-4 hidden -translate-y-1/2 text-ink-400 xl:block"
            aria-hidden="true"
          />
        </li>
      </ol>
    </section>

    <!-- ------------------------------------------------------------ tiers -->
    <section class="container-content pt-14">
      <div class="grid gap-6 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.4fr)_minmax(0,0.85fr)]">
        <!-- What the program is aimed at -->
        <div class="rounded-md border border-status-info/20 bg-tint-blue p-6">
          <h2 class="font-display text-[22px] leading-tight text-ink-900">
            Key Targets for Wholesale Customers
          </h2>
          <p class="mt-2.5 text-[14px] leading-relaxed text-ink-700">
            Our wholesale program is designed to be accessible and flexible, with competitive
            pricing and convenient shipping.
          </p>

          <div class="mt-5 space-y-4">
            <div class="flex items-center gap-3">
              <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-ink-700">
                <Tags :size="18" aria-hidden="true" />
              </span>
              <p class="text-[14px] text-ink-700">
                Wholesale access starting at
                <span class="tabular block font-semibold text-ink-900">{{ money(minOrder) }}</span>
              </p>
            </div>

            <div class="flex items-center gap-3">
              <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-ink-700">
                <Truck :size="18" aria-hidden="true" />
              </span>
              <p class="text-[14px] text-ink-700">
                Free shipping on qualifying orders
                <span class="tabular block font-semibold text-ink-900">{{ money(freeShipping) }}+</span>
              </p>
            </div>
          </div>

          <!-- True, and worth saying: these are settings the client edits, not
               figures baked into the site. -->
          <p class="mt-5 text-[12px] leading-relaxed text-ink-500">
            Our wholesale tiers and thresholds are configurable and may be updated as the program
            grows.
          </p>
        </div>

        <!-- The ladder itself -->
        <div>
          <h2 class="font-display text-[26px] text-ink-900">Wholesale Tiers</h2>
          <p class="mt-1.5 max-w-[52ch] text-[14px] leading-relaxed text-ink-700">
            The more you order, the more you save. Qualify by order value or by units — whichever
            you reach first — and the cart applies the tier for you.
          </p>

          <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div
              v-for="(tier, i) in tiers"
              :key="tier.slug"
              class="flex flex-col rounded-md border p-4"
              :class="tintFor(i).card"
            >
              <div class="flex items-center gap-2">
                <ChartColumnIncreasing
                  :size="18"
                  :class="tintFor(i).icon"
                  aria-hidden="true"
                />
                <h3 class="font-body text-[15px] font-medium text-ink-900">{{ tier.name }}</h3>
              </div>

              <p class="mt-3 text-[13px] leading-relaxed text-ink-700">
                From <span class="tabular font-semibold text-ink-900">{{ money(tier.min_subtotal) }}</span>
                <template v-if="tier.min_qty">
                  or <span class="font-semibold text-ink-900">{{ tier.min_qty }} units</span>
                </template>
              </p>

              <!-- Admin-editable per-tier copy. Absent for a tier that has none,
                   rather than invented here. -->
              <p v-if="tier.description" class="mt-2 text-[13px] leading-relaxed text-ink-500">
                {{ tier.description }}
              </p>

              <!-- §3: a guest is told a tier exists, never what it costs. -->
              <p
                v-if="tier.locked"
                class="mt-auto flex items-center gap-1.5 pt-3 text-[13px]"
                :class="tintFor(i).icon"
              >
                <Lock :size="14" aria-hidden="true" /> Sign in to view pricing
              </p>
              <p v-else-if="tier.unit_price" class="mt-auto pt-3">
                <span class="tabular font-display text-[22px] text-ink-900">
                  {{ tier.unit_price.formatted }}
                </span>
                <span class="text-[12px] text-ink-500"> per set</span>
              </p>
              <p v-else-if="tier.discount_percent" class="mt-auto pt-3">
                <span class="tabular font-display text-[22px] text-ink-900">
                  {{ tier.discount_percent }}% off
                </span>
                <span class="text-[12px] text-ink-500"> every item</span>
              </p>
            </div>
          </div>

          <p class="mt-4 text-[13px] text-ink-500">
            Below <span class="tabular">{{ money(minOrder) }}</span> you can still order at regular
            retail pricing — nothing is gated.
          </p>
        </div>

        <!-- Who it suits -->
        <div class="rounded-md border border-status-info/20 bg-tint-blue p-6">
          <h2 class="font-display text-[22px] leading-tight text-ink-900">Flexible for Your Needs</h2>
          <p class="mt-2.5 text-[14px] leading-relaxed text-ink-700">
            Our wholesale program supports a variety of customers, including:
          </p>

          <ul class="mt-5 space-y-4">
            <li v-for="item in flexible" :key="item.title" class="flex gap-3">
              <component
                :is="item.icon"
                :size="20"
                class="mt-0.5 shrink-0 text-ink-700"
                aria-hidden="true"
              />
              <p class="text-[13px] leading-snug text-ink-700">
                <span class="block text-[14px] font-medium text-ink-900">{{ item.title }}</span>
                {{ item.body }}
              </p>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <!-- --------------------------------------------------------- questions -->
    <section class="container-content pt-14">
      <div class="rounded-md border border-edge-subtle p-6 sm:p-8">
        <h2 class="font-display text-[24px] text-ink-900">Common questions</h2>
        <dl class="mt-5 grid gap-6 md:grid-cols-2">
          <div>
            <dt class="text-[15px] font-medium text-ink-900">Do I need a registered business?</dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              No. Your order size is the only qualification.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">Is there an approval process?</dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              None. Pricing unlocks the moment you create an account.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">
              What if my order is under the minimum?
            </dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              You can still order at regular retail pricing.
            </dd>
          </div>
          <div>
            <dt class="text-[15px] font-medium text-ink-900">When is shipping free?</dt>
            <dd class="mt-1.5 flex gap-2 text-[14px] text-ink-700">
              <Check :size="16" class="mt-0.5 shrink-0 text-sage" aria-hidden="true" />
              On qualifying orders over <span class="tabular">{{ money(freeShipping) }}.</span>
            </dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- --------------------------------------------------------------- cta -->
    <section class="mt-14 border-y border-edge-subtle bg-surface-warm">
      <div class="grid items-center lg:grid-cols-[minmax(0,0.9fr)_minmax(0,2fr)]">
        <img
          src="/images/wholesale-cta-scrub-stack.jpg"
          alt=""
          loading="lazy"
          class="hidden h-full max-h-[260px] w-full object-cover lg:block"
        >

        <div class="container-content flex flex-wrap items-center justify-between gap-8 py-10">
          <div class="min-w-[min(100%,22rem)] flex-1">
            <h2 class="font-display text-[28px] text-ink-900">Ready to Save on Scrubs?</h2>
            <p class="mt-1.5 max-w-[54ch] text-[14px] text-ink-700">
              Create an account to unlock wholesale pricing, or start browsing our products today.
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
              <UiBaseButton v-if="!auth.isAuthenticated" to="/account/register">
                Create an Account
                <ArrowRight :size="15" aria-hidden="true" />
              </UiBaseButton>
              <UiBaseButton
                to="/products"
                :variant="auth.isAuthenticated ? 'primary' : 'secondary'"
              >
                Shop All Scrubs
                <ArrowRight :size="15" aria-hidden="true" />
              </UiBaseButton>
            </div>
          </div>

          <ul class="grid w-full grid-cols-3 gap-4 sm:flex sm:w-auto sm:gap-8">
            <li v-for="item in trust" :key="item.label" class="text-center sm:w-[7.5rem]">
              <span class="mx-auto grid size-10 place-items-center rounded-full bg-white text-ink-700">
                <component :is="item.icon" :size="18" aria-hidden="true" />
              </span>
              <p class="mt-2 text-[12px] text-ink-700">{{ item.label }}</p>
            </li>
          </ul>
        </div>
      </div>
    </section>
  </div>
</template>
