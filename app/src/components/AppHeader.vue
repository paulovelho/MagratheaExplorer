<script setup>
import { onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { clearKey } from '../api/client'
import { useKeyUsage } from '../composables/useKeyUsage'
import { formatBytes } from '../utils/format'
import logoUrl from '../assets/logo.png'

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
    <!-- A link, not plain text: /app/shares is a full page of its own, so the title is
         the way back to the file list from it. -->
    <router-link :to="{ name: 'explorer', params: {} }" class="title">
      <img :src="logoUrl" class="logo" alt="" />
      <h1>Magrathea Explorer</h1>
    </router-link>
    <div class="right">
      <router-link :to="{ name: 'shares' }" class="nav-link">Shared links</router-link>
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

.title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: inherit;
  text-decoration: none;
}

.logo {
  width: 28px;
  height: 28px;
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

.nav-link {
  font-size: 0.85rem;
  color: #2d6cdf;
  text-decoration: none;
}

.nav-link:hover {
  text-decoration: underline;
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
