<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  addStocktakeLines,
  approveStocktakeVariance,
  closeServiceOrder,
  createInventoryIssue,
  createInventoryReceipt,
  createInventoryTransfer,
  createStocktake,
  fetchInventoryItems,
  reserveServiceOrder,
  setReservationStrategy,
} from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import FormValidationSummary from '../ui/FormValidationSummary.vue'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'

type ItemRow = {
  id: number
  sku: string
  name: string
  on_hand: number
  reserved: number
  atp: number
  safety_stock: number
  low_stock: boolean
}

const { busy, run } = useSafeAction()
const rows = ref<ItemRow[]>([])
const page = ref(1)
const perPage = 10
const errors = ref<string[]>([])

const receiptForm = ref({ item_id: '', facility_id: '1', storeroom_id: '1', qty: '' })
const issueForm = ref({ item_id: '', facility_id: '1', storeroom_id: '1', qty: '' })
const transferForm = ref({ item_id: '', facility_id: '1', from_storeroom_id: '1', to_storeroom_id: '2', qty: '' })

const stocktakeForm = ref({ facility_id: '1', storeroom_id: '1', item_id: '', counted_qty: '', variance_reason: '' })
const approveStocktakeForm = ref({ stocktake_id: '', reason: '' })

const reservationForm = ref({ service_id: '', strategy: 'reserve_on_order_create', service_order_id: '', storeroom_id: '1', item_id: '', qty: '' })

const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage
  return rows.value.slice(start, start + perPage)
})

async function loadItems() {
  const response = await run(() => fetchInventoryItems(), undefined)
  if (!response) return

  rows.value = response.data.map((item) => ({
    id: Number(item.id),
    sku: String(item.sku ?? ''),
    name: String(item.name ?? ''),
    on_hand: Number(item.on_hand ?? 0),
    reserved: Number(item.reserved ?? 0),
    atp: Number(item.atp ?? 0),
    safety_stock: Number(item.safety_stock ?? 0),
    low_stock: Boolean(item.low_stock),
  }))
}

function mustHaveNumeric(value: string, label: string) {
  if (!value || Number.isNaN(Number(value))) {
    errors.value = [`${label} is required and must be numeric.`]
    return false
  }
  return true
}

async function submitReceipt() {
  if (!mustHaveNumeric(receiptForm.value.item_id, 'Receipt Item ID')) return
  if (!mustHaveNumeric(receiptForm.value.qty, 'Receipt quantity')) return

  const optimistic = rows.value.find((row) => row.id === Number(receiptForm.value.item_id))
  const previous = optimistic ? optimistic.on_hand : 0
  if (optimistic) optimistic.on_hand += Number(receiptForm.value.qty)

  const result = await run(
    () =>
      createInventoryReceipt({
        item_id: Number(receiptForm.value.item_id),
        facility_id: Number(receiptForm.value.facility_id),
        storeroom_id: Number(receiptForm.value.storeroom_id),
        qty: Number(receiptForm.value.qty),
      }),
    'Receipt posted.',
  )

  if (!result && optimistic) optimistic.on_hand = previous
  await loadItems()
}

async function submitIssue() {
  if (!mustHaveNumeric(issueForm.value.item_id, 'Issue Item ID')) return
  if (!mustHaveNumeric(issueForm.value.qty, 'Issue quantity')) return
  await run(
    () =>
      createInventoryIssue({
        item_id: Number(issueForm.value.item_id),
        facility_id: Number(issueForm.value.facility_id),
        storeroom_id: Number(issueForm.value.storeroom_id),
        qty: Number(issueForm.value.qty),
      }),
    'Issue posted.',
  )
  await loadItems()
}

async function submitTransfer() {
  if (!mustHaveNumeric(transferForm.value.item_id, 'Transfer Item ID')) return
  if (!mustHaveNumeric(transferForm.value.qty, 'Transfer quantity')) return
  await run(
    () =>
      createInventoryTransfer({
        item_id: Number(transferForm.value.item_id),
        facility_id: Number(transferForm.value.facility_id),
        from_storeroom_id: Number(transferForm.value.from_storeroom_id),
        to_storeroom_id: Number(transferForm.value.to_storeroom_id),
        qty: Number(transferForm.value.qty),
      }),
    'Transfer posted.',
  )
}

async function submitStocktake() {
  if (!mustHaveNumeric(stocktakeForm.value.item_id, 'Stocktake Item ID')) return
  if (!mustHaveNumeric(stocktakeForm.value.counted_qty, 'Counted quantity')) return

  const created = await run(
    () =>
      createStocktake({
        facility_id: Number(stocktakeForm.value.facility_id),
        storeroom_id: Number(stocktakeForm.value.storeroom_id),
      }),
    undefined,
  )
  if (!created) return
  const stocktakeId = Number(created.data.id)

  await run(
    () =>
      addStocktakeLines(stocktakeId, {
        lines: [
          {
            item_id: Number(stocktakeForm.value.item_id),
            counted_qty: Number(stocktakeForm.value.counted_qty),
            variance_reason: stocktakeForm.value.variance_reason || undefined,
          },
        ],
      }),
    'Stocktake submitted. Variance above +/-5% will require manager approval.',
  )
}

async function submitVarianceApproval() {
  if (!mustHaveNumeric(approveStocktakeForm.value.stocktake_id, 'Stocktake ID')) return
  if (approveStocktakeForm.value.reason.trim() === '') {
    errors.value = ['Manager approval reason is required.']
    return
  }

  await run(
    () => approveStocktakeVariance(Number(approveStocktakeForm.value.stocktake_id), approveStocktakeForm.value.reason),
    'Variance approved.',
  )
}

async function submitReservationStrategy() {
  if (!mustHaveNumeric(reservationForm.value.service_id, 'Service ID')) return
  await run(
    () =>
      setReservationStrategy({
        service_id: Number(reservationForm.value.service_id),
        strategy: reservationForm.value.strategy,
      }),
    'Reservation strategy updated.',
  )
}

async function submitServiceOrderReserve() {
  if (!mustHaveNumeric(reservationForm.value.service_order_id, 'Service Order ID')) return
  if (!mustHaveNumeric(reservationForm.value.item_id, 'Item ID')) return
  if (!mustHaveNumeric(reservationForm.value.qty, 'Quantity')) return

  await run(
    () =>
      reserveServiceOrder(Number(reservationForm.value.service_order_id), {
        service_id: Number(reservationForm.value.service_id),
        storeroom_id: Number(reservationForm.value.storeroom_id),
        lines: [{ item_id: Number(reservationForm.value.item_id), qty: Number(reservationForm.value.qty) }],
      }),
    'Reservation event written (Q5 strategy-aware).',
  )
}

async function submitServiceOrderClose() {
  if (!mustHaveNumeric(reservationForm.value.service_order_id, 'Service Order ID')) return
  await run(() => closeServiceOrder(Number(reservationForm.value.service_order_id)), 'Service order closed and reservations reconciled.')
  await loadItems()
}

onMounted(async () => {
  await loadItems()
})
</script>

<template>
  <section class="panel">
    <header>
      <h2>Inventory</h2>
      <p>On-hand/reserved/ATP monitoring and multi-storeroom receive/issue/transfer/stocktake operations.</p>
    </header>

    <FormValidationSummary :errors="errors" />

    <div class="module-grid">
      <div>
        <h3>Items & ATP</h3>
        <PaginatedTable
          :rows="pagedRows"
          :columns="[
            { key: 'sku', label: 'SKU' },
            { key: 'name', label: 'Item' },
            { key: 'on_hand', label: 'On Hand' },
            { key: 'reserved', label: 'Reserved' },
            { key: 'atp', label: 'ATP' },
            { key: 'low_stock', label: 'Low Stock' },
          ]"
          :page="page"
          :per-page="perPage"
          :total="rows.length"
          @page-change="page = $event"
        >
          <template #cell-low_stock="{ value }">
            <StatusBadge :status="value ? 'warning' : 'ok'" :label="value ? 'low' : 'normal'" />
          </template>
        </PaginatedTable>
      </div>

      <div class="stacked-forms">
        <form class="inline-form" @submit.prevent="submitReceipt">
          <h4>Receive</h4>
          <label>Item ID <input v-model="receiptForm.item_id" inputmode="numeric" /></label>
          <label>Facility ID <input v-model="receiptForm.facility_id" inputmode="numeric" /></label>
          <label>Storeroom ID <input v-model="receiptForm.storeroom_id" inputmode="numeric" /></label>
          <label>Quantity <input v-model="receiptForm.qty" inputmode="decimal" /></label>
          <button :disabled="busy" type="submit">Post Receipt</button>
        </form>

        <form class="inline-form" @submit.prevent="submitIssue">
          <h4>Issue</h4>
          <label>Item ID <input v-model="issueForm.item_id" inputmode="numeric" /></label>
          <label>Facility ID <input v-model="issueForm.facility_id" inputmode="numeric" /></label>
          <label>Storeroom ID <input v-model="issueForm.storeroom_id" inputmode="numeric" /></label>
          <label>Quantity <input v-model="issueForm.qty" inputmode="decimal" /></label>
          <button :disabled="busy" type="submit">Post Issue</button>
        </form>

        <form class="inline-form" @submit.prevent="submitTransfer">
          <h4>Transfer</h4>
          <label>Item ID <input v-model="transferForm.item_id" inputmode="numeric" /></label>
          <label>From Storeroom ID <input v-model="transferForm.from_storeroom_id" inputmode="numeric" /></label>
          <label>To Storeroom ID <input v-model="transferForm.to_storeroom_id" inputmode="numeric" /></label>
          <label>Quantity <input v-model="transferForm.qty" inputmode="decimal" /></label>
          <button :disabled="busy" type="submit">Post Transfer</button>
        </form>

        <form class="inline-form" @submit.prevent="submitStocktake">
          <h4>Stocktake</h4>
          <label>Facility ID <input v-model="stocktakeForm.facility_id" inputmode="numeric" /></label>
          <label>Storeroom ID <input v-model="stocktakeForm.storeroom_id" inputmode="numeric" /></label>
          <label>Item ID <input v-model="stocktakeForm.item_id" inputmode="numeric" /></label>
          <label>Counted Quantity <input v-model="stocktakeForm.counted_qty" inputmode="decimal" /></label>
          <label>Variance Reason <input v-model="stocktakeForm.variance_reason" /></label>
          <button :disabled="busy" type="submit">Submit Stocktake</button>
        </form>

        <form class="inline-form" @submit.prevent="submitVarianceApproval">
          <h4>Variance Approval (&gt; +/-5%)</h4>
          <label>Stocktake ID <input v-model="approveStocktakeForm.stocktake_id" inputmode="numeric" /></label>
          <label>Manager Reason <input v-model="approveStocktakeForm.reason" /></label>
          <button :disabled="busy" type="submit">Approve Variance</button>
        </form>

        <form class="inline-form" @submit.prevent="submitReservationStrategy">
          <h4>Reservation Strategy (Q5)</h4>
          <label>Service ID <input v-model="reservationForm.service_id" inputmode="numeric" /></label>
          <label>
            Strategy
            <select v-model="reservationForm.strategy">
              <option value="reserve_on_order_create">reserve_on_order_create</option>
              <option value="deduct_on_order_close">deduct_on_order_close</option>
            </select>
          </label>
          <button :disabled="busy" type="submit">Set Strategy</button>
        </form>

        <form class="inline-form" @submit.prevent="submitServiceOrderReserve">
          <h4>Service-Order Reserve</h4>
          <label>Service Order ID <input v-model="reservationForm.service_order_id" inputmode="numeric" /></label>
          <label>Service ID <input v-model="reservationForm.service_id" inputmode="numeric" /></label>
          <label>Storeroom ID <input v-model="reservationForm.storeroom_id" inputmode="numeric" /></label>
          <label>Item ID <input v-model="reservationForm.item_id" inputmode="numeric" /></label>
          <label>Quantity <input v-model="reservationForm.qty" inputmode="decimal" /></label>
          <div class="form-row">
            <button :disabled="busy" type="submit">Reserve</button>
            <button :disabled="busy" type="button" class="btn-subtle" @click="submitServiceOrderClose">Close Order</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
