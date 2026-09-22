<script setup>
import { ref, computed, onMounted } from 'vue'
import { createShare, fetchShares, deleteShare } from '../api/shares'
import { copyText } from '../utils/clipboard'
import { formatDate } from '../utils/format'

const props = defineProps({
  // 'folder' | 'file'
  kind: { type: String, required: true },
  item: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const links = ref([])
const loading = ref(true)
const creating = ref(false)
const errorMessage = ref('')
const copiedUuid = ref(null)
const confirmDeleteUuid = ref(null)

const target = computed(() =>
  props.kind === 'folder' ? { folderUuid: props.item.uuid } : { fileUuid: props.item.uuid }
)

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    links.value = await fetchShares(target.value)
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load links for this item.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function create() {
  creating.value = true
  errorMessage.value = ''
  try {
    const share = await createShare(target.value)
    links.value = [share, ...links.value]
    await copy(share)
  } catch (err) {
    errorMessage.value = err.message || 'Failed to create a link.'
  } finally {
    creating.value = false
  }
}

async function copy(share) {
  const ok = await copyText(share.url)
  if (ok) {
    copiedUuid.value = share.uuid
    setTimeout(() => {
      if (copiedUuid.value === share.uuid) copiedUuid.value = null
    }, 2000)
  } else {
    errorMessage.value = 'Could not copy automatically — select the link and copy it.'
  }
}

async function remove(share) {
  confirmDeleteUuid.value = null
  errorMessage.value = ''
  try {
    await deleteShare(share.uuid)
    links.value = links.value.filter((l) => l.uuid !== share.uuid)
  } catch (err) {
    errorMessage.value = err.message || 'Failed to delete the link.'
  }
}
</script>

<template>
  <div class="backdrop" @click.self="emit('close')">
    <div class="dialog">
      <header class="dialog-header">
        <h2>Share “{{ item.name }}”</h2>
        <button class="close" aria-label="Close" @click="emit('close')">×</button>
      </header>

      <p class="hint">
        Anyone with the link can open
        {{ kind === 'folder' ? 'this folder and everything inside it' : 'this file' }} —
        no login needed.
      </p>

      <p v-if="errorMessage" class="error-banner">{{ errorMessage }}</p>

      <button class="primary" :disabled="creating" @click="create">
        {{ creating ? 'Creating…' : 'Create link' }}
      </button>

      <p v-if="loading" class="muted">Loading…</p>
      <p v-else-if="!links.length" class="muted">No links for this item yet.</p>

      <ul v-else class="links">
        <li v-for="link in links" :key="link.uuid" class="link-row">
          <input class="link-input" :value="link.url" readonly @focus="$event.target.select()" />
          <div class="link-meta">
            <span>{{ link.views }} view{{ link.views === 1 ? '' : 's' }}</span>
            <span>· created {{ formatDate(link.created_at) }}</span>
          </div>
          <div class="link-actions">
            <button class="link-btn" @click="copy(link)">
              {{ copiedUuid === link.uuid ? 'Copied!' : 'Copy' }}
            </button>
            <template v-if="confirmDeleteUuid === link.uuid">
              <span class="confirm-text">Delete?</span>
              <button class="link-btn danger" @click="remove(link)">Yes</button>
              <button class="link-btn" @click="confirmDeleteUuid = null">No</button>
            </template>
            <button v-else class="link-btn danger" @click="confirmDeleteUuid = link.uuid">
              Delete
            </button>
          </div>
        </li>
      </ul>

      <footer class="dialog-footer">
        <router-link :to="{ name: 'shares' }" class="all-links">All shared links →</router-link>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 50;
}

.dialog {
  background: #fff;
  border-radius: 10px;
  padding: 1.25rem;
  width: 100%;
  max-width: 540px;
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}

.dialog-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

h2 {
  font-size: 1.05rem;
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.close {
  background: none;
  border: none;
  font-size: 1.4rem;
  line-height: 1;
  color: #666;
  padding: 0 0.2rem;
}

.hint {
  font-size: 0.85rem;
  color: #666;
  margin: 0.5rem 0 0.9rem;
}

.primary {
  padding: 0.45rem 0.9rem;
  border: none;
  border-radius: 6px;
  background: #2d6cdf;
  color: #fff;
  font-size: 0.9rem;
}

.primary:disabled {
  opacity: 0.6;
}

.muted {
  color: #999;
  font-size: 0.85rem;
  margin: 1rem 0 0;
}

.links {
  list-style: none;
  padding: 0;
  margin: 1rem 0 0;
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.link-row {
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 0.6rem;
}

.link-input {
  width: 100%;
  padding: 0.35rem 0.5rem;
  border: 1px solid #ddd;
  border-radius: 5px;
  font-size: 0.8rem;
  color: #333;
  background: #fafafa;
}

.link-meta {
  display: flex;
  gap: 0.4rem;
  font-size: 0.78rem;
  color: #777;
  margin-top: 0.35rem;
}

.link-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.4rem;
  font-size: 0.85rem;
}

.link-btn {
  background: none;
  border: none;
  color: #2d6cdf;
  padding: 0.1rem 0.3rem;
}

.link-btn.danger {
  color: #c0392b;
}

.confirm-text {
  color: #666;
  font-size: 0.85rem;
}

.error-banner {
  padding: 0.5rem 0.7rem;
  background: #fdecea;
  color: #c0392b;
  border-radius: 6px;
  font-size: 0.82rem;
  margin: 0 0 0.8rem;
}

.dialog-footer {
  margin-top: 1.1rem;
  padding-top: 0.8rem;
  border-top: 1px solid #eee;
}

.all-links {
  font-size: 0.85rem;
  color: #2d6cdf;
  text-decoration: none;
}
</style>
