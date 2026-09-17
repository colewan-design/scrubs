<script setup lang="ts">
import {
  ArrowRight,
  BadgeCheck,
  ChevronRight,
  MapPin,
  PackageCheck,
  RefreshCw,
  Tags,
  Truck,
  UserPlus,
  Users,
} from 'lucide-vue-next'
import type { ProductCard } from '~/composables/useApi'

const api = useApi()
const auth = useAuthStore()

const { data: featured } = await useAsyncData('home-featured', () =>
  api.get<{ data: ProductCard[] }>('/products', { per_page: 5 }),
)

const { minOrder, freeShipping } = await useWholesaleSummary()

useSeoMeta({
  title: 'Wholesale Scrubs in Canada',
  description:
    'Comfortable scrubs for every shift. Shop women’s and men’s medical uniforms with Canada-wide fulfilment and wholesale pricing for teams.',
})

const heroBenefits = [
  { icon: BadgeCheck, label: 'Canadian fulfillment' },
  { icon: BadgeCheck, label: 'Volume pricing' },
  { icon: BadgeCheck, label: 'Easy reorders' },
]

const trustItems = computed(() => [
  {
    icon: Truck,
    title: freeShipping.value
      ? `Free shipping over CAD ${freeShipping.value.formatted}`
      : 'Free shipping on qualifying orders',
    body: 'Across Canada',
  },
  {
    icon: Users,
    title: 'Volume pricing for teams',
    body: 'The more you order, the more you save',
  },
  {
    icon: PackageCheck,
    title: 'Canadian fulfillment',
    body: 'Fast, reliable delivery',
  },
  {
    icon: RefreshCw,
    title: 'Easy reordering',
    body: 'Save favourites and reorder in minutes',
  },
])

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
    icon: Users,
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
    body: 'Pick up from our Canadian location in select areas.',
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
  <div class="home-page overflow-hidden">
    <section class="home-hero relative bg-[#fbf5ef]" aria-labelledby="home-hero-title">
      <div class="absolute inset-0 mx-auto max-w-[1600px]" aria-hidden="true">
        <img
          src="/images/home-hero-scrubs.png"
          alt=""
          class="h-full w-full object-cover object-top"
        >
      </div>

      <div class="container-content relative flex min-h-[520px] items-center pb-24 pt-10 md:min-h-[410px] md:pb-20 lg:min-h-[390px] lg:pt-6">
        <div class="max-w-[610px] md:max-w-[47%] lg:max-w-[570px]">
          <p class="text-[11px] font-semibold tracking-[0.28em] text-ink-500 uppercase">
            For every shift. A brighter tomorrow.
          </p>
          <h1
            id="home-hero-title"
            class="mt-4 font-body text-[42px] leading-[0.98] font-bold tracking-[-0.055em] text-ink-900 sm:text-[50px] lg:text-[50px]"
          >
            Scrubs that<br>move with your shift.
          </h1>
          <p class="mt-4 max-w-[580px] text-[17px] leading-[1.35] text-ink-700 sm:text-[19px]">
            Comfortable. Durable. Designed for the people<br class="hidden lg:block"> who care for Canadians.
          </p>

          <div class="mt-6 flex flex-wrap gap-3">
            <NuxtLink
              to="/women"
              class="group inline-flex h-12 min-w-[178px] items-center justify-center gap-3 rounded-full bg-ink-900 px-7 text-[14px] font-semibold text-white transition hover:bg-ink-700"
            >
              Shop Women
              <ArrowRight :size="17" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
            </NuxtLink>
            <NuxtLink
              to="/men"
              class="group inline-flex h-12 min-w-[178px] items-center justify-center gap-3 rounded-full border border-ink-900 bg-white/65 px-7 text-[14px] font-semibold text-ink-900 transition hover:bg-white"
            >
              Shop Men
              <ArrowRight :size="17" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
            </NuxtLink>
          </div>

          <ul class="mt-6 flex flex-wrap gap-x-8 gap-y-2">
            <li
              v-for="benefit in heroBenefits"
              :key="benefit.label"
              class="flex items-center gap-2 text-[12px] text-ink-700"
            >
              <span class="grid size-5 place-items-center rounded-full border border-[#8ab4cf] bg-[#ecf7fc] text-[#376f94]">
                <component :is="benefit.icon" :size="12" aria-hidden="true" />
              </span>
              {{ benefit.label }}
            </li>
          </ul>
        </div>

        <div
          class="absolute top-14 right-[42%] hidden -rotate-3 font-script text-[23px] leading-[1.08] text-ink-900 xl:block"
          aria-hidden="true"
        >
          Comfort<br>fuels care.
          <span class="ml-2 inline-block rotate-12">♡</span>
        </div>

        <div
          class="absolute top-14 right-6 hidden rotate-2 text-right font-script text-[22px] leading-[1.05] text-ink-900 lg:block"
          aria-hidden="true"
        >
          Healthcare<br>Looks Good<br>On You
          <span class="mt-2 ml-auto block h-[2px] w-20 -rotate-6 bg-ink-500/65" />
        </div>

        <NuxtLink
          to="/wholesale"
          class="group absolute right-0 bottom-[74px] hidden h-[116px] w-[292px] items-center overflow-hidden rounded-l-xl border border-white/90 bg-white/90 shadow-sm backdrop-blur lg:flex xl:right-[-44px] xl:w-[328px]"
        >
          <div class="relative z-10 w-[52%] px-4">
            <p class="text-[13px] leading-[1.2] font-semibold text-ink-900">
              Same great scrubs.<br>A healthier tomorrow.
            </p>
            <span class="mt-3 grid size-8 place-items-center rounded-full bg-[#e3f2fa] text-[#2f6689]">
              <ArrowRight :size="15" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
            </span>
          </div>
          <img
            src="/images/wholesale-cta-scrub-stack.jpg"
            alt="Folded navy scrubs"
            class="absolute inset-y-0 right-0 h-full w-[49%] object-cover object-[28%_center]"
          >
        </NuxtLink>
      </div>
    </section>

    <div class="container-content relative z-10 -mt-[58px]">
      <ul class="grid overflow-hidden rounded-xl border border-white bg-white/95 shadow-[0_8px_28px_rgb(24_35_63/0.08)] backdrop-blur sm:grid-cols-2 lg:grid-cols-4">
        <li
          v-for="(item, index) in trustItems"
          :key="item.title"
          class="flex min-h-[74px] items-center gap-4 px-6 py-4"
          :class="index > 0 && 'lg:border-l lg:border-edge-subtle'"
        >
          <component :is="item.icon" :size="27" :stroke-width="1.7" class="shrink-0 text-[#3d7699]" aria-hidden="true" />
          <span>
            <span class="block text-[12px] leading-tight font-semibold text-ink-900">{{ item.title }}</span>
            <span class="mt-1 block text-[10px] leading-tight text-ink-500">{{ item.body }}</span>
          </span>
        </li>
      </ul>
    </div>

    <section class="container-content pt-7" aria-labelledby="featured-title">
      <div class="flex items-end justify-between gap-6">
        <div>
          <h2 id="featured-title" class="font-display text-[26px] font-semibold text-ink-900">Featured Scrubs</h2>
          <p class="mt-0.5 text-[12px] text-ink-500">Trusted styles. Great value. Ready for your next shift.</p>
        </div>
        <NuxtLink
          to="/products"
          class="group hidden items-center gap-2 pb-1 text-[12px] font-semibold text-[#174b70] hover:underline sm:inline-flex"
        >
          View all products
          <ArrowRight :size="15" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
        </NuxtLink>
      </div>

      <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <ShopProductCard
          v-for="product in featured?.data ?? []"
          :key="product.id"
          :product="product"
          compact
        />
      </div>

      <NuxtLink
        to="/products"
        class="mt-4 inline-flex items-center gap-2 text-[13px] font-semibold text-[#174b70] sm:hidden"
      >
        View all products <ArrowRight :size="15" aria-hidden="true" />
      </NuxtLink>
    </section>

    <section class="container-content pt-5" aria-labelledby="wholesale-title">
      <div class="relative overflow-hidden rounded-xl border border-[#f1e8e2] bg-[#fff8f4] px-6 py-7 lg:px-8">
        <div class="absolute inset-y-0 left-0 w-[28%] rounded-r-[120px] bg-[#fff0e9]" aria-hidden="true" />
        <div class="relative grid items-center gap-8 lg:grid-cols-[300px_1fr_auto]">
          <div>
            <h2 id="wholesale-title" class="font-display text-[27px] font-semibold text-ink-900">How Wholesale Works</h2>
            <p class="mt-1 max-w-[31ch] text-[13px] leading-[1.4] text-ink-500">
              Get started in minutes and unlock better pricing for your team or business.
            </p>
          </div>

          <ol class="grid gap-5 sm:grid-cols-3">
            <li
              v-for="(step, index) in steps"
              :key="step.title"
              class="flex min-w-0 gap-3 sm:border-l sm:border-edge-subtle sm:pl-5"
            >
              <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-[14px] font-semibold text-[#225f88] shadow-sm">{{ index + 1 }}</span>
              <span class="min-w-0">
                <span class="flex items-center gap-2 text-[11px] font-semibold text-ink-900">
                  <component :is="step.icon" :size="18" :stroke-width="1.7" aria-hidden="true" />
                  {{ step.title }}
                </span>
                <span class="mt-1 block text-[10px] leading-[1.4] text-ink-500">{{ step.body }}</span>
              </span>
            </li>
          </ol>

          <NuxtLink
            :to="auth.isAuthenticated ? '/wholesale' : '/account/register'"
            class="group inline-flex h-11 items-center justify-center gap-3 rounded-lg bg-ink-900 px-6 text-[12px] font-semibold whitespace-nowrap text-white hover:bg-ink-700"
          >
            See your pricing tiers
            <ArrowRight :size="16" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
          </NuxtLink>
        </div>
        <p v-if="minOrder && !auth.isAuthenticated" class="sr-only">
          Minimum wholesale order CAD {{ minOrder.formatted }}.
        </p>
      </div>
    </section>

    <section class="container-content grid gap-3 pt-3 lg:grid-cols-[1.35fr_1fr]" aria-label="Why shop with us">
      <div class="rounded-xl border border-edge-subtle bg-white px-6 py-5 lg:px-8">
        <h2 class="font-display text-[25px] font-semibold text-ink-900">Why Shop with BulkScrubs Direct?</h2>
        <ul class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4 lg:gap-0">
          <li
            v-for="(reason, index) in reasons"
            :key="reason.title"
            class="flex gap-3 lg:flex-col"
            :class="index > 0 && 'lg:border-l lg:border-edge-subtle lg:pl-6'"
          >
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-[#fbf4ef] text-ink-900">
              <component :is="reason.icon" :size="19" :stroke-width="1.7" aria-hidden="true" />
            </span>
            <span>
              <span class="block text-[12px] font-semibold text-ink-900">{{ reason.title }}</span>
              <span class="mt-1 block text-[11px] leading-[1.45] text-ink-500">{{ reason.body }}</span>
            </span>
          </li>
        </ul>
      </div>

      <div class="relative min-h-[220px] overflow-hidden rounded-xl bg-[#e5f4ff] px-7 py-6">
        <div class="relative z-10 max-w-[46%]">
          <h2 class="font-display text-[27px] leading-[1.02] font-semibold text-[#245477]">Built for<br>Healthcare Teams</h2>
          <p class="mt-2 text-[11px] leading-[1.45] text-ink-500">
            Outfit your clinic, department or organization with quality scrubs at better pricing.
          </p>
          <NuxtLink
            to="/contact"
            class="group mt-4 inline-flex h-10 items-center gap-2 rounded-lg bg-ink-900 px-5 text-[11px] font-semibold text-white hover:bg-ink-700"
          >
            Request a Quote
            <ArrowRight :size="14" class="transition-transform group-hover:translate-x-1" aria-hidden="true" />
          </NuxtLink>
        </div>
        <img
          src="/images/home-hero-scrubs.png"
          alt="Healthcare professionals in lavender and navy scrubs"
          class="absolute inset-y-0 right-[-18%] h-full w-[84%] object-cover object-[76%_center]"
        >
        <div class="absolute top-4 right-4 z-10 hidden rotate-3 text-right font-script text-[18px] leading-[1.05] text-ink-900 xl:block" aria-hidden="true">
          Stronger<br>Teams<br>Healthier<br>Communities
          <span class="block">♡</span>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
@media (max-width: 767px) {
  .home-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgb(251 245 239 / 0.98) 0%, rgb(251 245 239 / 0.92) 56%, rgb(251 245 239 / 0.25) 100%);
    pointer-events: none;
  }

  .home-hero > .container-content {
    z-index: 1;
  }
}
</style>
