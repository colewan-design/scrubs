/**
 * The public half of /wholesale — thresholds, not prices.
 *
 * The header's announcement bar and the home page both state the wholesale
 * minimum and the free-shipping threshold. Both are admin-editable settings
 * (Settings::DEFAULTS), so hardcoding "$600" in a template means a deployment
 * to correct a number the client can already change in admin — and means the
 * header can disagree with the cart.
 *
 * One shared useAsyncData key, so the header, the home page and anything else
 * that asks resolve to a single request per render rather than one each.
 *
 * Nothing here is gated: the thresholds are the pitch and the API returns them
 * to guests. The tier PRICES in the same payload are omitted server-side for
 * anyone not signed in (§3), so this is safe to render into a guest's page.
 */
import type { Money } from '~/composables/useApi'

export interface WholesaleTierSummary {
  name: string
  slug: string
  description: string | null
  min_subtotal: Money
  min_qty: number | null
  qualify_mode: string
  locked: boolean
  unit_price?: Money | null
  discount_percent?: number | null
}

export interface WholesaleSummary {
  tiers: WholesaleTierSummary[]
  wholesale_unlocked: boolean
  min_order: Money
  free_shipping_threshold: Money
}

export async function useWholesaleSummary() {
  const api = useApi()

  const { data } = await useAsyncData('wholesale-summary', () =>
    api.get<WholesaleSummary>('/wholesale'),
  )

  return {
    summary: data,
    tiers: computed(() => data.value?.tiers ?? []),
    /** Rendered as "CAD $200.00" — currency stated, per the redesign. */
    minOrder: computed(() => data.value?.min_order ?? null),
    freeShipping: computed(() => data.value?.free_shipping_threshold ?? null),
  }
}
