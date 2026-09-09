import { ref } from 'vue'
import { fetchKeyUsage } from '../api/key'

// Module-level so every component that cares about usage (header + upload
// zone) shares one source of truth without prop-drilling or a store lib.
const usage = ref(null) // { uses, usage_limit, total_size, usage_limit_mb }
const loading = ref(false)

async function refresh() {
  loading.value = true
  try {
    usage.value = await fetchKeyUsage()
  } finally {
    loading.value = false
  }
}

export function useKeyUsage() {
  return { usage, loading, refresh }
}
