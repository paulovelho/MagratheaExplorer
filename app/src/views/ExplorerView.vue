<script setup>
import { ref, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import AppHeader from '../components/AppHeader.vue'
import Breadcrumbs from '../components/Breadcrumbs.vue'
import FileList from '../components/FileList.vue'
import UploadZone from '../components/UploadZone.vue'
import ShareDialog from '../components/ShareDialog.vue'
import { useBreadcrumbs } from '../composables/useBreadcrumbs'
import { useKeyUsage } from '../composables/useKeyUsage'
import { fetchFolders, createFolder, renameFolder, deleteFolder } from '../api/folders'
import { fetchFiles, renameFile, deleteFile } from '../api/files'
import { ApiError } from '../api/client'

const props = defineProps({
  uuid: { type: String, default: undefined },
})

const router = useRouter()
const { path, push: pushBreadcrumb, popTo, syncTo } = useBreadcrumbs()
const { refresh: refreshUsage } = useKeyUsage()

// vue-router resolves the omitted optional :uuid? segment to '' (not
// undefined) here, so both must map to root.
const folderUuid = computed(() => props.uuid || null)

const folders = ref([])
const files = ref([])
const loading = ref(false)
const errorMessage = ref('')

const creatingFolder = ref(false)
const newFolderName = ref('')

// { kind: 'folder'|'file', item } while the share dialog is open, null otherwise.
const shareTarget = ref(null)

// The `autofocus` HTML attribute only fires on the initial page parse, not
// when Vue inserts the element into an already-mounted page (e.g. this
// input appearing on "New folder" click) -- this directive focuses it.
const vFocus = {
  mounted: (el) => el.focus(),
}

async function loadContents() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [folderList, fileList] = await Promise.all([
      fetchFolders(folderUuid.value ?? undefined),
      fetchFiles(folderUuid.value ?? undefined),
    ])
    folders.value = [...folderList].sort((a, b) => a.name.localeCompare(b.name))
    files.value = [...fileList].sort((a, b) => a.name.localeCompare(b.name))
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load folder contents.'
  } finally {
    loading.value = false
  }
}

async function load() {
  await syncTo(folderUuid.value)
  await loadContents()
}

watch(folderUuid, load, { immediate: true })

function openFolder(folder) {
  pushBreadcrumb(folder)
  router.push({ name: 'explorer', params: { uuid: folder.uuid } })
}

function navigateBreadcrumb(crumb, index) {
  popTo(index)
  if (crumb.uuid == null) {
    router.push({ name: 'explorer', params: {} })
  } else {
    router.push({ name: 'explorer', params: { uuid: crumb.uuid } })
  }
}

async function handleRenameFolder(folder, name) {
  try {
    await renameFolder(folder.uuid, name)
    await loadContents()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to rename folder.'
  }
}

async function handleDeleteFolder(folder) {
  try {
    await deleteFolder(folder.uuid)
    await loadContents()
  } catch (err) {
    if (err instanceof ApiError && err.status === 400) {
      errorMessage.value = 'That folder is not empty and cannot be deleted.'
    } else {
      errorMessage.value = err.message || 'Failed to delete folder.'
    }
  }
}

async function handleRenameFile(file, name) {
  try {
    await renameFile(file.uuid, name)
    await loadContents()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to rename file.'
  }
}

async function handleDeleteFile(file) {
  try {
    await deleteFile(file.uuid)
    await loadContents()
    refreshUsage()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to delete file.'
  }
}

function startNewFolder() {
  creatingFolder.value = true
  newFolderName.value = ''
}

async function submitNewFolder() {
  // Guards against a double-submit: pressing Enter hides the input, and
  // removing a focused element fires a native blur, re-invoking this via
  // @blur with the same stale value.
  if (!creatingFolder.value) return
  creatingFolder.value = false
  const name = newFolderName.value.trim()
  if (!name) return
  try {
    await createFolder(name, folderUuid.value ?? undefined)
    await loadContents()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to create folder.'
  }
}

function openShare(kind, item) {
  shareTarget.value = { kind, item }
}

function onUploaded() {
  loadContents()
  refreshUsage()
}
</script>

<template>
  <div class="explorer">
    <AppHeader />
    <Breadcrumbs :path="path" @navigate="navigateBreadcrumb" />

    <div class="toolbar">
      <template v-if="creatingFolder">
        <input
          v-model="newFolderName"
          v-focus
          class="new-folder-input"
          placeholder="Folder name"
          @keyup.enter="submitNewFolder"
          @keyup.esc="creatingFolder = false"
          @blur="submitNewFolder"
        />
      </template>
      <button v-else class="new-folder-btn" @click="startNewFolder">New folder</button>
    </div>

    <p v-if="errorMessage" class="error-banner">{{ errorMessage }}</p>

    <UploadZone :folder-id="folderUuid" @uploaded="onUploaded" />

    <p v-if="loading" class="loading">Loading…</p>
    <FileList
      v-else
      :folders="folders"
      :files="files"
      @open-folder="openFolder"
      @rename-folder="handleRenameFolder"
      @delete-folder="handleDeleteFolder"
      @rename-file="handleRenameFile"
      @delete-file="handleDeleteFile"
      @share="openShare"
    />

    <ShareDialog
      v-if="shareTarget"
      :kind="shareTarget.kind"
      :item="shareTarget.item"
      @close="shareTarget = null"
    />
  </div>
</template>

<style scoped>
.explorer {
  min-height: 100vh;
  background: #fff;
}

.toolbar {
  padding: 0.75rem 1.5rem 0;
  display: flex;
}

.new-folder-btn {
  padding: 0.4rem 0.8rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  background: #fff;
  font-size: 0.85rem;
}

.new-folder-input {
  padding: 0.4rem 0.7rem;
  border: 1px solid #2d6cdf;
  border-radius: 6px;
  font-size: 0.9rem;
  width: 220px;
}

.error-banner {
  margin: 0.75rem 1.5rem 0;
  padding: 0.6rem 0.9rem;
  background: #fdecea;
  color: #c0392b;
  border-radius: 6px;
  font-size: 0.85rem;
}

.loading {
  padding: 2rem;
  text-align: center;
  color: #999;
}
</style>
