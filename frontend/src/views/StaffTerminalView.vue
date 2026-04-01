<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { fetchPublishedCarousel } from '../api/modules'

type CarouselItem = {
  id: number
  title: string
  body: string
}

const items = ref<CarouselItem[]>([])
const currentIndex = ref(0)
const loading = ref(true)

let interval: ReturnType<typeof setInterval> | null = null

onMounted(async () => {
  try {
    const response = await fetchPublishedCarousel()
    items.value = response.data.map((item) => ({
      id: Number(item.id ?? 0),
      title: String(item.title ?? ''),
      body: String(item.body ?? ''),
    }))
  } catch {
    // show empty carousel gracefully
  } finally {
    loading.value = false
  }

  if (items.value.length > 1) {
    interval = setInterval(() => {
      currentIndex.value = (currentIndex.value + 1) % items.value.length
    }, 5000)
  }
})

onUnmounted(() => {
  if (interval !== null) clearInterval(interval)
})
</script>

<template>
  <div class="terminal-layout">
    <div class="terminal-card">
      <h1 class="terminal-brand">VetOps Staff Terminal</h1>

      <div v-if="loading" class="terminal-loading">Loading…</div>

      <div v-else-if="items.length === 0" class="terminal-empty">
        No announcements at this time.
      </div>

      <div v-else class="carousel">
        <div class="carousel-slide">
          <h2 class="carousel-title">{{ items[currentIndex].title }}</h2>
          <pre class="carousel-body">{{ items[currentIndex].body }}</pre>
        </div>

        <div class="dot-row">
          <button
            v-for="(_, i) in items"
            :key="i"
            class="dot"
            :class="{ active: i === currentIndex }"
            @click="currentIndex = i"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.terminal-layout {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #1a1a2e;
  color: #e0e0e0;
}
.terminal-card {
  width: 100%;
  max-width: 720px;
  padding: 3rem 2rem;
  text-align: center;
}
.terminal-brand {
  font-size: 1.5rem;
  color: #94a3b8;
  margin-bottom: 2rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}
.terminal-loading,
.terminal-empty {
  color: #64748b;
  font-size: 1.25rem;
}
.carousel-slide {
  min-height: 200px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 1rem;
}
.carousel-title {
  font-size: 2rem;
  color: #f1f5f9;
}
.carousel-body {
  white-space: pre-wrap;
  font-family: inherit;
  font-size: 1.1rem;
  color: #cbd5e1;
  line-height: 1.6;
}
.dot-row {
  display: flex;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 2rem;
}
.dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  border: none;
  background: #475569;
  cursor: pointer;
  padding: 0;
}
.dot.active {
  background: #3b82f6;
}
</style>
