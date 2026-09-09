<script setup>
import { onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { clearKey } from '../api/client'
import { useKeyUsage } from '../composables/useKeyUsage'
import { formatBytes } from '../utils/format'

const router = useRouter()
const { usage, refresh } = useKeyUsage()

onMounted(() => {
  if (!usage.value) refresh()
})

const usageLabel = computed(() => {
  if (!usage.value) return ''
  const used = formatBytes(usage.value.total_size)
  if (usage.value.usage_limit_mb) {
    return `${used} of ${usage.value.usage_limit_mb} MB used`
  }
  return `${used} used`
})

function logout() {
  clearKey()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="app-header">
    <h1>Magrathea Explorer</h1>
    <div class="right">
      <span v-if="usageLabel" class="usage">{{ usageLabel }}</span>
      <button class="logout" @click="logout">Log out</button>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1.5rem;
  background: #fff;
  border-bottom: 1px solid #e0e0e0;
}

h1 {
  font-size: 1.1rem;
  margin: 0;
}

.right {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.usage {
  font-size: 0.85rem;
  color: #666;
}

.logout {
  background: none;
  border: 1px solid #ccc;
  border-radius: 6px;
  padding: 0.35rem 0.7rem;
  font-size: 0.85rem;
}
</style>
