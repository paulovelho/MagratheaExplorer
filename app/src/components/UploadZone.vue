<script setup>
import { ref } from 'vue'
import { uploadFile } from '../api/files'

const props = defineProps({
  folderId: { type: [Number, String, null], default: null },
})

const emit = defineEmits(['uploaded'])

const fileInput = ref(null)
const dragging = ref(false)
const uploads = ref([]) // [{ key, name, progress, error }]

function pickFiles() {
  fileInput.value?.click()
}

function onFileInputChange(event) {
  handleFiles(event.target.files)
  event.target.value = ''
}

function onDrop(event) {
  dragging.value = false
  handleFiles(event.dataTransfer.files)
}

function handleFiles(fileList) {
  Array.from(fileList).forEach(startUpload)
}

function startUpload(file) {
  const key = `${file.name}-${Date.now()}-${Math.random()}`
  const entry = { key, name: file.name, progress: 0, error: null }
  uploads.value = [...uploads.value, entry]

  uploadFile(file, props.folderId, {
    onProgress: (pct) => {
      entry.progress = pct
    },
  })
    .then((uploaded) => {
      uploads.value = uploads.value.filter((u) => u.key !== key)
      emit('uploaded', uploaded)
    })
    .catch((err) => {
      entry.error = err.message || 'Upload failed'
    })
}

function dismiss(key) {
  uploads.value = uploads.value.filter((u) => u.key !== key)
}
</script>

<template>
  <div
    class="upload-zone"
    :class="{ dragging }"
    @dragover.prevent="dragging = true"
    @dragleave.prevent="dragging = false"
    @drop.prevent="onDrop"
  >
    <button class="upload-btn" @click="pickFiles">Upload files</button>
    <span class="hint">or drag files here</span>
    <input ref="fileInput" type="file" multiple hidden @change="onFileInputChange" />

    <div v-if="uploads.length" class="progress-list">
      <div v-for="u in uploads" :key="u.key" class="progress-row">
        <span class="upload-name">{{ u.name }}</span>
        <template v-if="u.error">
          <span class="upload-error">{{ u.error }}</span>
          <button class="link" @click="dismiss(u.key)">Dismiss</button>
        </template>
        <template v-else>
          <div class="bar">
            <div class="bar-fill" :style="{ width: u.progress + '%' }"></div>
          </div>
          <span class="pct">{{ u.progress }}%</span>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.upload-zone {
  margin: 1rem 1.5rem;
  padding: 1rem;
  border: 2px dashed #ccc;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.upload-zone.dragging {
  border-color: #2d6cdf;
  background: #eef2fb;
}

.upload-btn {
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 8px;
  background: #2d6cdf;
  color: #fff;
  font-weight: 600;
}

.hint {
  color: #888;
  font-size: 0.85rem;
}

.progress-list {
  flex-basis: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 0.25rem;
}

.progress-row {
  display: grid;
  grid-template-columns: 1fr 120px 40px;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.upload-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar {
  background: #e0e0e0;
  border-radius: 4px;
  height: 6px;
  overflow: hidden;
}

.bar-fill {
  background: #2d6cdf;
  height: 100%;
  transition: width 0.15s ease;
}

.pct {
  text-align: right;
  color: #666;
}

.upload-error {
  color: #c0392b;
}

.link {
  background: none;
  border: none;
  color: #2d6cdf;
  padding: 0;
}
</style>
