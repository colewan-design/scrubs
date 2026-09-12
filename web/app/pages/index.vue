<script setup lang="ts">
import {
  ArrowRight,
  Users,
  UserPlus,
  Tags,
  PackageCheck,
  Truck,
  MapPin,
  RefreshCw,
} from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

/**
 * §1: "Homepage should initially emphasize two primary shopping paths:
 * WOMEN and MEN." The split hero makes that structural rather than merely
 * stated, and keeps a two-category catalogue from looking sparse.
 *
 * Everything below it answers the second half of §1 — that a first-time
 * visitor has to understand within seconds that anyone can buy at retail and
 * that an account is what unlocks wholesale. Hence: the access strip, then the
 * three-step wholesale explainer, then the reasons to buy here at all.
 */
const api = useApi()
const auth = useAuthStore()

// Six, to fill exactly one row at xl and divide cleanly at every smaller
// breakpoint — a ragged last row is the one thing a featured strip must avoid.
const { data: featured } = await useAsyncData('home-featured', () =>
  api.get<{ data: ProductCard[] }>('/products', { per_page: 6 }),
)

// Shared with the header's announcement bar — one request, not two.
const { minOrder } = await useWholesaleSummary()

useSeoMeta({
  title: 'Wholesale Scrubs in Canada',
  description:
    'Canadian scrub uniforms for individuals, teams and resellers. Unlock wholesale pricing on orders over $200. Free shipping over $600.',
})

/**
 * Hero photography is placeholder, like every other image on the site (§14 /
 * materials request §3): these are the scraped development shots, and both
 * paths are swapped for the client's own art in this one array.
 */
const paths = [
  {
    eyebrow: 'Scrubs for her',
    headline: 'Comfort moves care forward.',
    blurb: 'Stylish, functional scrubs for every shift — because you do more.',
    cta: 'Shop Women',
    to: '/women',
    image: '/placeholders/products/womens-cropped-sydney-outerwear--purple-haze.jpg',
    alt: 'Healthcare worker in lavender scrubs',
  },
  {
    eyebrow: 'Scrubs for him',
    headline: 'Performance looks good on you.',
    blurb: 'Durable, comfortable scrubs designed for every day and every shift.',
    cta: 'Shop Men',
    to: '/men',
    image: '/placeholders/products/mens-stratton-henley-scrub-top--navy.jpg',
    alt: 'Healthcare worker in navy scrubs with a stethoscope',
  },
]

const steps = [
  {
    icon: UserPlus,
    title: 'Create account',
    body: 'Sign up for a free account — it only takes a minute.',
  },
  {
    icon: Tags,
    title: 'See wholesale tiers',
    body: 'Get access to our tiered pricing based on your order volume.',
  },
  {
    icon: PackageCheck,
    title: 'Reach MOQ and save',
    body: 'Hit the minimum order quantity and unlock your wholesale pricing.',
  },
]

const reasons = [
  {
    icon: Truck,
    title: 'Canada-wide shipping',
    body: 'Fast, reliable delivery across Canada.',
  },
  {
    icon: MapPin,
    title: 'Local pickup',
    body: 'Pick up from our Canadian location (select areas).',
  },
  {
    icon: Users,
    title: 'Easy group ordering',
    body: 'Outfit your team, clinic or organization with ease.',
  },
  {
    icon: RefreshCw,
    title: 'Simple reorders',
    body: 'Save your favourites and reorder in just a few clicks.',
  },
]
</script>

<template>
  <div>
    <!-- One h1 for the document; the two hero panels are peers beneath it, and
         neither is the page's subject on its own. -->
    <h1 class="sr-only">BulkScrubs Direct — wholesale scrub uniforms in Canada</h1>

    <!-- Split hero, full-bleed. Photography with a warm ground rather than a
         dark fill: §1 explicitly rules out large dark background sections. -->
    <section class="grid md:grid-cols-2" aria-label="Shop by fit">
      <NuxtLink
        v-for="path in paths"
        :key="path.to"
        :to="path.to"
        class="group relative flex min-h-[360px] items-center overflow-hidden bg-surface-warm md:min-h-[440px] lg:min-h-[520px]"
      >
        <!-- The photograph sits on the right half, its left edge dissolved into
             the panel so the studio backdrop never shows as a hard rectangle
             against the warm ground.
             A mask on the image rather than a warm gradient laid OVER it: a
             scrim that reaches far enough left to hide the seam also washes the
             garment out, which turned the navy set grey. This fades the pixels
             themselves and leaves the rest of the photograph at full strength. -->
        <img
          :src="path.image"
          :alt="path.alt"
          class="absolute inset-y-0 right-0 h-full w-[58%] object-cover object-top transition-transform duration-500 group-hover:scale-[1.03] sm:w-[50%] lg:w-[46%]"
          style="
            mask-image: linear-gradient(to right, transparent 0%, rgb(0 0 0 / 0.65) 18%, #000 42%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, rgb(0 0 0 / 0.65) 18%, #000 42%);
          "
        >

        <!-- Capped as a share of the panel on a phone, where a fixed width runs
             the copy under the photograph. -->
        <div class="relative max-w-[62%] px-6 py-12 sm:max-w-76 sm:px-10 md:px-8 lg:max-w-92 lg:px-12">
          <p class="text-[11px] font-semibold tracking-[0.14em] text-ink-500 uppercase">
            {{ path.eyebrow }}
          </p>
          <h2 class="mt-3 font-display text-[34px] leading-[1.05] text-ink-900 lg:text-[44px]">
            {{ path.headline }}
          </h2>
          <p class="mt-3 max-w-[30ch] text-[14px] leading-relaxed text-ink-700">
            {{ path.blurb }}
          </p>
          <!-- Not a nested <a>: the whole panel is the link, so this is the
               button's appearance without the element. -->
          <span
            class="mt-7 inline-flex h-11 items-center gap-2 rounded-sm bg-ink-900 px-5 text-[13px] font-medium tracking-[0.02em] text-white transition-colors group-hover:bg-ink-700"
          >
            {{ path.cta }}
            <ArrowRight
              :size="15"
              class="transition-transform duration-150 group-hover:translate-x-1"
              aria-hidden="true"
            />
          </span>
        </div>

        <!-- The flourish, on the men's panel only, exactly as drawn. Decorative:
             it repeats nothing and announces nothing, so it is hidden from
             assistive tech rather than read out as three orphaned words. -->
        <div
          v-if="path.to === '/men'"
          class="pointer-events-none absolute top-8 right-8 hidden w-44 -rotate-6 text-right lg:block"
          aria-hidden="true"
        >
          <p class="font-script text-[25px] leading-[1.2] text-ink-900">
            Healthcare<br>Looks Good<br>On You
          </p>
          <svg
            class="mt-1 ml-auto w-28 text-status-info/45"
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
      </NuxtLink>
    </section>

    <!-- Access strip. The one sentence §1 asks a first-time visitor to leave
         with: everyone can buy, an account is what changes the price. Members
         are already past it, so they never see it. -->
    <section
      v-if="!auth.isAuthenticated"
      class="border-b border-edge-subtle bg-surface-warm-deep"
    >
      <div
        class="container-wide flex flex-wrap items-center gap-x-6 gap-y-3 py-5"
      >
        <Users :size="22" class="shrink-0 text-ink-700" aria-hidden="true" />
        <p class="text-[15px] font-semibold text-ink-900">
          Everyone can browse and shop at retail prices.
        </p>
        <span class="hidden h-5 w-px bg-edge lg:block" aria-hidden="true" />
        <p class="text-[14px] text-ink-700">
          Create an account to unlock wholesale pricing, tiered discounts and more.
        </p>
        <UiBaseButton to="/wholesale" variant="secondary" size="md" class="ml-auto">
          Learn About Wholesale
          <ArrowRight :size="15" aria-hidden="true" />
        </UiBaseButton>
      </div>
    </section>

    <!-- Featured products -->
    <section class="container-content pt-14">
      <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <div>
          <h2 class="font-display text-[28px] text-ink-900">Featured Scrubs</h2>
          <p class="mt-1 text-[14px] text-ink-500">
            Trusted styles. Great value. Ready for your next shift.
          </p>
        </div>
        <NuxtLink
          to="/products"
          class="inline-flex items-center gap-2 py-1 text-[13px] font-medium text-ink-900 hover:underline underline-offset-4"
        >
          View All Products
          <ArrowRight :size="15" aria-hidden="true" />
        </NuxtLink>
      </div>

      <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <ShopProductCard
          v-for="product in featured?.data ?? []"
          :key="product.id"
          :product="product"
        />
      </div>
    </section>

    <!-- How wholesale works -->
    <section class="container-content pt-16">
      <div class="rounded-md border border-edge-subtle bg-surface-warm px-6 py-10 sm:px-10">
        <div class="flex flex-wrap items-start justify-between gap-x-8 gap-y-4">
          <div>
            <h2 class="font-display text-[28px] text-ink-900">How Wholesale Works</h2>
            <p class="mt-1 text-[14px] text-ink-500">
              Get started in minutes and unlock better pricing for your team or business.
            </p>
          </div>
          <UiBaseButton
            v-if="!auth.isAuthenticated"
            to="/account/register"
            variant="secondary"
            size="md"
          >
            Create an Account
            <ArrowRight :size="15" aria-hidden="true" />
          </UiBaseButton>
          <UiBaseButton v-else to="/wholesale" variant="secondary" size="md">
            See your pricing tiers
            <ArrowRight :size="15" aria-hidden="true" />
          </UiBaseButton>
        </div>

        <!-- The arrows are decoration between steps, so they are dropped rather
             than stacked when the row wraps to one column. -->
        <ol class="mt-9 grid gap-6 lg:grid-cols-[1fr_auto_1fr_auto_1fr] lg:items-center lg:gap-4">
          <template v-for="(step, i) in steps" :key="step.title">
            <!-- An <ol> may only contain <li>, so the connector is one too —
                 presentational, and never counted or announced. -->
            <li v-if="i > 0" role="presentation" class="hidden lg:block" aria-hidden="true">
              <ArrowRight :size="18" class="shrink-0 text-ink-400" />
            </li>
            <li class="flex items-start gap-4">
              <span
                class="tabular grid size-10 shrink-0 place-items-center rounded-full bg-white text-[14px] font-medium text-ink-900"
              >{{ i + 1 }}</span>
              <span
                class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-ink-700"
                aria-hidden="true"
              >
                <component :is="step.icon" :size="18" />
              </span>
              <span class="min-w-0">
                <span class="block text-[14px] font-semibold text-ink-900">{{ step.title }}</span>
                <span class="mt-1 block text-[13px] leading-relaxed text-ink-500">
                  {{ step.body }}
                </span>
              </span>
            </li>
          </template>
        </ol>

        <p v-if="minOrder && !auth.isAuthenticated" class="mt-8 text-[13px] text-ink-500">
          Minimum wholesale order {{ minOrder.formatted }}. Below that you can still
          buy at regular retail pricing — no account required.
        </p>
      </div>
    </section>

    <!-- Why shop here -->
    <section class="container-content pt-16">
      <h2 class="font-display text-[28px] text-ink-900">Why Shop with BulkScrubs Direct?</h2>
      <p class="mt-1 text-[14px] text-ink-500">
        More than scrubs. We're here to support the people who care for Canadians.
      </p>

      <ul class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-0">
        <li
          v-for="(reason, i) in reasons"
          :key="reason.title"
          class="flex items-start gap-4"
          :class="i > 0 && 'lg:border-l lg:border-edge-subtle lg:pl-8'"
        >
          <span
            class="grid size-12 shrink-0 place-items-center rounded-full bg-surface-warm text-ink-700"
            aria-hidden="true"
          >
            <component :is="reason.icon" :size="20" />
          </span>
          <span class="min-w-0">
            <span class="block text-[14px] font-semibold text-ink-900">{{ reason.title }}</span>
            <span class="mt-1 block max-w-[28ch] text-[13px] leading-relaxed text-ink-500">
              {{ reason.body }}
            </span>
          </span>
        </li>
      </ul>
    </section>
  </div>
</template>
