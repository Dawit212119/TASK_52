<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { menuItems } from '../router/menu'
import { useAuthStore } from '../stores/auth'
import { useUiStore } from '../stores/ui'
import { useInactivityTimeout } from '../composables/useInactivityTimeout'

useInactivityTimeout()

const authStore = useAuthStore()
const uiStore = useUiStore()
const router = useRouter()

const visibleMenuItems = computed(() => {
  const userRoles = authStore.user?.roles ?? []
  return menuItems.filter(
    (item) =>
      item.permissions.every((permission) => authStore.hasPermission(permission)) &&
      authStore.hasAnyRole(item.roles.length > 0 ? item.roles : userRoles),
  )
})

async function handleLogout() {
  const confirmed = await uiStore.requestConfirm({
    title: 'Sign out',
    message: 'Do you want to sign out from VetOps?',
    confirmText: 'Sign out',
    cancelText: 'Cancel',
  })

  if (!confirmed) {
    return
  }

  await authStore.signOut()
  await router.push('/login')
}
</script>

<template>
  <div class="app-layout">
    <aside class="sidebar">
      <h1>VetOps</h1>
      <p class="sidebar-user">{{ authStore.user?.display_name }}</p>
      <nav>
        <RouterLink v-for="item in visibleMenuItems" :key="item.path" :to="item.path" class="nav-link">
          {{ item.label }}
        </RouterLink>
      </nav>
      <button class="btn-subtle" @click="handleLogout">Sign Out</button>
    </aside>
    <main class="content-area">
      <RouterView />
    </main>
  </div>
</template>
