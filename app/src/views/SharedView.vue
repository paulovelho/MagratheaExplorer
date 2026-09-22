<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import SharedFileList from '../components/SharedFileList.vue'
import { fetchShare, fetchShareFolder } from '../api/publicShare'
import { getFileIcon } from '../utils/icons'
import { formatBytes } from '../utils/format'

const props = defineProps({
  uuid: { type: String, required: true },
  folderUuid: { type: String, default: undefined },
})

const router = useRouter()

// vue-router resolves the omitted optional :folderUuid? segment to '' (not undefined),
// so both must map to "the share's own root" -- same gotcha the explorer route handles.
const currentFolder = computed(() => props.folderUuid || null)

const share = ref(null)
const loading = ref(true)
// One message for every failure -- unknown uuid, deleted share, dead key, a folder
// outside the shared subtree. A visitor learns nothing about which it was.
const failed = ref(false)

async function load() {
  loading.value = true
  failed.value = false
  try {
    share.value = currentFolder.value
      ? await fetchShareFolder(props.uuid, currentFolder.value)
      : await fetchShare(props.uuid)
  } catch {
    share.value = null
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch([() => props.uuid, currentFolder], load, { immediate: true })

function openFolder(folder) {
  router.push({ name: 'shared', params: { uuid: props.uuid, folderUuid: folder.uuid } })
}

function openCrumb(crumb, index) {
  // The first crumb is the share root, which is the bare /s/:uuid route.
  if (index === 0) {
    router.push({ name: 'shared', params: { uuid: props.uuid } })
  } else {
    router.push({ name: 'shared', params: { uuid: props.uuid, folderUuid: crumb.uuid } })
  }
}
</script>

<template>
  <div class="shared">
    <!-- Its own minimal header, NOT AppHeader: that one calls /key/usage and renders a
         "Log out" button. Nothing on this page may show the owner's key or identity, or
         link back into /app. -->
    <header class="shared-header">
      <span class="brand">Shared</span>
      <span v-if="share" class="title">{{ share.name }}</span>
    </header>

    <p v-if="loading" class="loading">Loading…</p>

    <p v-else-if="failed" class="invalid">This link is not valid.</p>

    <template v-else-if="share.type === 'file'">
      <div class="file-card">
        <img
          v-if="share.file.thumbnail_url"
          :src="share.file.thumbnail_url"
          class="preview"
          alt=""
        />
        <span v-else class="preview-icon">{{ getFileIcon(share.file) }}</span>
        <h1>{{ share.file.name }}</h1>
        <p class="file-meta">{{ formatBytes(share.file.size) }} · {{ share.file.mime_type }}</p>
        <a class="download" :href="share.file.url" target="_blank" rel="noopener">Download</a>
      </div>
    </template>

    <template v-else>
      <nav class="breadcrumbs">
        <template v-for="(crumb, index) in share.path" :key="crumb.uuid">
          <span v-if="index > 0" class="sep">/</span>
          <button
            class="crumb"
            :class="{ current: index === share.path.length - 1 }"
            :disabled="index === share.path.length - 1"
            @click="openCrumb(crumb, index)"
          >
            {{ crumb.name }}
          </button>
        </template>
      </nav>

      <SharedFileList
        :folders="share.folders"
        :files="share.files"
        @open-folder="openFolder"
      />
    </template>
  </div>
</template>

<style scoped>
.shared {
  min-height: 100vh;
  background: #fff;
}

.shared-header {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  padding: 0.75rem 1.5rem;
  border-bottom: 1px solid #e0e0e0;
}

.brand {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #999;
}

.title {
  font-size: 1.05rem;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.loading {
  padding: 3rem 1.5rem;
  text-align: center;
  color: #999;
}

.invalid {
  padding: 4rem 1.5rem;
  text-align: center;
  color: #666;
  font-size: 1rem;
}

.file-card {
  max-width: 420px;
  margin: 3rem auto;
  padding: 2rem 1.5rem;
  border: 1px solid #eee;
  border-radius: 10px;
  text-align: center;
}

.preview {
  max-width: 100%;
  max-height: 260px;
  border-radius: 6px;
}

.preview-icon {
  font-size: 3.5rem;
}

h1 {
  font-size: 1.05rem;
  margin: 1rem 0 0.35rem;
  overflow-wrap: anywhere;
}

.file-meta {
  font-size: 0.85rem;
  color: #777;
  margin: 0 0 1.25rem;
}

.download {
  display: inline-block;
  padding: 0.5rem 1.1rem;
  border-radius: 6px;
  background: #2d6cdf;
  color: #fff;
  font-size: 0.9rem;
  text-decoration: none;
}

.breadcrumbs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;
  padding: 0.75rem 1.5rem;
  font-size: 0.9rem;
}

.crumb {
  background: none;
  border: none;
  padding: 0.15rem 0.3rem;
  color: #2d6cdf;
  font-size: 0.9rem;
  border-radius: 4px;
}

.crumb:hover:not(:disabled) {
  background: #eef2fb;
}

.crumb.current {
  color: #1a1a1a;
  font-weight: 600;
  cursor: default;
}

.sep {
  color: #999;
}
</style>
