import { onMounted, onUnmounted } from 'vue'
import { subscribeApiErrors, toActionableMessage } from '../api/http'
import { useAuthStore } from '../stores/auth'
import { useUiStore } from '../stores/ui'

export function useApiErrorBridge() {
  const authStore = useAuthStore()
  const uiStore = useUiStore()

  let unsubscribe: (() => void) | null = null

  onMounted(() => {
    unsubscribe = subscribeApiErrors((error) => {
      if (error.code === 'SESSION_EXPIRED' || error.code === 'UNAUTHENTICATED') {
        authStore.markSessionExpired()
      }

      uiStore.pushMessage('error', toActionableMessage(error))
    })
  })

  onUnmounted(() => {
    unsubscribe?.()
  })
}
