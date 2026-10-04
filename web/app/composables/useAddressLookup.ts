import type { AddressAutofillSuggestion } from '@mapbox/search-js-core'
import type { AddressSuggestion, ResolvedAddress } from '~/composables/useApi'

/**
 * Address suggestions as the customer types.
 *
 * Which service answers is the server's decision (`/address/lookup`), and the
 * two it can name work differently:
 *
 *   mapbox — asked straight from the browser through Mapbox's own SDK, with a
 *            public token the API hands over. That is how Mapbox licenses
 *            address autofill, and its suggestions arrive already broken into
 *            form fields.
 *
 *   google — asked through our API, which holds the key. A suggestion is only
 *            a label; picking one is a second request that fetches its fields.
 *
 * Both sit behind the same small `Provider` shape, so nothing outside this
 * file knows or cares which one is in force. What this composable adds on top
 * keeps the conversation cheap and in order:
 *
 *  - It waits for a pause in typing before asking. Every request is metered,
 *    and a suggestion for "55" is of no use to someone on their way to "5580".
 *  - It drops answers that arrive out of order, so a slow reply to "5580 B"
 *    cannot overwrite the list for "5580 Belm".
 *  - It carries one session token from the first keystroke to the pick, which
 *    is how both providers price a lookup as one thing rather than a dozen.
 *
 * Nothing here can fail in a way the customer sees. No provider configured, a
 * rate limit, an outage: each is an empty list, and the form is typed by hand.
 */

const MIN_CHARS = 3
/** The API refuses anything longer, and no street line needs it. */
const MAX_CHARS = 120
const PAUSE_MS = 250

interface Provider {
  /** The credit each service requires beside its results. */
  attribution: string
  suggest: (q: string, session: string) => Promise<AddressSuggestion[]>
  /** `typed` is what was in the field when the customer picked. */
  resolve: (picked: AddressSuggestion, session: string, typed: string) => Promise<ResolvedAddress | null>
}

interface LookupConfig {
  provider: 'mapbox' | 'google' | null
  mapbox: { token: string } | null
}

type Api = ReturnType<typeof useApi>

/**
 * Asked for once per page load, on the first keystroke that needs it, and
 * shared by every address field on the page. Null means "no lookup": nothing
 * is configured, or finding out failed — and neither is retried per keystroke.
 */
let provider: Promise<Provider | null> | null = null

function loadProvider(api: Api): Promise<Provider | null> {
  provider ??= api.get<LookupConfig>('/address/lookup')
    .then((config) => {
      if (config.provider === 'mapbox' && config.mapbox) return mapboxProvider(config.mapbox.token)
      if (config.provider === 'google') return googleProvider(api)

      return null
    })
    .catch(() => null)

  return provider
}

// ----------------------------------------------------------------- google

function googleProvider(api: Api): Provider {
  return {
    attribution: 'Google Maps',

    async suggest(q, session) {
      const response = await api.get<{ suggestions: AddressSuggestion[] }>('/address/suggest', { q, session })

      return response.suggestions
    },

    async resolve(picked, session, typed) {
      const response = await api.get<{ address: ResolvedAddress | null }>('/address/resolve', {
        id: picked.id,
        session,
        // The API falls back on this house number when Google knows the
        // street but not the house.
        typed: typed.trim().slice(0, MAX_CHARS) || undefined,
      })

      return response.address
    },
  }
}

// ----------------------------------------------------------------- mapbox

const PROVINCES: Record<string, string> = {
  'AB': 'alberta', 'BC': 'british columbia', 'MB': 'manitoba', 'NB': 'new brunswick',
  'NL': 'newfoundland and labrador', 'NS': 'nova scotia', 'NT': 'northwest territories',
  'NU': 'nunavut', 'ON': 'ontario', 'PE': 'prince edward island', 'QC': 'quebec',
  'SK': 'saskatchewan', 'YT': 'yukon',
}

/** "ON", "Ontario" or "Québec" — whichever Mapbox sends — as the form's two-letter code. */
function provinceCode(value?: string): string | null {
  if (!value) return null

  const code = value.trim().toUpperCase()
  if (code in PROVINCES) return code

  const name = value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')

  return Object.keys(PROVINCES).find((c) => PROVINCES[c] === name) ?? null
}

/**
 * A complete Canadian postal code, or nothing. Half a postal code in the box
 * looks finished and is not, so anything short of six characters is dropped
 * and the customer is left to type the whole thing.
 */
function postalCode(value?: string): string | null {
  const code = (value ?? '').toUpperCase().replace(/\s+/g, '')

  return /^[A-Z]\d[A-Z]\d[A-Z]\d$/.test(code) ? `${code.slice(0, 3)} ${code.slice(3)}` : null
}

function fromMapbox(found: AddressAutofillSuggestion): ResolvedAddress | null {
  const country = (found.country_code ?? found.metadata?.iso_3166_1 ?? '').toLowerCase()

  // Canada only — the store ships domestically. And a suggestion with no
  // street line is not something a parcel can be sent to.
  if (country !== 'ca' || !found.address_line1) return null

  // The live service sends the province twice: spelled out in the documented
  // field ("Ontario"), and as a code in one the SDK's types leave out ("ON").
  // The code is taken when it is there; the name is what is promised.
  const region = (found as { region_code?: string }).region_code

  return {
    line1: found.address_line1,
    line2: found.address_line2 || null,
    city: found.address_level2 || null,
    province: provinceCode(region) ?? provinceCode(found.address_level1),
    postal_code: postalCode(found.postcode),
  }
}

async function mapboxProvider(token: string): Promise<Provider> {
  // Loaded on demand: it is only needed by someone typing an address, and
  // only when Mapbox is the provider.
  const { AddressAutofillCore } = await import('@mapbox/search-js-core')

  const autofill = new AddressAutofillCore({
    accessToken: token,
    country: 'ca',
    language: 'en',
    limit: 5,
    // Addresses only. A bare street has no house number to fill in, and
    // picking one would wipe out the number the customer typed.
    streets: false,
  })

  /** Mapbox's own objects for the list on screen, which `retrieve` needs back. */
  let listed = new Map<string, AddressAutofillSuggestion>()

  return {
    attribution: 'Powered by Mapbox',

    async suggest(q, session) {
      const response = await autofill.suggest(q, { sessionToken: session })

      listed = new Map()

      return response.suggestions.flatMap((found, i) => {
        const address = fromMapbox(found)
        if (!address) return []

        const id = found.action?.id ?? found.mapbox_id ?? String(i)
        listed.set(id, found)

        return [{
          id,
          primary: found.address_line1!,
          // "Niagara Falls, ON L2H 2W9" — built from the parts that will be
          // filled in, so the row shows exactly what picking it does.
          secondary: [address.city, [address.province, address.postal_code].filter(Boolean).join(' ')]
            .filter(Boolean)
            .join(', '),
          address,
        }]
      })
    },

    async resolve(picked, session) {
      const found = listed.get(picked.id)

      // Tells Mapbox the customer chose, which is what closes the session it
      // bills by. Not waited for: the reply adds only coordinates, which the
      // form has no use for and Mapbox's terms say not to keep.
      if (found && autofill.canRetrieve(found)) {
        autofill.retrieve(found, { sessionToken: session }).catch(() => {})
      }

      return picked.address ?? null
    },
  }
}

// -------------------------------------------------------------------------

function newSession(): string {
  // randomUUID needs a secure context, which a phone testing against a LAN
  // address over http is not.
  return globalThis.crypto?.randomUUID?.()
    ?? Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('')
}

export function useAddressLookup() {
  const api = useApi()

  const suggestions = ref<AddressSuggestion[]>([])
  /** Whose results these are. Empty until there are any. */
  const attribution = ref('')

  let session: string | null = null
  let timer: ReturnType<typeof setTimeout> | undefined
  /** The most recent thing asked for. Any reply to an older one is discarded. */
  let latest = 0

  /** Abandon whatever is waiting to be asked or answered. */
  function cancel() {
    clearTimeout(timer)
    latest++
  }

  /** Stop: forget the list, and anything still on its way. */
  function clear() {
    cancel()
    suggestions.value = []
  }

  function search(text: string) {
    const q = text.trim().slice(0, MAX_CHARS)

    if (q.length < MIN_CHARS) {
      clear()

      return
    }

    // The list on screen stays until its replacement arrives. Emptying it on
    // every keystroke would make it blink shut and open again as they type.
    cancel()

    const ticket = latest

    timer = setTimeout(async () => {
      try {
        const source = await loadProvider(api)
        if (ticket !== latest) return

        if (!source) {
          suggestions.value = []

          return
        }

        session ??= newSession()

        const found = await source.suggest(q, session)

        if (ticket === latest) {
          attribution.value = source.attribution
          suggestions.value = found
        }
      } catch {
        if (ticket === latest) suggestions.value = []
      }
    }, PAUSE_MS)
  }

  /** Turn the picked suggestion into form fields. */
  async function resolve(picked: AddressSuggestion, typed: string): Promise<ResolvedAddress | null> {
    clear()

    // The pick ends the session whether or not it succeeds; the next address
    // typed starts a new one.
    const used = session ?? newSession()
    session = null

    try {
      const source = await loadProvider(api)

      return source ? await source.resolve(picked, used, typed) : null
    } catch {
      return null
    }
  }

  onScopeDispose(clear)

  return { suggestions, attribution, search, resolve, clear }
}
