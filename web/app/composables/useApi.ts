/**
 * Typed API client.
 *
 * Three things it takes care of that are easy to get wrong:
 *  - On the server during SSR it forwards the incoming cookies, so an
 *    authenticated page renders with that user's pricing rather than as a guest.
 *  - It also sends an Origin on those SSR calls. Sanctum ignores a session
 *    cookie unless it recognises the request as first-party, and it decides
 *    that from Referer/Origin alone (EnsureFrontendRequestsAreStateful::
 *    fromFrontend). Node's fetch sends neither, so without this the cookie
 *    arrives and is thrown away.
 *  - It performs Sanctum's CSRF handshake before any mutating request.
 */

/** Normalise a configured URL down to a bare scheme://host:port origin. */
function originOf(url?: string): string | null {
  if (!url) return null
  try {
    return new URL(url).origin
  } catch {
    return null
  }
}

export interface Money {
  cents: number
  formatted: string
  currency: string
}

export interface ProductCard {
  id: number
  name: string
  slug: string
  short_description: string | null
  retail_price: Money
  wholesale_locked: boolean
  wholesale_from?: Money
  image: { path: string; alt: string } | null
  colors?: Array<{ name: string; hex: string | null; slug: string }>
  in_stock?: boolean
  is_featured: boolean
  category?: { name: string; slug: string }
}

/** One rung of a product's wholesale ladder. Members only — see §3. */
export interface WholesaleTier {
  id: number
  name: string
  slug: string
  min_subtotal_cents: number | null
  min_qty: number | null
  min_subtotal: Money | null
  unit_price: Money
  saving: Money
}

export interface ProductVariant {
  id: number
  sku: string
  color_id: number
  size_id: number
  secondary_size_id: number | null
  retail_price: Money
  available: number
  in_stock: boolean
  low_stock: boolean
}

export interface ProductDetail {
  id: number
  name: string
  slug: string
  base_sku: string
  product_type: string
  has_dual_sizing: boolean
  short_description: string | null
  panels: Array<{ key: string; label: string; body: string }>
  retail_price: Money
  wholesale_locked: boolean
  wholesale_from?: Money
  wholesale_tiers?: WholesaleTier[]
  category?: { name: string; slug: string }
  size_chart?: { name: string; body: string }
  images?: Array<{ path: string; alt: string; color_slug: string | null; is_primary: boolean }>
  colors?: Array<{ id: number; name: string; hex: string | null; slug: string }>
  sizes?: Array<{ id: number; name: string; slug: string }>
  variants?: ProductVariant[]
  in_stock?: boolean
  meta: { title: string; description: string | null }
}

export interface CartQuote {
  retail_subtotal_cents: number
  subtotal_cents: number
  discount_cents: number
  total_qty: number
  tier: { id: number; name: string; slug: string } | null
  next_tier: {
    id: number
    name: string
    subtotal_gap_cents: number | null
    qty_gap: number | null
    additional_saving_cents: number | null
    subtotal_gap?: Money
    additional_saving?: Money
  } | null
  wholesale_visible: boolean
  unlock_prompt: { qualifies: boolean; saving_cents: number; saving?: Money } | null
  free_shipping: {
    threshold_cents: number
    gap_cents: number
    qualifies: boolean
    gap?: Money
    threshold?: Money
  }
  totals: { retail_subtotal: Money; subtotal: Money; discount: Money }
}

export interface CartItem {
  variant_id: number
  product_name: string
  product_slug: string
  sku: string
  variant_label: string
  /** The parts of variant_label, so the cart can label each one. */
  color: string | null
  size: string | null
  secondary_size: string | null
  image: string | null
  qty: number
  available: number
  unit_retail: Money
  unit_price: Money
  line_total: Money
  line_discount: Money
}

export interface CartResponse {
  cart_token: string | null
  item_count: number
  items: CartItem[]
  quote: CartQuote
}

// ---------------------------------------------------------------- checkout

export interface ShippingOption {
  code: string
  name: string
  cost_cents: number
  cost: Money
  carrier: string | null
  service: string | null
  delivery_estimate: string | null
  provider: string
  free_threshold_applied: boolean
}

export interface TaxLine {
  label: string
  tax_type: string
  province: string
  rate_bps: number
  taxable_base_cents: number
  amount_cents: number
  amount: Money
}

export interface CheckoutQuote {
  cart: CartQuote
  fulfillment_type: 'ship' | 'pickup'
  shipping_options: ShippingOption[]
  selected_shipping_option: string | null
  shipping: Money
  tax: { province: string | null; lines: TaxLine[]; total_cents: number; total: Money }
  grand_total: Money
  weights_complete: boolean
  pickup: { address: string; hours: string; lead_time: string } | null
  etransfer: { instructions: string } | null
}

export interface AddressInput {
  first_name: string
  last_name: string
  company?: string
  line1: string
  line2?: string
  city: string
  province: string
  postal_code: string
  country?: string
  phone?: string
}

export interface OrderAddress extends AddressInput {
  name: string
  lines: string[]
}

export interface OrderItemLine {
  product_name: string
  variant_sku: string
  /** The live product behind the frozen line. All null once it is deleted. */
  variant_id: number | null
  product_slug: string | null
  image: string | null
  variant_label: string
  qty: number
  unit_retail: Money
  unit_price: Money
  line_discount: Money
  line_total: Money
  pricing_tier_name: string | null
}

export interface Order {
  order_number: string
  status: string
  status_label: string
  payment_status: string
  fulfillment_status: string
  fulfillment_type: 'ship' | 'pickup'
  email: string
  phone: string | null
  customer_note: string | null
  is_cancellable: boolean
  pricing_tier_name: string | null
  totals: {
    subtotal: Money
    discount: Money
    shipping: Money
    tax: Money
    grand_total: Money
  }
  items?: OrderItemLine[]
  taxes?: Array<{ label: string; tax_type: string; province: string; amount: Money }>
  /** Seller's GST/HST number, frozen at placement (§6). Null when none applied. */
  tax_registration: string | null
  shipping_address?: OrderAddress | null
  billing_address?: OrderAddress | null
  shipments?: Array<{
    carrier: string | null
    service: string | null
    tracking_number: string | null
    tracking_url: string | null
    shipped_at: string | null
  }>
  timeline?: Array<{ status: string; status_label: string; note: string | null; at: string | null }>
  placed_at: string | null
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  cancelled_at: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  phone: string | null
  business_name: string | null
  city: string | null
  province: string | null
  /** active | suspended — §8's "account status", shown on the account page. */
  status: string
  email_verified: boolean
  /** False for a Google-created account, which has no password to change. */
  has_password: boolean
  auth_providers: string[]
  member_since: string | null
  wholesale_unlocked: boolean
}

/** An entry in the customer's address book (§8). */
export interface SavedAddress extends AddressInput {
  id: number
  label: string | null
  is_default_shipping: boolean
  is_default_billing: boolean
  name: string
  lines: string[]
}

/** The shape the address form posts. Everything except the server-set fields. */
export type SavedAddressInput = Omit<SavedAddress, 'id' | 'name' | 'lines'>

/** Social providers that are configured well enough to actually work (§3). */
export type AuthProvider = 'google'

let csrfReady = false

export function useApi() {
  const config = useRuntimeConfig()
  const base = config.public.apiBase

  /** Sanctum requires the XSRF cookie before any state-changing request. */
  async function ensureCsrf() {
    if (csrfReady || import.meta.server) return
    await $fetch('/sanctum/csrf-cookie', { baseURL: base, credentials: 'include' })
    csrfReady = true
  }

  async function request<T>(path: string, opts: any = {}): Promise<T> {
    const method = (opts.method || 'GET').toUpperCase()

    // Every Nuxt composable must be called BEFORE the first await. After an
    // await the Nuxt instance is no longer on the async context, which is what
    // NUXT_E1001 warns about.
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(opts.headers || {}),
    }

    // The guest cart is keyed by a token we hold in a cookie.
    const cartToken = useCookie<string | null>('bsd_cart', {
      maxAge: 60 * 60 * 24 * 30,
      sameSite: 'lax',
    })
    if (cartToken.value) headers['X-Cart-Token'] = cartToken.value

    const serverCookies = import.meta.server ? useRequestHeaders(['cookie']) : null

    // Prefer the configured site URL over the inbound Host header: this value
    // is what makes Laravel trust the session, so it must not be something a
    // caller can set. Whatever it resolves to has to appear in
    // SANCTUM_STATEFUL_DOMAINS or the session is dropped again.
    const ssrOrigin = import.meta.server
      ? originOf(config.public.siteUrl) || useRequestURL().origin
      : null

    if (method !== 'GET') {
      await ensureCsrf()
    }

    if (import.meta.server) {
      // Forward the browser's cookies so SSR renders the real session, not a guest.
      if (serverCookies?.cookie) headers.cookie = serverCookies.cookie
      // ...and identify the call as coming from the frontend, or Sanctum skips
      // StartSession entirely and $request->user() is null on every SSR render.
      if (ssrOrigin) headers.origin = ssrOrigin
    } else {
      const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1]
      if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)
    }

    return $fetch<T>(path, {
      baseURL: `${base}/api/v1`,
      credentials: 'include',
      ...opts,
      headers,
    })
  }

  return {
    get: <T>(path: string, query?: Record<string, any>) =>
      request<T>(path, { method: 'GET', query }),
    post: <T>(path: string, body?: any) => request<T>(path, { method: 'POST', body }),
    patch: <T>(path: string, body?: any) => request<T>(path, { method: 'PATCH', body }),
    del: <T>(path: string, body?: any) => request<T>(path, { method: 'DELETE', body }),
  }
}
