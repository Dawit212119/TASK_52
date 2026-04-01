<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FormValidationSummary from '../components/ui/FormValidationSummary.vue'
import { toActionableMessage } from '../api/http'
import { firstAllowedPath } from '../router/menu'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const captchaToken = ref('')
const errors = ref<string[]>([])

const showSessionExpiredNotice = computed(() => authStore.sessionExpired)

function validateForm(): boolean {
  const nextErrors: string[] = []

  if (username.value.trim() === '') {
    nextErrors.push('Username is required.')
  }

  if (password.value.length < 12) {
    nextErrors.push('Password must be at least 12 characters.')
  }

  if (authStore.captchaRequired && captchaToken.value.trim() === '') {
    nextErrors.push('CAPTCHA token is required after repeated failed logins.')
  }

  errors.value = nextErrors
  return nextErrors.length === 0
}

async function submitLogin() {
  if (!validateForm()) {
    return
  }

  try {
    await authStore.signIn(username.value.trim(), password.value, captchaToken.value.trim() || undefined)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : ''
    const defaultPath = firstAllowedPath(authStore.user?.roles ?? [])

    if (redirect !== '' && redirect !== '/unauthorized') {
      await router.push(redirect)
      if (router.currentRoute.value.path !== '/unauthorized') {
        return
      }
    }

    await router.replace(defaultPath)
  } catch (error) {
    errors.value = [toActionableMessage(error)]
  }
}
</script>

<template>
  <main class="auth-page">
    <section class="auth-card">
      <h1>VetOps Sign In</h1>
      <p>Use your local LAN account credentials to access role-specific workspaces.</p>

      <p v-if="showSessionExpiredNotice" class="session-expired">
        Your session expired. Please sign in again to continue.
      </p>

      <FormValidationSummary :errors="errors" />

      <form class="auth-form" @submit.prevent="submitLogin">
        <label>
          Username
          <input v-model="username" type="text" autocomplete="username" />
        </label>

        <label>
          Password
          <input v-model="password" type="password" autocomplete="current-password" />
        </label>

        <label v-if="authStore.captchaRequired">
          CAPTCHA Token
          <input v-model="captchaToken" type="text" placeholder="Enter CAPTCHA token" />
        </label>

        <button :disabled="authStore.loading" type="submit">
          {{ authStore.loading ? 'Signing In...' : 'Sign In' }}
        </button>
      </form>
    </section>
  </main>
</template>
