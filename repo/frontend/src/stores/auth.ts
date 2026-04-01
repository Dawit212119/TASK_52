import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { fetchMe, login, logout } from '../api/auth'
import { ApiClientError, setAuthToken } from '../api/http'
import type { AuthUser } from '../types/auth'

const TOKEN_KEY = 'vetops.auth.token'

function getStorage(): Storage | null {
  if (typeof globalThis === 'undefined') {
    return null
  }

  const maybeStorage = (globalThis as { sessionStorage?: Storage }).sessionStorage
  return maybeStorage ?? null
}

const storage = getStorage()

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(storage?.getItem(TOKEN_KEY) ?? '')
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)
  const captchaRequired = ref(false)
  const sessionExpired = ref(false)

  const isAuthenticated = computed(() => token.value !== '' && user.value !== null)

  function persistToken(nextToken: string) {
    token.value = nextToken
    setAuthToken(nextToken)
    if (nextToken === '') {
      storage?.removeItem(TOKEN_KEY)
      return
    }
    storage?.setItem(TOKEN_KEY, nextToken)
  }

  async function hydrate(): Promise<void> {
    if (token.value === '') {
      return
    }

    setAuthToken(token.value)
    try {
      const me = await fetchMe()
      user.value = me.user
      sessionExpired.value = false
    } catch {
      clearSession()
    }
  }

  async function signIn(username: string, password: string, captchaToken?: string): Promise<void> {
    loading.value = true
    try {
      const result = await login({ username, password, captcha_token: captchaToken })
      persistToken(result.token)
      captchaRequired.value = result.security.captcha_required
      const me = await fetchMe()
      user.value = me.user
      sessionExpired.value = false
      captchaRequired.value = false
    } catch (error) {
      if (error instanceof ApiClientError && error.code === 'CAPTCHA_REQUIRED') {
        captchaRequired.value = true
      }
      throw error
    } finally {
      loading.value = false
    }
  }

  async function signOut(): Promise<void> {
    try {
      if (token.value !== '') {
        await logout()
      }
    } finally {
      clearSession()
    }
  }

  function clearSession() {
    user.value = null
    persistToken('')
  }

  function markSessionExpired() {
    sessionExpired.value = true
    clearSession()
  }

  function hasPermission(permission: string): boolean {
    return user.value?.permissions.includes(permission) ?? false
  }

  function hasAnyRole(roles: string[]): boolean {
    if (roles.length === 0) {
      return true
    }
    const roleSet = new Set(user.value?.roles ?? [])
    return roles.some((role) => roleSet.has(role as never))
  }

  return {
    token,
    user,
    loading,
    captchaRequired,
    sessionExpired,
    isAuthenticated,
    hydrate,
    signIn,
    signOut,
    markSessionExpired,
    hasPermission,
    hasAnyRole,
  }
})
