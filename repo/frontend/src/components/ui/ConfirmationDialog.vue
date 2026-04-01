<script setup lang="ts">
import { useUiStore } from '../../stores/ui'

const uiStore = useUiStore()
</script>

<template>
  <div v-if="uiStore.confirmState" class="confirm-overlay" role="dialog" aria-modal="true">
    <div class="confirm-card">
      <h3>{{ uiStore.confirmState.title }}</h3>
      <p>{{ uiStore.confirmState.message }}</p>
      <div class="confirm-actions">
        <button class="btn-subtle" @click="uiStore.resolveConfirm(false)">
          {{ uiStore.confirmState.cancelText }}
        </button>
        <button class="btn-danger" @click="uiStore.resolveConfirm(true)">
          {{ uiStore.confirmState.confirmText }}
        </button>
      </div>
    </div>
  </div>
</template>
