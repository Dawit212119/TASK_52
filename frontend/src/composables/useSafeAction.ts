import { ref } from 'vue'
import { toActionableMessage } from '../api/http'
import { useUiStore } from '../stores/ui'

export function useSafeAction() {
  const busy = ref(false)
  const uiStore = useUiStore()

  async function run<T>(action: () => Promise<T>, successMessage?: string): Promise<T | null> {
    busy.value = true
    try {
      const result = await action()
      if (successMessage) {
        uiStore.pushMessage('success', successMessage)
      }
      return result
    } catch (error) {
      uiStore.pushMessage('error', toActionableMessage(error))
      return null
    } finally {
      busy.value = false
    }
  }

  return {
    busy,
    run,
  }
}
