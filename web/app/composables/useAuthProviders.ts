import type { AuthProvider } from '~/composables/useApi'

/**
 * Which social sign-in buttons to render (§3).
 *
 * The API answers this rather than the storefront hardcoding the button, so an
 * unconfigured provider is simply absent rather than a button that apologises
 * after the click. Google is the only one — Apple was dropped on 2026-09-04.
 *
 * The shared useAsyncData key means the login page, the register page and the
 * unlock dialog all share one request per render.
 */
export function useAuthProviders() {
  const api = useApi()
  const config = useRuntimeConfig()

  const { data } = useAsyncData('auth-providers', () =>
    api.get<{ providers: AuthProvider[] }>('/auth/providers'),
  )

  const providers = computed<AuthProvider[]>(() => data.value?.providers ?? [])
  const hasAny = computed(() => providers.value.length > 0)

  function has(provider: AuthProvider) {
    return providers.value.includes(provider)
  }

  /**
   * A full page navigation, not fetch: the provider needs to own the window so
   * it can show its own consent screen and set its own cookies.
   *
   * `redirect` is where to land afterwards (checkout sends /checkout). The API
   * remembers it across the round trip and ignores anything off-site.
   */
  function signInWith(provider: AuthProvider, redirect?: string) {
    const query = redirect ? `?${new URLSearchParams({ redirect })}` : ''
    window.location.href = `${config.public.apiBase}/api/v1/auth/${provider}/redirect${query}`
  }

  return { providers, hasAny, has, signInWith }
}
