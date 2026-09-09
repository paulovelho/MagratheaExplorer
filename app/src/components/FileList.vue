<script setup>
import { ref } from 'vue'
import { getFileIcon, FOLDER_ICON } from '../utils/icons'
import { formatBytes, formatDate } from '../utils/format'

const props = defineProps({
  folders: { type: Array, required: true },
  files: { type: Array, required: true },
})

const emit = defineEmits(['open-folder', 'rename-folder', 'delete-folder', 'rename-file', 'delete-file'])

const editingKey = ref(null) // `folder:5` or `file:12`
const editValue = ref('')
const confirmDeleteKey = ref(null)

// The `autofocus` HTML attribute only fires on the initial page parse, not
// when Vue inserts the element into an already-mounted page (e.g. a rename
// input appearing on click) -- this directive focuses it explicitly.
const vFocus = {
  mounted: (el) => {
    el.focus()
    el.select()
  },
}

function startRename(kind, item) {
  editingKey.value = `${kind}:${item.id}`
  editValue.value = item.name
  confirmDeleteKey.value = null
}

function cancelRename() {
  editingKey.value = null
}

function submitRename(kind, item) {
  // Guards against a double-submit: pressing Enter hides the input, and
  // removing a focused element fires a native blur, re-invoking this via
  // @blur with the same stale value.
  const key = `${kind}:${item.id}`
  if (editingKey.value !== key) return
  editingKey.value = null
  const name = editValue.value.trim()
  if (!name || name === item.name) return
  emit(kind === 'folder' ? 'rename-folder' : 'rename-file', item, name)
}

function askDelete(kind, item) {
  confirmDeleteKey.value = `${kind}:${item.id}`
  editingKey.value = null
}

function confirmDelete(kind, item) {
  confirmDeleteKey.value = null
  emit(kind === 'folder' ? 'delete-folder' : 'delete-file', item)
}

function cancelDelete() {
  confirmDeleteKey.value = null
}
</script>

<template>
  <div class="file-list">
    <div v-if="!folders.length && !files.length" class="empty">
      This folder is empty.
    </div>

    <div
      v-for="folder in folders"
      :key="`folder:${folder.id}`"
      class="row"
    >
      <button class="name-cell" @click="emit('open-folder', folder)">
        <span class="icon">{{ FOLDER_ICON }}</span>
        <input
          v-if="editingKey === `folder:${folder.id}`"
          v-model="editValue"
          v-focus
          class="rename-input"
          @click.stop
          @keyup.enter="submitRename('folder', folder)"
          @keyup.esc="cancelRename"
          @blur="submitRename('folder', folder)"
        />
        <span v-else class="name">{{ folder.name }}</span>
      </button>
      <span class="meta">—</span>
      <span class="meta">{{ formatDate(folder.created_at) }}</span>
      <div class="actions">
        <template v-if="confirmDeleteKey === `folder:${folder.id}`">
          <span class="confirm-text">Delete?</span>
          <button class="link danger" @click="confirmDelete('folder', folder)">Yes</button>
          <button class="link" @click="cancelDelete">No</button>
        </template>
        <template v-else>
          <button class="link" @click="startRename('folder', folder)">Rename</button>
          <button class="link danger" @click="askDelete('folder', folder)">Delete</button>
        </template>
      </div>
    </div>

    <div
      v-for="file in files"
      :key="`file:${file.id}`"
      class="row"
    >
      <a class="name-cell" :href="file.url" target="_blank" rel="noopener">
        <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="thumb" alt="" />
        <span v-else class="icon">{{ getFileIcon(file) }}</span>
        <input
          v-if="editingKey === `file:${file.id}`"
          v-model="editValue"
          v-focus
          class="rename-input"
          @click.stop.prevent
          @keyup.enter="submitRename('file', file)"
          @keyup.esc="cancelRename"
          @blur="submitRename('file', file)"
        />
        <span v-else class="name">{{ file.name }}</span>
      </a>
      <span class="meta">{{ formatBytes(file.size) }}</span>
      <span class="meta">{{ formatDate(file.created_at) }}</span>
      <div class="actions">
        <template v-if="confirmDeleteKey === `file:${file.id}`">
          <span class="confirm-text">Delete?</span>
          <button class="link danger" @click="confirmDelete('file', file)">Yes</button>
          <button class="link" @click="cancelDelete">No</button>
        </template>
        <template v-else>
          <button class="link" @click.prevent="startRename('file', file)">Rename</button>
          <button class="link danger" @click.prevent="askDelete('file', file)">Delete</button>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.file-list {
  display: flex;
  flex-direction: column;
}

.empty {
  padding: 2rem;
  text-align: center;
  color: #999;
}

.row {
  display: grid;
  grid-template-columns: 1fr 90px 160px 150px;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1.5rem;
  border-bottom: 1px solid #eee;
}

.row:hover {
  background: #fafafa;
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  background: none;
  border: none;
  text-align: left;
  padding: 0;
  color: inherit;
  text-decoration: none;
  overflow: hidden;
}

.icon {
  font-size: 1.2rem;
  flex-shrink: 0;
}

.thumb {
  width: 28px;
  height: 28px;
  object-fit: cover;
  border-radius: 4px;
  flex-shrink: 0;
}

.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rename-input {
  padding: 0.15rem 0.4rem;
  border: 1px solid #2d6cdf;
  border-radius: 4px;
  font-size: 0.95rem;
  width: 100%;
}

.meta {
  font-size: 0.85rem;
  color: #666;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.link {
  background: none;
  border: none;
  color: #2d6cdf;
  padding: 0.1rem 0.3rem;
}

.link.danger {
  color: #c0392b;
}

.confirm-text {
  color: #666;
}
</style>
