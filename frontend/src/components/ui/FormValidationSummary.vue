<script setup lang="ts">
defineProps<{
  errors: string[]
}>()
</script>

<template>
  <div v-if="errors.length > 0" class="validation-summary" role="alert">
    <p>Please fix the following issues:</p>
    <ul>
      <li v-for="error in errors" :key="error">{{ error }}</li>
    </ul>
  </div>
</template>
