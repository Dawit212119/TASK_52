import { onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

export function useInactivityTimeout(timeoutMs = 15 * 60 * 1000) {
  const authStore = useAuthStore()
  const router = useRouter()
  let timer: ReturnType<typeof setTimeout> | null = null

  function reset() {
    if (timer !== null) clearTimeout(timer)
    timer = setTimeout(async () => {
      authStore.markSessionExpired()
      await router.push('/login')
    }, timeoutMs)
  }

  const events = ['mousemove', 'keydown', 'pointerdown', 'scroll'] as const

  onMounted(() => {
    events.forEach((e) => window.addEventListener(e, reset, { passive: true }))
    reset()
  })

  onUnmounted(() => {
    events.forEach((e) => window.removeEventListener(e, reset))
    if (timer !== null) clearTimeout(timer)
  })
}
