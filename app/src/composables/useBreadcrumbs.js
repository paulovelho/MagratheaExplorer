import { ref } from 'vue'
import { fetchFolder } from '../api/folders'

// Module-level so the path stack survives across ExplorerView re-renders
// within the same session, not just within one component instance.
const path = ref([]) // [{ id: null, name: 'Root' }, { id: 5, name: 'Photos' }, ...]

/**
 * Descend into a known child folder (id + name already in hand from the
 * current listing) -- no API call needed.
 */
function push(folder) {
  path.value = [...path.value, { id: folder.id, name: folder.name }]
}

/**
 * Jump back to an ancestor already in the stack (breadcrumb click).
 */
function popTo(index) {
  path.value = path.value.slice(0, index + 1)
}

function reset() {
  path.value = [{ id: null, name: 'Root' }]
}

/**
 * Rebuild the stack by walking parent_id up to root via GET /folder/{id}.
 * Only needed for a direct load / refresh where the client has no prior
 * navigation history for this folder.
 */
async function rebuild(folderId) {
  if (folderId == null) {
    reset()
    return
  }
  const ancestors = []
  let currentId = folderId
  while (currentId != null) {
    const folder = await fetchFolder(currentId)
    // The real root folder row is represented by the synthetic { id: null,
    // name: 'Root' } crumb below, not by its own (implementation-detail)
    // name -- stop without adding it as a separate ancestor.
    if (folder.is_root) break
    ancestors.unshift({ id: folder.id, name: folder.name })
    currentId = folder.parent_id
  }
  path.value = [{ id: null, name: 'Root' }, ...ancestors]
}

/**
 * Ensure the stack's tail matches folderId -- reuses the existing stack if
 * it already ends there (breadcrumb/back navigation), otherwise falls back
 * to rebuild().
 */
async function syncTo(folderId) {
  const tail = path.value[path.value.length - 1]
  if (tail && tail.id === (folderId ?? null)) return
  const idx = path.value.findIndex((p) => p.id === (folderId ?? null))
  if (idx !== -1) {
    popTo(idx)
    return
  }
  await rebuild(folderId)
}

export function useBreadcrumbs() {
  return { path, push, popTo, reset, rebuild, syncTo }
}
