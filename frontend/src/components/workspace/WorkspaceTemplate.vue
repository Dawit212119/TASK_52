<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import FilterBar from '../ui/FilterBar.vue'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'
import { toActionableMessage } from '../../api/http'

type Row = {
  id: number
  title: string
  status: string
  owner: string
}

const props = defineProps<{
  title: string
  description: string
  loadRows: () => Promise<Row[]>
}>()

const query = ref('')
const status = ref('')
const page = ref(1)
const perPage = 5
const loading = ref(true)
const error = ref('')
const sourceRows = ref<Row[]>([])

const filteredRows = computed(() => {
  return sourceRows.value.filter((row) => {
    const queryMatch = query.value === '' || row.title.toLowerCase().includes(query.value.toLowerCase())
    const statusMatch = status.value === '' || row.status === status.value
    return queryMatch && statusMatch
  })
})

const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage
  return filteredRows.value.slice(start, start + perPage)
})

const statuses = computed(() => [...new Set(sourceRows.value.map((row) => row.status))])

onMounted(async () => {
  try {
    sourceRows.value = await props.loadRows()
  } catch (exception) {
    error.value = toActionableMessage(exception)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="panel">
    <header>
      <h2>{{ title }}</h2>
      <p>{{ description }}</p>
    </header>

    <p v-if="loading">Loading workspace data...</p>
    <p v-else-if="error" class="danger-text">{{ error }}</p>

    <template v-else>
      <FilterBar
        :query="query"
        :status="status"
        :status-options="statuses"
        @update-query="query = $event"
        @update-status="status = $event"
      />
      <PaginatedTable
        :rows="pagedRows"
        :columns="[
          { key: 'title', label: 'Record' },
          { key: 'status', label: 'Status' },
          { key: 'owner', label: 'Owner' },
        ]"
        :page="page"
        :per-page="perPage"
        :total="filteredRows.length"
        @page-change="page = $event"
      >
        <template #cell-status="{ value }">
          <StatusBadge
            :status="value === 'ok' || value === 'published' || value === 'approved' ? 'ok' : value === 'warning' || value === 'pending' ? 'warning' : 'info'"
            :label="String(value)"
          />
        </template>
      </PaginatedTable>
    </template>
  </section>
</template>
