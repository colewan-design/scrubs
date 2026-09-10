/**
 * Guards the account pages.
 *
 * The session is resolved once in the default layout, before any page runs, so
 * by the time this fires the store is already truthful about who is signed in —
 * no second /auth/me round trip, and no flash of the signed-out state.
 *
 * The attempted path is carried through as ?redirect so signing in returns the
 * customer to where they were going rather than dumping them on the account
 * home. login.vue reads it back.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()

  // Middleware runs before the layout, so on a hard refresh this is the first
  // thing that needs the session. ensureSession() is a no-op once resolved.
  await auth.ensureSession()

  if (auth.isAuthenticated) return

  return navigateTo({
    path: '/account/login',
    query: to.fullPath === '/account' ? undefined : { redirect: to.fullPath },
  })
})
