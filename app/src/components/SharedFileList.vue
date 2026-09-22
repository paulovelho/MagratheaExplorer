<script setup>
import { getFileIcon, FOLDER_ICON } from '../utils/icons'
import { formatBytes } from '../utils/format'

/**
 * Read-only twin of FileList for the public share page. The item shapes are identical
 * (the API's shared file view is a strict subset of the owner's), so the only real
 * difference is that there are no rename/delete/share actions and no owner-side
 * @open-folder semantics -- which is exactly why this isn't a prop on FileList.
 */
defineProps({
  folders: { type: Array, required: true },
  files: { type: Array, required: true },
})

const emit = defineEmits(['open-folder'])
</script>

<template>
  <div class="file-list">
    <div v-if="!folders.length && !files.length" class="empty">
      This folder is empty.
    </div>

    <button
      v-for="folder in folders"
      :key="`folder:${folder.uuid}`"
      class="row"
      @click="emit('open-folder', folder)"
    >
      <span class="name-cell">
        <span class="icon">{{ FOLDER_ICON }}</span>
        <span class="name">{{ folder.name }}</span>
      </span>
      <span class="meta">—</span>
    </button>

    <a
      v-for="file in files"
      :key="`file:${file.uuid}`"
      class="row"
      :href="file.url"
      target="_blank"
      rel="noopener"
    >
      <span class="name-cell">
        <img v-if="file.thumbnail_url" :src="file.thumbnail_url" class="thumb" alt="" />
        <span v-else class="icon">{{ getFileIcon(file) }}</span>
        <span class="name">{{ file.name }}</span>
      </span>
      <span class="meta">{{ formatBytes(file.size) }}</span>
    </a>
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
  grid-template-columns: 1fr 90px;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 1.5rem;
  border: none;
  border-bottom: 1px solid #eee;
  background: none;
  text-align: left;
  color: inherit;
  text-decoration: none;
  font-size: 1rem;
}

.row:hover {
  background: #fafafa;
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 0.6rem;
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

.meta {
  font-size: 0.85rem;
  color: #666;
}
</style>
