<script setup lang="ts">
import {
  ArrowRight,
  BarChart3,
  Box,
  Building2,
  Check,
  CircleDollarSign,
  PackageCheck,
  Plus,
  Minus,
  RefreshCw,
  Search,
  ShoppingCart,
  Tags,
  Truck,
  UserRound,
  UsersRound,
} from 'lucide-vue-next'

const auth = useAuthStore()
const { tiers, minOrder, freeShipping } = await useWholesaleSummary()

useSeoMeta({
  title: 'Wholesale Pricing',
  description:
    'Unlock wholesale scrub pricing with a free account. No application, no approval — your order size sets your price.',
})

const money = (value: { currency: string; formatted: string } | null | undefined) =>
  value ? `${value.currency} ${value.formatted}` : ''

const promises = computed(() => [
  {
    icon: UsersRound,
    title: 'No approval required',
    body: 'Create an account and start saving today.',
  },
  {
    icon: Tags,
    title: `Wholesale from ${money(minOrder.value) || 'CAD $200'}`,
    body: `Unlock wholesale pricing at ${money(minOrder.value) || 'CAD $200'}+.`,
  },
  {
    icon: BarChart3,
    title: 'Better pricing at higher volumes',
    body: 'The more you order, the more you save.',
  },
])

const steps = computed(() => [
  {
    icon: Search,
    title: 'Browse Products',
    body: 'Shop our full range of scrubs at retail prices.',
  },
  {
    icon: UserRound,
    title: 'Create Account',
    body: 'Sign up for a free wholesale account — it only takes a minute.',
  },
  {
    icon: Box,
    title: 'Reach Minimum',
    body: `Place an order of at least ${money(minOrder.value) || 'CAD $200'} (${minOrder.value ? 'min. 4 units' : 'minimum order'}) to unlock wholesale pricing.`,
  },
  {
    icon: Tags,
    title: 'Pricing Unlocks',
    body: 'Your wholesale pricing is applied automatically at checkout — no codes needed.',
  },
  {
    icon: ShoppingCart,
    title: 'Checkout',
    body: 'Complete your order with our secure, fast checkout.',
  },
  {
    icon: Truck,
    title: 'Track Order',
    body: 'We’ll get your order ready and keep you updated with tracking from our Canadian locations.',
  },
])

const tierMeta = [
  {
    label: 'Starter',
    description: 'Great for small clinics and teams getting started.',
    footer: 'Unlock wholesale pricing',
  },
  {
    label: 'Business',
    description: 'Ideal for growing clinics and multi-location teams.',
    footer: 'Even better savings',
  },
  {
    label: 'Volume',
    description: 'Maximum value for large organizations and resellers.',
    footer: 'Our best pricing',
  },
]

const visibleTiers = computed(() => tiers.value.slice(0, 3))

const benefits = [
  { icon: UsersRound, title: 'Group ordering', body: 'Outfit your team with ease.' },
  { icon: Building2, title: 'Clinic outfitting', body: 'Everything you need in one place.' },
  { icon: CircleDollarSign, title: 'Reseller support', body: 'Reliable supply and competitive pricing.' },
  { icon: RefreshCw, title: 'Easy reorders', body: 'Reorder past orders in a couple of clicks.' },
]

const faqs = computed(() => [
  {
    question: 'Do I need a registered business?',
    answer: 'No. Your order size is the only qualification.',
  },
  {
    question: 'What if my order is under the minimum?',
    answer: 'You can still place your order at regular retail pricing.',
  },
  {
    question: 'Is there an approval process?',
    answer: 'No. Create a free account and wholesale pricing unlocks automatically.',
  },
  {
    question: 'When is shipping free?',
    answer: `Shipping is free on qualifying orders of ${money(freeShipping.value) || 'CAD $600'} or more.`,
  },
  {
    question: 'Can I combine different products to reach the minimum?',
    answer: 'Yes. Mix styles, colours and sizes from across the catalogue in one order.',
  },
])

const openFaq = ref(0)

function toggleFaq(index: number) {
  openFaq.value = openFaq.value === index ? -1 : index
}
</script>

<template>
  <div class="wholesale-page">
    <section class="hero-section">
      <div class="hero-photo" aria-hidden="true">
        <img src="/images/home-hero-scrubs.png" alt="">
      </div>

      <div class="wholesale-container hero-content">
        <div class="hero-copy">
          <p class="eyebrow">Wholesale program <span aria-hidden="true" /></p>
          <h1>Wholesale<br>Made Simple.</h1>
          <p class="hero-intro">
            Quality scrubs. Better teams. Greater impact. Our wholesale program makes it easy for
            clinics, businesses and organizations to save on the scrubs they need — with a simple,
            transparent process.
          </p>

          <ul class="hero-checks" aria-label="Wholesale account benefits">
            <li><Check :size="13" aria-hidden="true" /> No approval required</li>
            <li><Check :size="13" aria-hidden="true" /> Free account</li>
            <li>
              <Check :size="13" aria-hidden="true" /> Wholesale pricing starts at
              {{ money(minOrder) || 'CAD $200' }} per order
            </li>
          </ul>

          <div class="hero-actions">
            <UiBaseButton v-if="!auth.isAuthenticated" to="/account/register" size="lg">
              Create Wholesale Account
              <ArrowRight :size="16" aria-hidden="true" />
            </UiBaseButton>
            <UiBaseButton
              to="/products"
              :variant="auth.isAuthenticated ? 'primary' : 'secondary'"
              size="lg"
            >
              Shop All Scrubs
            </UiBaseButton>
          </div>
        </div>

        <div class="hero-note handwritten" aria-hidden="true">
          Stronger<br>Healthcare<br>Together.
          <span />
        </div>
        <p class="hero-side-note" aria-hidden="true">People<br>care<br>bigger<br>together</p>
      </div>
    </section>

    <section class="promise-band" aria-label="Wholesale highlights">
      <ul class="wholesale-container promise-grid">
        <li v-for="item in promises" :key="item.title">
          <span class="round-icon"><component :is="item.icon" :size="22" aria-hidden="true" /></span>
          <span>
            <strong>{{ item.title }}</strong>
            <small>{{ item.body }}</small>
          </span>
        </li>
      </ul>
    </section>

    <section class="wholesale-container how-section section-space" aria-labelledby="how-title">
      <div class="section-heading-row">
        <div>
          <h2 id="how-title">How It Works</h2>
          <p>Get wholesale pricing in 6 simple steps — from browsing to delivery.</p>
        </div>
        <NuxtLink to="/products" class="section-link">Simple. Fast. Built for healthcare teams.</NuxtLink>
      </div>

      <ol class="steps-grid">
        <li v-for="(step, index) in steps" :key="step.title">
          <span class="step-number">{{ index + 1 }}</span>
          <component :is="step.icon" :size="23" stroke-width="1.7" aria-hidden="true" />
          <h3>{{ step.title }}</h3>
          <p>{{ step.body }}</p>
          <ArrowRight
            v-if="index < steps.length - 1"
            class="step-arrow"
            :size="17"
            aria-hidden="true"
          />
        </li>
      </ol>
    </section>

    <section class="tiers-band section-space" aria-labelledby="tiers-title">
      <div class="wholesale-container tiers-inner">
        <div class="section-heading-row">
          <div>
            <h2 id="tiers-title">Wholesale Tiers</h2>
            <p>The more you order, the more you save. Qualify by order value or units — whichever comes first.</p>
          </div>
          <div class="tiers-note handwritten" aria-hidden="true">
            Higher volumes.<br>Greater impact.
            <span />
          </div>
        </div>

        <div class="tiers-grid">
          <article
            v-for="(tier, index) in visibleTiers"
            :key="tier.slug"
            class="tier-card"
            :class="{ featured: index === 1 }"
          >
            <p v-if="index === 1" class="popular">Most popular</p>
            <div class="tier-body">
              <div class="tier-title-row">
                <span class="tier-icon"><Box :size="25" stroke-width="1.5" aria-hidden="true" /></span>
                <h3>{{ tier.name }}</h3>
                <span class="tier-label">{{ tierMeta[index]?.label }}</span>
              </div>
              <p class="tier-threshold">
                From {{ money(tier.min_subtotal) }}
                <strong v-if="tier.min_qty">{{ tier.min_qty }} units</strong>
              </p>
              <p class="tier-description">{{ tier.description || tierMeta[index]?.description }}</p>
            </div>
            <p class="tier-footer">
              <span v-if="tier.locked">{{ tierMeta[index]?.footer }}</span>
              <span v-else-if="tier.discount_percent">{{ tier.discount_percent }}% off every item</span>
              <span v-else-if="tier.unit_price">{{ tier.unit_price.formatted }} per item</span>
              <span v-else>{{ tierMeta[index]?.footer }}</span>
            </p>
          </article>
        </div>
      </div>
    </section>

    <section class="wholesale-container benefits-section section-space" aria-labelledby="benefits-title">
      <div class="section-heading-row compact">
        <h2 id="benefits-title">Why businesses choose BulkScrubs Direct</h2>
        <p>Trusted by healthcare teams across Canada.</p>
      </div>

      <ul class="benefits-grid">
        <li v-for="item in benefits" :key="item.title">
          <span class="round-icon"><component :is="item.icon" :size="22" aria-hidden="true" /></span>
          <span><strong>{{ item.title }}</strong><small>{{ item.body }}</small></span>
        </li>
      </ul>
    </section>

    <section class="wholesale-container faq-section" aria-labelledby="faq-title">
      <div class="faq-intro">
        <h2 id="faq-title">Common questions</h2>
        <p>Quick answers about our wholesale program.</p>
        <NuxtLink to="/contact" class="faq-link">
          View all FAQs <ArrowRight :size="15" aria-hidden="true" />
        </NuxtLink>
      </div>

      <div class="faq-list">
        <article v-for="(faq, index) in faqs" :key="faq.question" :class="{ open: openFaq === index }">
          <h3>
            <button
              type="button"
              :aria-expanded="openFaq === index"
              :aria-controls="`faq-answer-${index}`"
              @click="toggleFaq(index)"
            >
              {{ faq.question }}
              <component :is="openFaq === index ? Minus : Plus" :size="17" aria-hidden="true" />
            </button>
          </h3>
          <p v-show="openFaq === index" :id="`faq-answer-${index}`">{{ faq.answer }}</p>
        </article>
      </div>
    </section>

    <section class="closing-cta" aria-labelledby="closing-title">
      <div class="closing-photo">
        <img src="/images/wholesale-cta-scrub-stack.jpg" alt="A neatly folded stack of navy and lavender scrubs">
      </div>
      <div class="closing-copy">
        <h2 id="closing-title">Ready for wholesale pricing?</h2>
        <p>Create an account and start saving on scrubs today.<br>It only takes about 1 minute.</p>
        <div class="closing-actions">
          <UiBaseButton v-if="!auth.isAuthenticated" to="/account/register" size="lg">
            Create Wholesale Account <ArrowRight :size="16" aria-hidden="true" />
          </UiBaseButton>
          <UiBaseButton v-else to="/products" size="lg">
            Shop All Scrubs <ArrowRight :size="16" aria-hidden="true" />
          </UiBaseButton>
          <ul>
            <li><Check :size="13" aria-hidden="true" /> No application</li>
            <li><Check :size="13" aria-hidden="true" /> No approval</li>
            <li><Check :size="13" aria-hidden="true" /> Takes about 1 minute</li>
          </ul>
        </div>
      </div>
      <div class="closing-note handwritten" aria-hidden="true">
        Same great scrubs.<br>A healthier tomorrow.
        <span />
      </div>
    </section>
  </div>
</template>

<style scoped>
.wholesale-page {
  --lavender: #7e71a7;
  --lavender-soft: #f1eef8;
  --warm: #faf6f2;
  --warm-deep: #f3ece6;
  color: var(--color-ink-700);
}

.wholesale-container {
  width: min(100% - 2rem, 1280px);
  margin-inline: auto;
}

.section-space {
  padding-block: 50px;
}

.section-heading-row {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 2rem;
}

.section-heading-row h2,
.faq-intro h2,
.closing-copy h2 {
  font-size: clamp(1.75rem, 2.5vw, 2.25rem);
  letter-spacing: -0.035em;
}

.section-heading-row p,
.faq-intro > p,
.closing-copy > p {
  margin-top: .35rem;
  font-size: .86rem;
  line-height: 1.55;
  color: var(--color-ink-500);
}

.hero-section {
  position: relative;
  min-height: 500px;
  overflow: hidden;
  background: #f9f4ee;
}

.hero-photo {
  position: absolute;
  inset: 0;
}

.hero-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center top;
}

.hero-content {
  position: relative;
  min-height: 500px;
}

.hero-copy {
  position: relative;
  z-index: 2;
  width: 47%;
  padding-block: 62px 54px;
}

.eyebrow {
  display: flex;
  align-items: center;
  gap: .8rem;
  color: var(--color-ink-500);
  font-size: .69rem;
  font-weight: 600;
  letter-spacing: .16em;
  text-transform: uppercase;
}

.eyebrow span {
  width: 38px;
  height: 1px;
  background: var(--color-ink-400);
}

.hero-copy h1 {
  margin-top: .9rem;
  font-size: clamp(3.2rem, 5vw, 4.85rem);
  line-height: .98;
  letter-spacing: -.055em;
}

.hero-intro {
  max-width: 39rem;
  margin-top: 1.35rem;
  font-size: .95rem;
  line-height: 1.65;
}

.hero-checks {
  display: flex;
  flex-wrap: wrap;
  gap: .75rem 1.3rem;
  margin-top: 1.4rem;
  font-size: .72rem;
}

.hero-checks li,
.closing-actions li {
  display: flex;
  align-items: center;
  gap: .42rem;
}

.hero-checks svg,
.closing-actions li svg {
  border-radius: 50%;
  padding: 2px;
  color: white;
  background: #9489b2;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-top: 1.65rem;
}

.handwritten {
  font-family: var(--font-script);
  color: var(--color-ink-700);
  line-height: 1.05;
}

.handwritten span {
  display: block;
  width: 54px;
  height: 9px;
  margin-top: .35rem;
  margin-left: auto;
  border-top: 2px solid var(--lavender);
  border-radius: 50%;
  transform: rotate(-7deg);
}

.hero-note {
  position: absolute;
  z-index: 3;
  top: 58px;
  right: 0;
  width: 150px;
  transform: rotate(-5deg);
  font-size: 1.7rem;
  text-align: center;
}

.hero-side-note {
  position: absolute;
  right: 0;
  bottom: 26px;
  font-size: .68rem;
  font-weight: 600;
  line-height: 1.55;
  letter-spacing: .16em;
  text-transform: uppercase;
}

.promise-band {
  border-block: 1px solid var(--color-edge-subtle);
  background: var(--warm);
}

.promise-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  padding-block: 20px;
}

.promise-grid li,
.benefits-grid li {
  display: flex;
  align-items: center;
  gap: .9rem;
  min-width: 0;
}

.promise-grid li:not(:first-child) {
  padding-left: 2.4rem;
  border-left: 1px solid var(--color-edge);
}

.round-icon {
  display: grid;
  flex: 0 0 auto;
  width: 52px;
  height: 52px;
  place-items: center;
  border-radius: 50%;
  color: var(--color-ink-900);
  background: #f1e8e1;
}

.promise-grid strong,
.benefits-grid strong {
  display: block;
  font-size: .82rem;
  font-weight: 600;
  color: var(--color-ink-900);
}

.promise-grid small,
.benefits-grid small {
  display: block;
  margin-top: .12rem;
  font-size: .69rem;
  line-height: 1.45;
  color: var(--color-ink-500);
}

.section-link {
  padding-bottom: .18rem;
  border-bottom: 1px solid #bdb4cc;
  color: #6d618f;
  font-size: .72rem;
}

.steps-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 1.3rem;
  margin-top: 1.8rem;
}

.steps-grid > li {
  position: relative;
  min-height: 225px;
  padding: 15px;
  border-radius: 5px;
  background: linear-gradient(155deg, #fbf8f5 0%, #f7f1ec 100%);
}

.step-number {
  display: grid;
  width: 30px;
  height: 30px;
  margin-bottom: .8rem;
  place-items: center;
  border-radius: 50%;
  background: #eee5df;
  color: var(--color-ink-700);
  font-size: .75rem;
  font-weight: 600;
}

.steps-grid h3 {
  margin-top: .8rem;
  font-family: var(--font-body);
  font-size: .82rem;
  font-weight: 600;
}

.steps-grid p {
  margin-top: .45rem;
  font-size: .69rem;
  line-height: 1.55;
  color: var(--color-ink-500);
}

.step-arrow {
  position: absolute;
  top: 50%;
  right: -1.2rem;
  color: var(--color-ink-400);
}

.tiers-band {
  background: linear-gradient(90deg, #fbf8f5 0%, #fff 49%, #fbf7f3 100%);
}

.tiers-inner {
  position: relative;
}

.tiers-note {
  transform: rotate(-4deg);
  padding-right: 1rem;
  font-size: 1.5rem;
  text-align: right;
}

.tiers-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
  margin-top: 1.6rem;
}

.tier-card {
  display: flex;
  min-height: 260px;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--color-edge-subtle);
  border-radius: 7px;
  background: rgba(255, 255, 255, .92);
}

.tier-card.featured {
  border-color: #8877b2;
  box-shadow: 0 8px 24px rgba(68, 53, 96, .08);
}

.popular {
  padding: .28rem 1rem;
  color: white;
  background: linear-gradient(90deg, #8f82b3, #77669f);
  font-size: .63rem;
  font-weight: 700;
  letter-spacing: .09em;
  text-align: center;
  text-transform: uppercase;
}

.tier-body {
  flex: 1;
  padding: 22px 24px 18px;
}

.tier-title-row {
  display: flex;
  align-items: center;
  gap: .8rem;
}

.tier-icon {
  display: grid;
  width: 50px;
  height: 50px;
  place-items: center;
  border-radius: 50%;
  background: #f3eae4;
}

.featured .tier-icon {
  background: var(--lavender-soft);
}

.tier-title-row h3 {
  font-family: var(--font-body);
  font-size: 1.25rem;
  font-weight: 500;
}

.tier-label {
  margin-left: auto;
  padding: .28rem .85rem;
  border-radius: 999px;
  background: #eee6e0;
  color: var(--color-ink-500);
  font-size: .62rem;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
}

.featured .tier-label {
  background: #e8e2f2;
  color: #665785;
}

.tier-threshold {
  margin-top: .8rem;
  padding-left: 4.05rem;
  color: var(--color-ink-900);
  font-size: 1rem;
  line-height: 1.45;
}

.tier-threshold strong {
  display: block;
  font-size: .88rem;
  font-weight: 600;
}

.tier-description {
  max-width: 26ch;
  margin: .7rem auto 0;
  color: var(--color-ink-500);
  font-size: .72rem;
  line-height: 1.5;
  text-align: center;
}

.tier-footer {
  padding: .7rem 1rem;
  color: #6d5d93;
  background: #f1ece8;
  font-size: .76rem;
  text-align: center;
}

.featured .tier-footer {
  background: #eae5f4;
}

.benefits-section {
  padding-bottom: 28px;
}

.section-heading-row.compact {
  align-items: center;
}

.section-heading-row.compact > p {
  margin: 0;
  font-size: .74rem;
}

.benefits-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  margin-top: 1.5rem;
}

.benefits-grid li {
  padding-right: 1.25rem;
}

.benefits-grid li:not(:first-child) {
  padding-left: 1.5rem;
  border-left: 1px solid var(--color-edge);
}

.benefits-grid .round-icon {
  width: 48px;
  height: 48px;
}

.faq-section {
  display: grid;
  grid-template-columns: .72fr 1.55fr;
  gap: 3rem;
  margin-bottom: 0;
  padding: 26px;
  border-radius: 7px;
  background: linear-gradient(110deg, #fbf8f5, #f8f3ef);
}

.faq-intro {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.faq-link {
  display: inline-flex;
  align-items: center;
  gap: 1rem;
  min-height: 44px;
  margin-top: auto;
  padding: 0 1.25rem;
  border: 1px solid var(--color-ink-700);
  border-radius: 4px;
  color: var(--color-ink-900);
  font-size: .75rem;
  font-weight: 500;
}

.faq-list {
  display: grid;
  gap: .45rem;
}

.faq-list article {
  border: 1px solid var(--color-edge-subtle);
  border-radius: 4px;
  background: rgba(255, 255, 255, .94);
}

.faq-list button {
  display: flex;
  width: 100%;
  min-height: 40px;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: .55rem 1rem;
  color: var(--color-ink-900);
  font-size: .73rem;
  font-weight: 600;
  text-align: left;
}

.faq-list article > p {
  padding: 0 1rem .65rem;
  color: var(--color-ink-500);
  font-size: .68rem;
}

.closing-cta {
  position: relative;
  display: grid;
  grid-template-columns: minmax(280px, 37%) 1fr;
  min-height: 220px;
  margin-top: 38px;
  overflow: hidden;
  border-block: 1px solid var(--color-edge-subtle);
  background: #fbf7f3;
}

.closing-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.closing-copy {
  align-self: center;
  max-width: 710px;
  padding: 34px 48px;
}

.closing-actions {
  display: flex;
  align-items: center;
  gap: 2.5rem;
  margin-top: 1.1rem;
}

.closing-actions ul {
  padding-left: 2.5rem;
  border-left: 1px solid var(--color-edge);
  font-size: .69rem;
  line-height: 1.8;
}

.closing-note {
  position: absolute;
  right: 5%;
  top: 54px;
  transform: rotate(-4deg);
  font-size: 1.35rem;
  text-align: right;
}

@media (max-width: 1100px) {
  .hero-copy { width: 53%; }
  .hero-note,
  .hero-side-note,
  .closing-note { display: none; }
  .closing-copy { padding-right: 32px; }
}

@media (max-width: 900px) {
  .section-space { padding-block: 40px; }
  .hero-section,
  .hero-content { min-height: 460px; }
  .hero-copy { width: 54%; padding-top: 48px; }
  .hero-copy h1 { font-size: 3.2rem; }
  .steps-grid { grid-template-columns: repeat(3, 1fr); }
  .steps-grid > li { min-height: 205px; }
  .step-arrow { display: none; }
  .tiers-grid { gap: .8rem; }
  .tier-body { padding-inline: 16px; }
  .tier-threshold { padding-left: 0; text-align: center; }
  .benefits-grid { grid-template-columns: repeat(2, 1fr); gap: 1.4rem 0; }
  .benefits-grid li:nth-child(3) { padding-left: 0; border-left: 0; }
  .closing-actions { gap: 1.2rem; }
  .closing-actions ul { padding-left: 1.2rem; }
}

@media (max-width: 767px) {
  .wholesale-container { width: min(100% - 2rem, 1280px); }
  .section-heading-row { align-items: flex-start; }
  .section-link,
  .tiers-note,
  .section-heading-row.compact > p { display: none; }
  .hero-section { padding-top: 280px; }
  .hero-section,
  .hero-content { min-height: 0; }
  .hero-photo { height: 280px; background: #f9f4ee; }
  .hero-photo img { object-position: 67% center; }
  .hero-content { width: 100%; }
  .hero-copy { width: min(100% - 2rem, 34rem); margin-inline: auto; padding-block: 36px 42px; }
  .hero-copy h1 { font-size: clamp(2.8rem, 14vw, 4rem); }
  .hero-intro { font-size: .9rem; }
  .hero-checks { display: grid; }
  .hero-actions { display: grid; }
  .hero-actions > * { width: 100%; }
  .promise-grid { grid-template-columns: 1fr; padding-block: 8px; }
  .promise-grid li { padding-block: 12px; }
  .promise-grid li:not(:first-child) { padding-left: 0; border-top: 1px solid var(--color-edge); border-left: 0; }
  .steps-grid { grid-template-columns: repeat(2, 1fr); gap: .75rem; }
  .steps-grid > li { min-height: 220px; }
  .tiers-grid { grid-template-columns: 1fr; }
  .tier-card { min-height: 0; }
  .tier-description { max-width: 34ch; }
  .benefits-grid { grid-template-columns: 1fr; }
  .benefits-grid li,
  .benefits-grid li:not(:first-child) { padding: 0; border: 0; }
  .faq-section { grid-template-columns: 1fr; gap: 1.5rem; padding: 22px; }
  .faq-link { margin-top: 1.2rem; }
  .closing-cta { grid-template-columns: 1fr; }
  .closing-photo { height: 220px; }
  .closing-copy { padding: 32px 1rem 38px; }
  .closing-actions { align-items: stretch; flex-direction: column; }
  .closing-actions ul { padding-top: 1rem; padding-left: 0; border-top: 1px solid var(--color-edge); border-left: 0; }
}

@media (max-width: 420px) {
  .steps-grid { grid-template-columns: 1fr; }
  .steps-grid > li { min-height: 0; }
  .tier-title-row { flex-wrap: wrap; }
}
</style>
