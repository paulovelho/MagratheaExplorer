<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import { fetchShares, deleteShare } from '../api/shares'
import { fetchFile } from '../api/files'
import { copyText } from '../utils/clipboard'
import { formatDate } from '../utils/format'
import { FOLDER_ICON } from '../utils/icons'

const router = useRouter()

const shares = ref([])
const loading = ref(true)
const errorMessage = ref('')
const copiedUuid = ref(null)
const confirmDeleteUuid = ref(null)

onMounted(async () => {
  try {
    shares.value = await fetchShares()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load your shared links.'
  } finally {
    loading.value = false
  }
})

async function copy(share) {
  const ok = await copyText(share.url)
  if (ok) {
    copiedUuid.value = share.uuid
    setTimeout(() => {
      if (copiedUuid.value === share.uuid) copiedUuid.value = null
    }, 2000)
  } else {
    errorMessage.value = 'Could not copy automatically — open the link and copy it from there.'
  }
}

async function remove(share) {
  confirmDeleteUuid.value = null
  errorMessage.value = ''
  try {
    await deleteShare(share.uuid)
    shares.value = shares.value.filter((s) => s.uuid !== share.uuid)
  } catch (err) {
    errorMessage.value = err.message || 'Failed to delete the link.'
  }
}

/**
 * Navigates into the explorer at whatever the share points at: a folder share opens the
 * folder itself, a file share opens its containing folder (there is no per-file view),
 * which costs one extra lookup for the file's folder_uuid.
 */
async function openInExplorer(share) {
  try {
    if (share.target_type === 'folder') {
      router.push({ name: 'explorer', params: { uuid: share.folder_uuid } })
      return
    }
    const file = await fetchFile(share.file_uuid)
    router.push({ name: 'explorer', params: { uuid: file.folder_uuid } })
  } catch (err) {
    errorMessage.value = err.message || 'Could not open that item.'
  }
}
</script>

<template>
  <div class="shares">
    <AppHeader />

    <div class="page">
      <h2>Shared links</h2>
      <p class="hint">
        Anyone holding one of these links can open what it points at without logging in.
        Deleting a link stops its page working — file URLs someone already copied keep
        working until the file itself is deleted.
      </p>

      <p v-if="errorMessage" class="error-banner">{{ errorMessage }}</p>

      <p v-if="loading" class="loading">Loading…</p>

      <p v-else-if="!shares.length" class="empty">
        You haven't shared anything yet. Use the Share action on any file or folder.
      </p>

      <table v-else class="share-table">
        <thead>
          <tr>
            <th>Target</th>
            <th>Link</th>
            <th>Views</th>
            <th>Created</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="share in shares" :key="share.uuid">
            <td>
              <button class="target" @click="openInExplorer(share)">
                <span class="icon">{{ share.target_type === 'folder' ? FOLDER_ICON : '📄' }}</span>
                <span class="target-name">{{ share.target_name }}</span>
              </button>
              <span class="chip">{{ share.target_type === 'folder' ? 'Folder' : 'File' }}</span>
            </td>
            <td class="link-cell">
              <span class="url" :title="share.url">{{ share.url }}</span>
              <button class="link" @click="copy(share)">
                {{ copiedUuid === share.uuid ? 'Copied!' : 'Copy' }}
              </button>
            </td>
            <td class="meta">
              {{ share.views }}
              <span class="sub">{{
                share.last_viewed_at ? formatDate(share.last_viewed_at) : 'Never opened'
              }}</span>
            </td>
            <td class="meta">{{ formatDate(share.created_at) }}</td>
            <td class="actions">
              <template v-if="confirmDeleteUuid === share.uuid">
                <span class="confirm-text">
                  Delete this link? The share page stops working. Direct file URLs already
                  copied keep working.
                </span>
                <button class="link danger" @click="remove(share)">Yes</button>
                <button class="link" @click="confirmDeleteUuid = null">No</button>
              </template>
              <template v-else>
                <a class="link" :href="share.url" target="_blank" rel="noopener">Open</a>
                <button class="link danger" @click="confirmDeleteUuid = share.uuid">Delete</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.shares {
  min-height: 100vh;
  background: #fff;
}

.page {
  padding: 1.25rem 1.5rem;
}

h2 {
  font-size: 1.05rem;
  margin: 0 0 0.35rem;
}

.hint {
  font-size: 0.85rem;
  color: #666;
  margin: 0 0 1.25rem;
  max-width: 62ch;
}

.error-banner {
  padding: 0.6rem 0.9rem;
  background: #fdecea;
  color: #c0392b;
  border-radius: 6px;
  font-size: 0.85rem;
  margin-bottom: 1rem;
}

.loading,
.empty {
  padding: 2rem 0;
  color: #999;
}

.share-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

th {
  text-align: left;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #888;
  font-weight: 600;
  padding: 0 0.5rem 0.5rem;
  border-bottom: 1px solid #e0e0e0;
}

th.right {
  text-align: right;
}

td {
  padding: 0.6rem 0.5rem;
  border-bottom: 1px solid #eee;
  vertical-align: top;
}

.target {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  background: none;
  border: none;
  padding: 0;
  color: #2d6cdf;
  font-size: 0.9rem;
  max-width: 100%;
  overflow: hidden;
}

.target-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chip {
  display: inline-block;
  margin-left: 0.45rem;
  padding: 0.05rem 0.4rem;
  border-radius: 10px;
  background: #eef2fb;
  color: #4a5b7a;
  font-size: 0.72rem;
}

.link-cell {
  max-width: 300px;
}

.url {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #666;
  font-size: 0.8rem;
}

.meta {
  color: #666;
  font-size: 0.85rem;
  white-space: nowrap;
}

.sub {
  display: block;
  color: #999;
  font-size: 0.78rem;
}

.actions {
  text-align: right;
  white-space: normal;
}

.confirm-text {
  display: block;
  color: #666;
  font-size: 0.78rem;
  margin-bottom: 0.25rem;
}

.link {
  background: none;
  border: none;
  color: #2d6cdf;
  padding: 0.1rem 0.3rem;
  font-size: 0.85rem;
  text-decoration: none;
}

.link.danger {
  color: #c0392b;
}
</style>
