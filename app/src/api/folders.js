import { apiGet, apiPost, apiPut, apiDelete } from './client'

export function fetchFolders(parentId) {
  const qs = parentId != null ? `?parent_id=${encodeURIComponent(parentId)}` : ''
  return apiGet(`/folders${qs}`)
}

export function fetchFolder(id) {
  return apiGet(`/folder/${id}`)
}

export function createFolder(name, parentId) {
  const body = { name }
  if (parentId != null) body.parent_id = parentId
  return apiPost('/folders', body)
}

export function renameFolder(id, name) {
  return apiPut(`/folder/${id}`, { name })
}

export function deleteFolder(id) {
  return apiDelete(`/folder/${id}`)
}
