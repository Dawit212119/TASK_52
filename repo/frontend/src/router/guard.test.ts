import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '../stores/auth'
import type { RouteLocationNormalized } from 'vue-router'

const StubComponent = { template: '<div/>' }

function makeTestRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/login',
        name: 'login',
        component: StubComponent,
      },
      {
        path: '/unauthorized',
        name: 'unauthorized',
        component: StubComponent,
        meta: { requiresAuth: true },
      },
      {
        path: '/workspace/clinic-manager',
        name: 'workspace-clinic-manager',
        component: StubComponent,
        meta: {
          requiresAuth: true,
          permissions: ['analytics.read'],
          roles: ['clinic_manager', 'system_admin'],
        },
      },
      {
        path: '/workspace/system-admin',
        name: 'workspace-system-admin',
        component: StubComponent,
        meta: {
          requiresAuth: true,
          permissions: ['audit.read'],
          roles: ['system_admin'],
        },
      },
    ],
  })

  router.beforeEach(async (to: RouteLocationNormalized) => {
    const authStore = useAuthStore()

    if (authStore.user === null && authStore.token) {
      await authStore.hydrate()
    }

    const requiresAuth = Boolean(to.meta.requiresAuth)
    if (requiresAuth && !authStore.isAuthenticated) {
      return { path: '/login', query: { redirect: to.fullPath } }
    }

    if (requiresAuth) {
      const permissions = (to.meta.permissions as string[] | undefined) ?? []
      const roles = (to.meta.roles as string[] | undefined) ?? []
      const permissionAllowed =
        permissions.length === 0 || permissions.every((p) => authStore.hasPermission(p))
      const roleAllowed = roles.length === 0 || authStore.hasAnyRole(roles)
      if (!(permissionAllowed && roleAllowed)) {
        return '/unauthorized'
      }
    }

    return true
  })

  return router
}

function createLocalStorageMock(): Storage {
  const data = new Map<string, string>()
  return {
    get length() { return data.size },
    clear() { data.clear() },
    getItem(key: string) { return data.has(key) ? data.get(key)! : null },
    key(index: number) { return [...data.keys()][index] ?? null },
    removeItem(key: string) { data.delete(key) },
    setItem(key: string, value: string) { data.set(key, value) },
  }
}

describe('router navigation guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    Object.defineProperty(globalThis, 'localStorage', {
      value: createLocalStorageMock(),
      configurable: true,
      writable: true,
    })
  })

  it('redirects unauthenticated user to /login', async () => {
    const router = makeTestRouter()
    await router.push('/workspace/clinic-manager')
    expect(router.currentRoute.value.path).toBe('/login')
  })

  it('redirects authenticated user with wrong role to /unauthorized', async () => {
    const router = makeTestRouter()
    const authStore = useAuthStore()

    // Set up a clinic_manager (wrong role for system-admin route)
    authStore.$patch({
      token: 'test-token',
      user: {
        id: 1,
        username: 'clinic.manager',
        display_name: 'Clinic Manager',
        roles: ['clinic_manager'],
        permissions: ['analytics.read'],
        facility_ids: [1],
      },
    })

    await router.push('/workspace/system-admin')
    expect(router.currentRoute.value.path).toBe('/unauthorized')
  })

  it('allows authenticated user with correct role to access the route', async () => {
    const router = makeTestRouter()
    const authStore = useAuthStore()

    authStore.$patch({
      token: 'test-token',
      user: {
        id: 2,
        username: 'clinic.manager',
        display_name: 'Clinic Manager',
        roles: ['clinic_manager'],
        permissions: ['analytics.read'],
        facility_ids: [1],
      },
    })

    await router.push('/workspace/clinic-manager')
    expect(router.currentRoute.value.path).toBe('/workspace/clinic-manager')
  })
})
