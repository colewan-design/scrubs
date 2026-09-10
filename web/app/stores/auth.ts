import { defineStore } from 'pinia'
import type { AuthUser } from '~/composables/useApi'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)

  /**
   * Whether the session has been resolved at least once.
   *
   * Route middleware runs before any layout, so without this the auth guard
   * would see a null user on a hard refresh and bounce a signed-in customer to
   * the login page. It rides along in the Nuxt payload, so the client does not
   * repeat the request the server already made.
   */
  const ready = ref(false)

  /** Signing in is what makes wholesale pricing visible (§3). */
  const isAuthenticated = computed(() => user.value !== null)
  const wholesaleUnlocked = computed(() => isAuthenticated.value)
  const isSuspended = computed(() => user.value?.status === 'suspended')
  const needsEmailVerification = computed(
    () => isAuthenticated.value && !user.value?.email_verified,
  )

  async function fetchUser() {
    const api = useApi()
    try {
      const res = await api.get<{ user: AuthUser | null }>('/auth/me')
      user.value = res.user
    } catch {
      user.value = null
    } finally {
      ready.value = true
    }
  }

  /** Resolve the session exactly once per request, whoever asks first. */
  async function ensureSession() {
    if (!ready.value) await fetchUser()
  }

  async function login(payload: { email: string; password: string; remember?: boolean }) {
    const api = useApi()
    loading.value = true
    try {
      const res = await api.post<{ user: AuthUser }>('/auth/login', payload)
      user.value = res.user
      await useCartStore().refresh()
      return res.user
    } finally {
      loading.value = false
    }
  }

  async function register(payload: Record<string, any>) {
    const api = useApi()
    loading.value = true
    try {
      const res = await api.post<{ user: AuthUser; verification_sent: boolean }>(
        '/auth/register',
        payload,
      )
      user.value = res.user
      // The guest cart carried its token across, so the basket survives signup
      // and the wholesale price applies immediately.
      await useCartStore().refresh()
      return res
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    const api = useApi()
    try {
      await api.post('/auth/logout')
    } finally {
      user.value = null
      await useCartStore().refresh()
    }
  }

  /** Update contact details (§8). May clear verification — see the API. */
  async function updateProfile(payload: Record<string, any>) {
    const api = useApi()
    loading.value = true
    try {
      const res = await api.patch<{
        user: AuthUser
        email_changed: boolean
        verification_sent: boolean
      }>('/account/profile', payload)
      user.value = res.user
      return res
    } finally {
      loading.value = false
    }
  }

  /** Change, or for a Google-created account set, the password (§8). */
  async function updatePassword(payload: {
    current_password?: string
    password: string
    password_confirmation: string
  }) {
    const api = useApi()
    loading.value = true
    try {
      const res = await api.put<{ user: AuthUser; message: string }>('/account/password', payload)
      user.value = res.user
      return res
    } finally {
      loading.value = false
    }
  }

  async function resendVerification() {
    const api = useApi()
    return api.post<{ sent: boolean; message: string }>('/account/email/resend')
  }

  /**
   * Applied after the email-confirmation redirect lands, so the account page
   * stops asking without a second round trip to the API.
   */
  function markVerified() {
    if (user.value) user.value = { ...user.value, email_verified: true }
  }

  return {
    user,
    loading,
    ready,
    isAuthenticated,
    wholesaleUnlocked,
    isSuspended,
    needsEmailVerification,
    fetchUser,
    ensureSession,
    login,
    register,
    logout,
    updateProfile,
    updatePassword,
    resendVerification,
    markVerified,
  }
})
