<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  approveAssetTransfer,
  checkoutRental,
  fetchRentalAssets,
  requestAssetTransfer,
  returnRental,
} from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import { validateCheckoutForm } from '../../utils/rentalValidation'
import FilterBar from '../ui/FilterBar.vue'
import FormValidationSummary from '../ui/FormValidationSummary.vue'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'

type AssetRow = {
  id: number
  asset_code: string
  name: string
  status: string
  facility_id: number
  category?: string
  replacement_cost_cents: number
  expected_return_at: string | null
  photo_url: string | null
  specs: string | null
}

const { busy, run } = useSafeAction()
const query = ref('')
const status = ref('')
const scanCode = ref('')
const page = ref(1)
const perPage = 10
const rows = ref<AssetRow[]>([])
const errors = ref<string[]>([])
const nowTs = ref(Date.now())

const checkoutForm = ref({
  asset_id: '',
  renter_type: 'department',
  renter_id: '1',
  expected_return_at: '',
  pricing_mode: 'daily',
  deposit_cents: '',
})

const returnCheckoutId = ref('')

const transferForm = ref({
  asset_id: '',
  to_facility_id: '1',
  requested_effective_at: '',
  reason: '',
})

const approveTransferId = ref('')

const filtered = computed(() => {
  return rows.value.filter((row) => {
    const queryMatch =
      query.value.trim() === '' ||
      row.name.toLowerCase().includes(query.value.toLowerCase()) ||
      row.asset_code.toLowerCase().includes(query.value.toLowerCase())
    const statusMatch = status.value === '' || row.status === status.value
    return queryMatch && statusMatch
  })
})

const pagedRows = computed(() => {
  const start = (page.value - 1) * perPage
  return filtered.value.slice(start, start + perPage)
})

const statusOptions = computed(() => [...new Set(rows.value.map((row) => row.status))])

function formatCurrency(cents: number) {
  return `$${(cents / 100).toFixed(2)}`
}

function countdownLabel(row: AssetRow): string {
  if (row.status !== 'rented' && row.status !== 'overdue') return '-'
  if (!row.expected_return_at) return '?'
  const diffMs = Date.parse(row.expected_return_at) - nowTs.value
  const diffMins = Math.round(Math.abs(diffMs) / 60_000)
  if (diffMs >= 0) return `due in ${diffMins}m`
  return `overdue ${diffMins}m`
}

async function loadAssets(filters: { scan_code?: string } = {}) {
  const response = await run(
    () =>
      fetchRentalAssets({
        q: query.value || undefined,
        status: status.value || undefined,
        scan_code: filters.scan_code,
      }),
    undefined,
  )

  if (!response) {
    return
  }

  rows.value = response.data.map((row) => ({
    id: Number(row.id),
    asset_code: String(row.asset_code ?? ''),
    name: String(row.name ?? ''),
    status: String(row.status ?? 'available'),
    facility_id: Number(row.facility_id ?? 0),
    category: row.category ? String(row.category) : undefined,
    replacement_cost_cents: Number(row.replacement_cost_cents ?? 0),
    expected_return_at: row.expected_return_at ? String(row.expected_return_at) : null,
    photo_url: row.photo_url ? String(row.photo_url) : null,
    specs: row.specs ? String(row.specs) : null,
  }))
}

function validateCheckout() {
  errors.value = validateCheckoutForm(checkoutForm.value, rows.value)
  return errors.value.length === 0
}

async function submitCheckout() {
  if (!validateCheckout()) return

  const payload = {
    asset_id: Number(checkoutForm.value.asset_id),
    renter_type: checkoutForm.value.renter_type,
    renter_id: Number(checkoutForm.value.renter_id),
    checked_out_at: new Date().toISOString(),
    expected_return_at: new Date(checkoutForm.value.expected_return_at).toISOString(),
    pricing_mode: checkoutForm.value.pricing_mode,
    deposit_cents: Number(checkoutForm.value.deposit_cents),
  }

  const result = await run(() => checkoutRental(payload), 'Checkout created successfully.')
  if (!result) return

  const target = rows.value.find((row) => row.id === payload.asset_id)
  if (target) target.status = 'rented'
}

async function submitReturn() {
  if (!returnCheckoutId.value) {
    errors.value = ['Checkout ID is required for return.']
    return
  }

  const ok = await run(() => returnRental(Number(returnCheckoutId.value)), 'Return completed.')
  if (!ok) return
  await loadAssets()
}

async function submitTransferRequest() {
  if (!transferForm.value.asset_id || transferForm.value.reason.trim() === '') {
    errors.value = ['Asset ID and transfer reason are required.']
    return
  }

  await run(
    () =>
      requestAssetTransfer(Number(transferForm.value.asset_id), {
        to_facility_id: Number(transferForm.value.to_facility_id),
        requested_effective_at: new Date(transferForm.value.requested_effective_at || Date.now()).toISOString(),
        reason: transferForm.value.reason,
      }),
    'Transfer request submitted.',
  )
}

async function submitTransferApproval() {
  if (!approveTransferId.value) {
    errors.value = ['Transfer request ID is required for approval.']
    return
  }
  await run(() => approveAssetTransfer(Number(approveTransferId.value)), 'Transfer approved and completed.')
  await loadAssets()
}

async function scanLookup() {
  if (scanCode.value.trim() === '') {
    return
  }
  await loadAssets({ scan_code: scanCode.value.trim() })
}

let timer: number | null = null

onMounted(async () => {
  await loadAssets()
  timer = window.setInterval(() => {
    nowTs.value = Date.now()
  }, 1000)
})

onUnmounted(() => {
  if (timer !== null) window.clearInterval(timer)
})
</script>

<template>
  <section class="panel">
    <header>
      <h2>Rentals</h2>
      <p>Asset ledger, checkout/return, scan lookup, overdue monitoring, and facility transfer workflow.</p>
    </header>

    <div class="split-grid">
      <div>
        <h3>Asset Ledger</h3>
        <FilterBar
          :query="query"
          :status="status"
          :status-options="statusOptions"
          @update-query="query = $event"
          @update-status="status = $event"
        />
        <div class="toolbar-row">
          <label>
            QR / Barcode Scan Lookup
            <input
              v-model="scanCode"
              type="text"
              placeholder="Scan or type code, then press Enter"
              @keydown.enter.prevent="scanLookup"
            />
          </label>
          <button :disabled="busy" @click="loadAssets()">Refresh</button>
        </div>

        <PaginatedTable
          :rows="pagedRows"
          :columns="[
            { key: 'photo_url', label: 'Photo' },
            { key: 'asset_code', label: 'Asset Code' },
            { key: 'name', label: 'Name' },
            { key: 'status', label: 'Status' },
            { key: 'replacement_cost_cents', label: 'Replacement Cost' },
          ]"
          :page="page"
          :per-page="perPage"
          :total="filtered.length"
          @page-change="page = $event"
        >
          <template #cell-photo_url="{ value }">
            <img v-if="value" :src="String(value)" alt="asset photo"
                 style="width:48px;height:48px;object-fit:cover;border-radius:4px;" />
            <span v-else class="muted">—</span>
          </template>
          <template #cell-name="{ value, row }">
            <span>{{ String(value) }}</span>
            <small v-if="(row as AssetRow).specs" class="muted" style="display:block;font-size:0.75em;">
              {{ (row as AssetRow).specs }}
            </small>
          </template>
          <template #cell-status="{ value, row }">
            <StatusBadge
              :status="value === 'overdue' ? 'error' : value === 'rented' ? 'warning' : 'ok'"
              :label="`${String(value)} — ${countdownLabel(row as AssetRow)}`"
            />
          </template>
          <template #cell-replacement_cost_cents="{ value }">{{ formatCurrency(Number(value)) }}</template>
        </PaginatedTable>
      </div>

      <div>
        <h3>Actions</h3>
        <FormValidationSummary :errors="errors" />

        <form class="inline-form" @submit.prevent="submitCheckout">
          <h4>Checkout</h4>
          <label>
            Asset ID
            <input v-model="checkoutForm.asset_id" inputmode="numeric" />
          </label>
          <label>
            Renter Type
            <select v-model="checkoutForm.renter_type">
              <option value="department">Department</option>
              <option value="clinician">Clinician</option>
            </select>
          </label>
          <label>
            Renter ID
            <input v-model="checkoutForm.renter_id" inputmode="numeric" />
          </label>
          <label>
            Expected Return (UTC)
            <input v-model="checkoutForm.expected_return_at" type="datetime-local" />
          </label>
          <label>
            Pricing Mode
            <select v-model="checkoutForm.pricing_mode">
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
            </select>
          </label>
          <label>
            Deposit (cents)
            <input v-model="checkoutForm.deposit_cents" inputmode="numeric" />
          </label>
          <button :disabled="busy" type="submit">Create Checkout</button>
        </form>

        <form class="inline-form" @submit.prevent="submitReturn">
          <h4>Return</h4>
          <label>
            Checkout ID
            <input v-model="returnCheckoutId" inputmode="numeric" />
          </label>
          <button :disabled="busy" type="submit">Mark Returned</button>
        </form>

        <form class="inline-form" @submit.prevent="submitTransferRequest">
          <h4>Facility Transfer Request (Q1)</h4>
          <label>
            Asset ID
            <input v-model="transferForm.asset_id" inputmode="numeric" />
          </label>
          <label>
            To Facility ID
            <input v-model="transferForm.to_facility_id" inputmode="numeric" />
          </label>
          <label>
            Effective At (UTC)
            <input v-model="transferForm.requested_effective_at" type="datetime-local" />
          </label>
          <label>
            Reason
            <input v-model="transferForm.reason" />
          </label>
          <button :disabled="busy" type="submit">Submit Transfer</button>
        </form>

        <form class="inline-form" @submit.prevent="submitTransferApproval">
          <h4>Transfer Approval</h4>
          <label>
            Transfer Request ID
            <input v-model="approveTransferId" inputmode="numeric" />
          </label>
          <button :disabled="busy" type="submit">Approve Transfer</button>
        </form>
      </div>
    </div>
  </section>
</template>
