<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { API_BASE_PATH, toActionableMessage } from '../api/http'

const route = useRoute()
const visitOrderId = String(route.params.visitOrderId)
const reviewToken = String(route.query.token ?? '')

const rating = ref(0)
const tagsInput = ref('')
const text = ref('')
const images = ref<File[]>([])
const imageNames = ref<string[]>([])

const submitting = ref(false)
const submitted = ref(false)
const errorMessage = ref('')
const invalidLink = ref(reviewToken.trim() === '')

const AVAILABLE_TAGS = ['cleanliness', 'communication', 'wait_time', 'treatment', 'pricing']
const selectedTags = ref<string[]>([])

function toggleTag(tag: string) {
  const idx = selectedTags.value.indexOf(tag)
  if (idx === -1) {
    selectedTags.value.push(tag)
  } else {
    selectedTags.value.splice(idx, 1)
  }
}

function onFilesChange(event: Event) {
  const input = event.target as HTMLInputElement
  if (!input.files) return
  const files = Array.from(input.files).slice(0, 5)
  images.value = files
  imageNames.value = files.map((f) => f.name)
}

async function handleSubmit() {
  if (submitting.value) return
  errorMessage.value = ''

  if (invalidLink.value) {
    errorMessage.value = 'Invalid or expired review link. Please request a new one from clinic staff.'
    return
  }

  if (rating.value < 1 || rating.value > 5) {
    errorMessage.value = 'Please select a star rating before submitting.'
    return
  }

  const tags = selectedTags.value.length > 0
    ? selectedTags.value
    : tagsInput.value
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean)

  submitting.value = true
  try {
    const form = new FormData()
    form.append('visit_order_id', visitOrderId)
    form.append('token', reviewToken)
    form.append('rating', String(rating.value))
    tags.forEach((t) => form.append('tags[]', t))
    form.append('text', text.value)
    if (images.value.length > 0) {
      images.value.slice(0, 5).forEach((f) => form.append('images[]', f))
    }
    const res = await fetch(`${API_BASE_PATH}/reviews/public`, {
      method: 'POST',
      credentials: 'omit',
      body: form,
    })
    if (!res.ok) {
      const payload = await res.json()
      throw Object.assign(new Error(payload?.error?.message ?? 'Submission failed.'), { _payload: payload })
    }
    submitted.value = true
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : toActionableMessage(err)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-layout">
    <div class="auth-card">
      <h2>Leave a Review</h2>

      <div v-if="submitted" class="success-message">
        <p>Thank you for your feedback!</p>
      </div>

      <template v-else>
        <p v-if="invalidLink" class="error-message">Invalid or expired review link. Please request a new one from clinic staff.</p>
        <p v-if="errorMessage" class="error-message">{{ errorMessage }}</p>

        <form @submit.prevent="handleSubmit" :aria-disabled="invalidLink ? 'true' : 'false'">
          <div class="field-group">
            <label>Rating</label>
            <div class="star-row">
              <button
                v-for="star in 5"
                :key="star"
                type="button"
                class="star-btn"
                :class="{ active: star <= rating }"
                @click="rating = star"
              >★</button>
            </div>
          </div>

          <div class="field-group">
            <label>Tags</label>
            <div class="tag-chips">
              <button
                v-for="tag in AVAILABLE_TAGS"
                :key="tag"
                type="button"
                class="tag-chip"
                :class="{ selected: selectedTags.includes(tag) }"
                @click="toggleTag(tag)"
              >{{ tag }}</button>
            </div>
            <input
              v-model="tagsInput"
              type="text"
              placeholder="Or enter comma-separated tags"
              style="margin-top:0.5rem;"
            />
          </div>

          <div class="field-group">
            <label>Review <span class="muted">(required, max 1000 chars)</span></label>
            <textarea
              v-model="text"
              required
              maxlength="1000"
              rows="5"
              style="width:100%;resize:vertical;"
            />
            <small class="muted">{{ text.length }} / 1000</small>
          </div>

          <div class="field-group">
            <label>Photos <span class="muted">(up to 5)</span></label>
            <input type="file" multiple accept="image/*" @change="onFilesChange" />
            <ul v-if="imageNames.length" style="margin-top:0.25rem;padding-left:1.25rem;">
              <li v-for="name in imageNames" :key="name">{{ name }}</li>
            </ul>
          </div>

          <button type="submit" :disabled="submitting || invalidLink" style="width:100%;margin-top:1rem;">
            {{ submitting ? 'Submitting…' : 'Submit Review' }}
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<style scoped>
.auth-layout {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg, #f5f5f5);
}
.auth-card {
  background: #fff;
  border-radius: 8px;
  padding: 2rem;
  width: 100%;
  max-width: 480px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.1);
}
.field-group {
  margin-bottom: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.star-row {
  display: flex;
  gap: 0.25rem;
}
.star-btn {
  background: none;
  border: none;
  font-size: 2rem;
  cursor: pointer;
  color: #ccc;
  padding: 0;
  line-height: 1;
}
.star-btn.active {
  color: #f5a623;
}
.tag-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.tag-chip {
  padding: 0.25rem 0.75rem;
  border-radius: 999px;
  border: 1px solid #ccc;
  background: #f5f5f5;
  cursor: pointer;
  font-size: 0.85rem;
}
.tag-chip.selected {
  background: #3b82f6;
  color: #fff;
  border-color: #3b82f6;
}
.error-message {
  color: #dc2626;
  margin-bottom: 1rem;
}
.success-message {
  text-align: center;
  font-size: 1.25rem;
  color: #16a34a;
  padding: 2rem 0;
}
.muted {
  color: #888;
}
</style>
