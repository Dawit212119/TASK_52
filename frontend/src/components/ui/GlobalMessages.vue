<script setup lang="ts">
import { useUiStore } from '../../stores/ui'

const uiStore = useUiStore()
</script>

<template>
  <aside class="messages" v-if="uiStore.hasMessages">
    <article v-for="message in uiStore.messages" :key="message.id" class="message" :class="`message-${message.type}`">
      <p>{{ message.text }}</p>
      <button @click="uiStore.dismissMessage(message.id)">Dismiss</button>
    </article>
  </aside>
</template>
