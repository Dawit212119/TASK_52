import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'

function createLocalStorageMock(): Storage {
  const data = new Map<string, string>()
  return {
    get length() {
      return data.size
    },
    clear() {
      data.clear()
    },
    getItem(key: string) {
      return data.has(key) ? data.get(key)! : null
    },
    key(index: number) {
      return [...data.keys()][index] ?? null
    },
    removeItem(key: string) {
      data.delete(key)
    },
    setItem(key: string, value: string) {
      data.set(key, value)
    },
  }
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    Object.defineProperty(globalThis, 'sessionStorage', {
      value: createLocalStorageMock(),
      configurable: true,
      writable: true,
    })
  })

  it('signs in and hydrates authenticated user', async () => {
    const fetchMock = vi.fn()
    fetchMock
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          token: 'test-token',
          expires_in_seconds: 900,
          user: {
            id: 1,
            username: 'clinic.manager',
            display_name: 'Clinic Manager',
            roles: ['clinic_manager'],
            facility_ids: [1],
          },
          security: { captcha_required: false },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          user: {
            id: 1,
            username: 'clinic.manager',
            display_name: 'Clinic Manager',
            roles: ['clinic_manager'],
            permissions: ['analytics.read'],
            facility_ids: [1],
          },
          request_id: 'req-1',
        }),
      })

    vi.stubGlobal('fetch', fetchMock)

    const store = useAuthStore()
    await store.signIn('clinic.manager', 'VetOpsSecure123')

    expect(store.isAuthenticated).toBe(true)
    expect(store.user?.username).toBe('clinic.manager')
    expect(store.captchaRequired).toBe(false)
  })

  it('enables captcha state when backend requires captcha', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({
        error: {
          code: 'CAPTCHA_REQUIRED',
          message: 'CAPTCHA verification is required.',
          details: [],
        },
        request_id: 'req-2',
      }),
    })

    vi.stubGlobal('fetch', fetchMock)

    const store = useAuthStore()
    await expect(store.signIn('clinic.manager', 'VetOpsSecure123')).rejects.toBeTruthy()
    expect(store.captchaRequired).toBe(true)
    expect(store.isAuthenticated).toBe(false)
  })

  it('clears token and user on signOut', async () => {
    const fetchMock = vi.fn()
    // login
    fetchMock
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          token: 'existing-token',
          expires_in_seconds: 900,
          user: {
            id: 1,
            username: 'clinic.manager',
            display_name: 'Clinic Manager',
            roles: ['clinic_manager'],
            facility_ids: [1],
          },
          security: { captcha_required: false },
        }),
      })
      // fetchMe
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        json: async () => ({
          user: {
            id: 1,
            username: 'clinic.manager',
            display_name: 'Clinic Manager',
            roles: ['clinic_manager'],
            permissions: ['analytics.read'],
            facility_ids: [1],
          },
          request_id: 'req-1',
        }),
      })
      // logout returns 204
      .mockResolvedValueOnce({ ok: true, status: 204, json: async () => ({}) })

    vi.stubGlobal('fetch', fetchMock)

    const store = useAuthStore()
    await store.signIn('clinic.manager', 'VetOpsSecure123')
    expect(store.isAuthenticated).toBe(true)

    await store.signOut()
    expect(store.isAuthenticated).toBe(false)
    expect(store.token).toBe('')
    expect(globalThis.sessionStorage.getItem('vetops.auth.token')).toBeNull()
  })
})
