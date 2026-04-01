import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type UiMessage = {
  id: number
  type: 'error' | 'info' | 'success'
  text: string
}

type ConfirmState = {
  title: string
  message: string
  confirmText: string
  cancelText: string
  resolve: (confirmed: boolean) => void
}

export const useUiStore = defineStore('ui', () => {
  const messages = ref<UiMessage[]>([])
  const confirmState = ref<ConfirmState | null>(null)

  const hasMessages = computed(() => messages.value.length > 0)

  function pushMessage(type: UiMessage['type'], text: string) {
    const message: UiMessage = { id: Date.now() + Math.floor(Math.random() * 1000), type, text }
    messages.value.unshift(message)
  }

  function dismissMessage(id: number) {
    messages.value = messages.value.filter((m) => m.id !== id)
  }

  function clearMessages() {
    messages.value = []
  }

  function requestConfirm(options: Omit<ConfirmState, 'resolve'>): Promise<boolean> {
    return new Promise((resolve) => {
      confirmState.value = { ...options, resolve }
    })
  }

  function resolveConfirm(confirmed: boolean) {
    if (!confirmState.value) {
      return
    }
    confirmState.value.resolve(confirmed)
    confirmState.value = null
  }

  return {
    messages,
    hasMessages,
    confirmState,
    pushMessage,
    dismissMessage,
    clearMessages,
    requestConfirm,
    resolveConfirm,
  }
})
