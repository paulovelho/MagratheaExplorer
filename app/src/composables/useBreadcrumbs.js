import { ref } from 'vue'
import { fetchFolder } from '../api/folders'

// Module-level so the path stack survives across ExplorerView re-renders
// within the same session, not just within one component instance.
const path = ref([]) // [{ uuid: null, name: 'Root' }, { uuid: '018f2a3b-...', name: 'Photos' }, ...]

/**
 * Descend into a known child folder (uuid + name already in hand from the
 * current listing) -- no API call needed.
 */
function push(folder) {
  path.value = [...path.value, { uuid: folder.uuid, name: folder.name }]
}

/**
 * Jump back to an ancestor already in the stack (breadcrumb click).
 */
function popTo(index) {
  path.value = path.value.slice(0, index + 1)
}

function reset() {
  path.value = [{ uuid: null, name: 'Root' }]
}

/**
 * Rebuild the stack by walking parent_uuid up to root via GET /folder/{uuid}.
 * Only needed for a direct load / refresh where the client has no prior
 * navigation history for this folder.
 */
async function rebuild(folderUuid) {
  if (folderUuid == null) {
    reset()
    return
  }
  const ancestors = []
  let currentUuid = folderUuid
  while (currentUuid != null) {
    const folder = await fetchFolder(currentUuid)
    // The real root folder row is represented by the synthetic { uuid: null,
    // name: 'Root' } crumb below, not by its own (implementation-detail)
    // name -- stop without adding it as a separate ancestor.
    if (folder.is_root) break
    ancestors.unshift({ uuid: folder.uuid, name: folder.name })
    currentUuid = folder.parent_uuid
  }
  path.value = [{ uuid: null, name: 'Root' }, ...ancestors]
}

/**
 * Ensure the stack's tail matches folderUuid -- reuses the existing stack if
 * it already ends there (breadcrumb/back navigation), otherwise falls back
 * to rebuild().
 */
async function syncTo(folderUuid) {
  const tail = path.value[path.value.length - 1]
  if (tail && tail.uuid === (folderUuid ?? null)) return
  const idx = path.value.findIndex((p) => p.uuid === (folderUuid ?? null))
  if (idx !== -1) {
    popTo(idx)
    return
  }
  await rebuild(folderUuid)
}

export function useBreadcrumbs() {
  return { path, push, popTo, reset, rebuild, syncTo }
}
