<script setup lang="ts">
const props = defineProps<{
  status: 'ok' | 'warning' | 'error' | 'info'
  label: string
}>()

const className = `badge-${props.status}`
</script>

<template>
  <span class="status-badge" :class="className">{{ label }}</span>
</template>
