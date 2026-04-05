<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchAuditLogs } from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import AnalyticsModule from '../../components/modules/AnalyticsModule.vue'
import PaginatedTable from '../../components/ui/PaginatedTable.vue'

const { run } = useSafeAction()
const auditRows = ref<Array<Record<string, unknown>>>([])
const auditPage = ref(1)
const perPage = 15

onMounted(async () => {
  const response = await run(() => fetchAuditLogs(), undefined)
  if (response) auditRows.value = response.data
})
</script>

<template>
  <div class="stacked-modules">
    <section class="panel">
      <header>
        <h2>Audit Logs</h2>
        <p>System-wide audit trail — read-only for System Administrator review.</p>
      </header>
      <PaginatedTable
        :rows="auditRows.slice((auditPage - 1) * perPage, auditPage * perPage)"
        :columns="[
          { key: 'id', label: 'ID' },
          { key: 'actor_user_id', label: 'Actor User' },
          { key: 'event_type', label: 'Event Type' },
          { key: 'action', label: 'Action' },
          { key: 'status', label: 'Status' },
          { key: 'path', label: 'Path' },
          { key: 'created_at', label: 'Timestamp UTC' },
        ]"
        :page="auditPage"
        :per-page="perPage"
        :total="auditRows.length"
        @page-change="auditPage = $event"
      />
    </section>
    <AnalyticsModule />
  </div>
</template>
