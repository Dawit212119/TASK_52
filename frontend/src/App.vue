<script setup lang="ts">
import { RouterView } from 'vue-router'
import ConfirmationDialog from './components/ui/ConfirmationDialog.vue'
import GlobalMessages from './components/ui/GlobalMessages.vue'
import { useApiErrorBridge } from './composables/useApiErrorBridge'

useApiErrorBridge()
</script>

<template>
  <RouterView />
  <GlobalMessages />
  <ConfirmationDialog />
</template>
