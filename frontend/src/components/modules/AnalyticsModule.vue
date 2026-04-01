<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { fetchInventoryLowStock, fetchOverdueRentals, fetchReviewSummary } from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'

const { run } = useSafeAction()

const reviewSummary = ref({
  average_score: 0,
  negative_review_rate: 0,
  median_response_time_minutes: 0,
})

const lowStockRows = ref<Array<Record<string, unknown>>>([])
const overdueRows = ref<Array<Record<string, unknown>>>([])

async function loadAnalytics() {
  const [summary, lowStock, overdue] = await Promise.all([
    run(() => fetchReviewSummary(), undefined),
    run(() => fetchInventoryLowStock(), undefined),
    run(() => fetchOverdueRentals(), undefined),
  ])

  if (summary) reviewSummary.value = summary.data
  if (lowStock) lowStockRows.value = lowStock.data
  if (overdue) overdueRows.value = overdue.data
}

onMounted(loadAnalytics)
</script>

<template>
  <section class="panel">
    <header>
      <h2>Analytics</h2>
      <p>Review summary, low-stock dashboard, and overdue rentals monitoring.</p>
    </header>

    <div class="kpi-grid">
      <article class="kpi-card">
        <h3>Average Score</h3>
        <p>{{ reviewSummary.average_score.toFixed(2) }}</p>
      </article>
      <article class="kpi-card">
        <h3>Negative Review Rate</h3>
        <p>{{ reviewSummary.negative_review_rate.toFixed(2) }}%</p>
      </article>
      <article class="kpi-card">
        <h3>Median Response Time</h3>
        <p>{{ reviewSummary.median_response_time_minutes }} min</p>
      </article>
    </div>

    <div class="split-grid">
      <div>
        <h3>Inventory Low-Stock</h3>
        <PaginatedTable
          :rows="lowStockRows"
          :columns="[
            { key: 'id', label: 'Item ID' },
            { key: 'name', label: 'Item' },
            { key: 'atp', label: 'ATP' },
            { key: 'low_stock', label: 'Status' },
          ]"
          :page="1"
          :per-page="100"
          :total="lowStockRows.length"
          @page-change="() => {}"
        >
          <template #cell-low_stock="{ value }">
            <StatusBadge :status="value ? 'warning' : 'ok'" :label="value ? 'low' : 'normal'" />
          </template>
        </PaginatedTable>
      </div>

      <div>
        <h3>Overdue Rentals</h3>
        <PaginatedTable
          :rows="overdueRows"
          :columns="[
            { key: 'id', label: 'Checkout ID' },
            { key: 'asset_id', label: 'Asset ID' },
            { key: 'expected_return_at', label: 'Expected Return' },
          ]"
          :page="1"
          :per-page="100"
          :total="overdueRows.length"
          @page-change="() => {}"
        />
      </div>
    </div>
  </section>
</template>
