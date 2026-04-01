<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { appealReview, fetchReviews, hideReview, respondReview } from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import FilterBar from '../ui/FilterBar.vue'
import FormValidationSummary from '../ui/FormValidationSummary.vue'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'

type ReviewRow = {
  id: number
  visit_order_id: number
  rating: number
  visibility_status: string
  text: string
}

const { busy, run } = useSafeAction()
const rows = ref<ReviewRow[]>([])
const page = ref(1)
const perPage = 10
const query = ref('')
const status = ref('')
const errors = ref<string[]>([])

const responseForm = ref({ review_id: '', response_text: '' })
const appealForm = ref({ review_id: '', reason_category: 'abusive_language' })
const hideReviewId = ref('')

const filteredRows = computed(() => {
  return rows.value.filter((row) => {
    const text = `${row.visit_order_id} ${row.text}`.toLowerCase()
    const queryMatch = query.value === '' || text.includes(query.value.toLowerCase())
    const statusMatch = status.value === '' || row.visibility_status === status.value
    return queryMatch && statusMatch
  })
})

const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage
  return filteredRows.value.slice(start, start + perPage)
})

const statusOptions = computed(() => [...new Set(rows.value.map((row) => row.visibility_status))])

function parseReviewId(raw: string, _label?: string): number | null {
  const trimmed = raw.trim()
  if (trimmed === '') {
    return null
  }
  if (!/^\d+$/.test(trimmed)) {
    return null
  }
  const id = Number.parseInt(trimmed, 10)
  if (!Number.isInteger(id) || id < 1) {
    return null
  }
  return id
}

async function loadReviews() {
  const response = await run(() => fetchReviews(), undefined)
  if (!response) return
  rows.value = response.data.map((row) => ({
    id: Number(row.id),
    visit_order_id: Number(row.visit_order_id ?? 0),
    rating: Number(row.rating ?? 0),
    visibility_status: String(row.visibility_status ?? 'visible'),
    text: String(row.text ?? ''),
  }))
}

async function submitResponse() {
  errors.value = []
  const reviewId = parseReviewId(responseForm.value.review_id, 'Review ID')
  if (reviewId === null || responseForm.value.response_text.trim() === '') {
    errors.value = ['Enter a numeric Review ID (see table) and response text.']
    return
  }

  await run(() => respondReview(reviewId, responseForm.value.response_text), 'Response posted.')
}

async function submitAppeal() {
  errors.value = []
  const reviewId = parseReviewId(appealForm.value.review_id, 'Review ID')
  if (reviewId === null) {
    errors.value = ['Enter a numeric Review ID for the appeal.']
    return
  }

  await run(
    () => appealReview(reviewId, appealForm.value.reason_category),
    'Moderation case opened.',
  )
}

async function submitHide() {
  const reviewId = parseReviewId(hideReviewId.value, 'Review ID')
  if (reviewId === null) {
    errors.value = ['Enter a numeric Review ID to hide.']
    return
  }

  await run(() => hideReview(reviewId), 'Review hidden from operational screens.')
  await loadReviews()
}

onMounted(loadReviews)
</script>

<template>
  <section class="panel">
    <header>
      <h2>Reviews & Moderation</h2>
      <p>Review response, appeal/hide flows, and moderation case controls with policy categories (Q14).</p>
    </header>

    <FilterBar
      :query="query"
      :status="status"
      :status-options="statusOptions"
      @update-query="query = $event"
      @update-status="status = $event"
    />

    <PaginatedTable
      :rows="pagedRows"
      :columns="[
        { key: 'id', label: 'Review ID' },
        { key: 'visit_order_id', label: 'Visit Order' },
        { key: 'rating', label: 'Rating' },
        { key: 'visibility_status', label: 'Visibility' },
        { key: 'text', label: 'Text' },
      ]"
      :page="page"
      :per-page="perPage"
      :total="filteredRows.length"
      @page-change="page = $event"
    >
      <template #cell-visibility_status="{ value }">
        <StatusBadge :status="value === 'hidden' ? 'warning' : 'ok'" :label="String(value)" />
      </template>
    </PaginatedTable>

    <FormValidationSummary :errors="errors" />

    <div class="split-grid">
      <form class="inline-form" @submit.prevent="submitResponse">
        <h3>Manager/Provider Response</h3>
        <label>Review ID <input v-model="responseForm.review_id" inputmode="numeric" /></label>
        <label>Response Text <textarea v-model="responseForm.response_text" rows="3" /></label>
        <button :disabled="busy" type="submit">Post Response</button>
      </form>

      <form class="inline-form" @submit.prevent="submitAppeal">
        <h3>Appeal / Moderation Case</h3>
        <label>Review ID <input v-model="appealForm.review_id" inputmode="numeric" /></label>
        <label>
          Policy Category
          <select v-model="appealForm.reason_category">
            <option value="abusive_language">abusive_language</option>
            <option value="harassment">harassment</option>
            <option value="privacy">privacy</option>
            <option value="spam">spam</option>
            <option value="other">other</option>
          </select>
        </label>
        <button :disabled="busy" type="submit">Open Moderation Case</button>
      </form>

      <form class="inline-form" @submit.prevent="submitHide">
        <h3>Hide Review</h3>
        <label>Review ID <input v-model="hideReviewId" inputmode="numeric" /></label>
        <button :disabled="busy" type="submit">Hide</button>
      </form>
    </div>
  </section>
</template>
