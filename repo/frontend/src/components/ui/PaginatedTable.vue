<script setup lang="ts" generic="T extends Record<string, unknown>">
type Column<T> = {
  key: keyof T
  label: string
}

const props = defineProps<{
  rows: T[]
  columns: Column<T>[]
  page: number
  perPage: number
  total: number
}>()

const emit = defineEmits<{
  pageChange: [page: number]
}>()

const pages = Math.max(1, Math.ceil(props.total / Math.max(props.perPage, 1)))
</script>

<template>
  <div class="table-shell">
    <table class="data-table">
      <thead>
        <tr>
          <th v-for="column in columns" :key="String(column.key)">{{ column.label }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="rows.length === 0">
          <td :colspan="columns.length">No records found.</td>
        </tr>
        <tr v-for="(row, index) in rows" :key="index">
          <td v-for="column in columns" :key="String(column.key)">
            <slot :name="`cell-${String(column.key)}`" :value="row[column.key]" :row="row">
              {{ row[column.key] }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
    <div class="table-pagination">
      <button :disabled="page <= 1" @click="emit('pageChange', page - 1)">Previous</button>
      <span>Page {{ page }} of {{ pages }}</span>
      <button :disabled="page >= pages" @click="emit('pageChange', page + 1)">Next</button>
    </div>
  </div>
</template>
