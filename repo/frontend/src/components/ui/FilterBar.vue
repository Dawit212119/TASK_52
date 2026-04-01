<script setup lang="ts">
const props = defineProps<{
  query: string
  status: string
  statusOptions: string[]
}>()

const emit = defineEmits<{
  updateQuery: [value: string]
  updateStatus: [value: string]
}>()
</script>

<template>
  <div class="filter-bar">
    <label>
      Search
      <input :value="props.query" type="text" placeholder="Type search term" @input="emit('updateQuery', ($event.target as HTMLInputElement).value)" />
    </label>
    <label>
      Status
      <select :value="props.status" @change="emit('updateStatus', ($event.target as HTMLSelectElement).value)">
        <option value="">All</option>
        <option v-for="option in statusOptions" :key="option" :value="option">{{ option }}</option>
      </select>
    </label>
  </div>
</template>
