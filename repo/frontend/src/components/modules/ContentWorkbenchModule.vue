<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  approveContent,
  createContentItem,
  fetchContentItems,
  fetchContentVersions,
  rejectContent,
  rollbackContent,
  submitContentApproval,
  updateContentItem,
} from '../../api/modules'
import { useSafeAction } from '../../composables/useSafeAction'
import FormValidationSummary from '../ui/FormValidationSummary.vue'
import PaginatedTable from '../ui/PaginatedTable.vue'
import StatusBadge from '../ui/StatusBadge.vue'

type ContentRow = {
  id: number
  title: string
  status: string
  content_type: string
  current_version: number
}

const { busy, run } = useSafeAction()
const errors = ref<string[]>([])
const contentRows = ref<ContentRow[]>([])
const versions = ref<Array<Record<string, unknown>>>([])

const form = ref({
  id: '',
  content_type: 'announcement',
  title: '',
  body: '',
  facility_ids: '1',
  department_ids: '',
  role_codes: 'content_editor,content_approver',
  tags: 'operations',
})

const actionForm = ref({
  content_id: '',
  rollback_version: '',
})

onMounted(async () => {
  const response = await run(() => fetchContentItems(), undefined)
  if (!response) return
  contentRows.value = response.data.map((item) => ({
    id: Number(item.id),
    title: String(item.title ?? ''),
    status: String(item.status ?? 'draft'),
    content_type: String(item.content_type ?? 'announcement'),
    current_version: Number(item.current_version ?? 1),
  }))
})

function parseCsvList(input: string): string[] {
  return input
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
}

function parseCsvNumberList(input: string): number[] {
  return parseCsvList(input).map((value) => Number(value)).filter((value) => !Number.isNaN(value))
}

async function createDraft() {
  errors.value = []
  if (form.value.title.trim() === '') {
    errors.value = ['Title is required.']
    return
  }

  const optimistic: ContentRow = {
    id: Date.now(),
    title: form.value.title,
    status: 'draft',
    content_type: form.value.content_type,
    current_version: 1,
  }
  contentRows.value.unshift(optimistic)

  const result = await run(
    () =>
      createContentItem({
        content_type: form.value.content_type,
        title: form.value.title,
        body: form.value.body,
        facility_ids: parseCsvNumberList(form.value.facility_ids),
        department_ids: parseCsvNumberList(form.value.department_ids),
        role_codes: parseCsvList(form.value.role_codes),
        tags: parseCsvList(form.value.tags),
      }),
    'Draft created.',
  )

  if (!result) {
    contentRows.value = contentRows.value.filter((row) => row.id !== optimistic.id)
    return
  }

  optimistic.id = Number(result.data.id)
  optimistic.current_version = Number(result.data.current_version ?? 1)
}

async function updateDraft() {
  if (!actionForm.value.content_id) {
    errors.value = ['Content ID is required for update.']
    return
  }

  const result = await run(
    () =>
      updateContentItem(Number(actionForm.value.content_id), {
        title: form.value.title,
        body: form.value.body,
        facility_ids: parseCsvNumberList(form.value.facility_ids),
        department_ids: parseCsvNumberList(form.value.department_ids),
        role_codes: parseCsvList(form.value.role_codes),
        tags: parseCsvList(form.value.tags),
      }),
    'Draft updated.',
  )

  if (!result) return
  const index = contentRows.value.findIndex((row) => row.id === Number(result.data.id))
  if (index >= 0) {
    contentRows.value[index] = {
      ...contentRows.value[index],
      title: String(result.data.title ?? contentRows.value[index].title),
      status: String(result.data.status ?? contentRows.value[index].status),
      current_version: Number(result.data.current_version ?? contentRows.value[index].current_version),
    }
  }
}

async function executeLifecycle(action: 'submit' | 'approve' | 'reject' | 'rollback') {
  if (!actionForm.value.content_id) {
    errors.value = ['Content ID is required for workflow action.']
    return
  }

  const id = Number(actionForm.value.content_id)
  if (action === 'submit') {
    await run(() => submitContentApproval(id), 'Content submitted for approval.')
  }
  if (action === 'approve') {
    await run(() => approveContent(id), 'Content approved and published.')
  }
  if (action === 'reject') {
    await run(() => rejectContent(id), 'Content rejected.')
  }
  if (action === 'rollback') {
    if (!actionForm.value.rollback_version) {
      errors.value = ['Rollback version is required.']
      return
    }
    await run(() => rollbackContent(id, Number(actionForm.value.rollback_version)), 'Content rolled back to selected version.')
  }
}

async function loadVersions() {
  if (!actionForm.value.content_id) {
    errors.value = ['Content ID is required to view versions.']
    return
  }

  const response = await run(() => fetchContentVersions(Number(actionForm.value.content_id)), undefined)
  if (!response) return
  versions.value = response.data
}
</script>

<template>
  <section class="panel">
    <header>
      <h2>Content Workbench</h2>
      <p>Draft, target, approve/reject/publish, and rollback with version history visibility.</p>
    </header>

    <FormValidationSummary :errors="errors" />

    <div class="split-grid">
      <div>
        <form class="inline-form" @submit.prevent="createDraft">
          <h3>Draft & Targeting</h3>
          <label>
            Content Type
            <select v-model="form.content_type">
              <option value="announcement">announcement</option>
              <option value="homepage_carousel">homepage_carousel</option>
            </select>
          </label>
          <label>Title <input v-model="form.title" /></label>
          <label>Body <textarea v-model="form.body" rows="4" /></label>
          <label>Facility IDs (comma) <input v-model="form.facility_ids" /></label>
          <label>Department IDs (comma) <input v-model="form.department_ids" /></label>
          <label>Role Codes (comma) <input v-model="form.role_codes" /></label>
          <label>Tags (comma) <input v-model="form.tags" /></label>
          <div class="form-row">
            <button :disabled="busy" type="submit">Create Draft</button>
            <button :disabled="busy" type="button" class="btn-subtle" @click="updateDraft">Update Draft</button>
          </div>
        </form>

        <form class="inline-form" @submit.prevent>
          <h3>Approval Lifecycle</h3>
          <label>Content ID <input v-model="actionForm.content_id" inputmode="numeric" /></label>
          <label>Rollback Version <input v-model="actionForm.rollback_version" inputmode="numeric" /></label>
          <div class="form-row">
            <button :disabled="busy" type="button" @click="executeLifecycle('submit')">Submit</button>
            <button :disabled="busy" type="button" @click="executeLifecycle('approve')">Approve/Publish</button>
            <button :disabled="busy" type="button" @click="executeLifecycle('reject')">Reject</button>
            <button :disabled="busy" type="button" class="btn-subtle" @click="executeLifecycle('rollback')">Rollback</button>
          </div>
        </form>
      </div>

      <div>
        <h3>Working Set</h3>
        <PaginatedTable
          :rows="contentRows"
          :columns="[
            { key: 'id', label: 'ID' },
            { key: 'title', label: 'Title' },
            { key: 'status', label: 'Status' },
            { key: 'current_version', label: 'Version' },
          ]"
          :page="1"
          :per-page="100"
          :total="contentRows.length"
          @page-change="() => {}"
        >
          <template #cell-status="{ value }">
            <StatusBadge
              :status="value === 'published' || value === 'approved' ? 'ok' : value === 'rejected' ? 'error' : 'warning'"
              :label="String(value)"
            />
          </template>
        </PaginatedTable>

        <div class="inline-form">
          <h3>Versions / History</h3>
          <button :disabled="busy" type="button" @click="loadVersions">Load Versions by Content ID</button>
          <PaginatedTable
            :rows="versions"
            :columns="[
              { key: 'version_number', label: 'Version' },
              { key: 'status', label: 'Status' },
              { key: 'created_at_utc', label: 'Created UTC' },
            ]"
            :page="1"
            :per-page="100"
            :total="versions.length"
            @page-change="() => {}"
          />
        </div>
      </div>
    </div>
  </section>
</template>
