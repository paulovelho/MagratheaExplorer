import { apiGet, apiPost, apiPut, apiDelete } from './client'

export function fetchFolders(parentUuid) {
  const qs = parentUuid != null ? `?parent_uuid=${encodeURIComponent(parentUuid)}` : ''
  return apiGet(`/folders${qs}`)
}

export function fetchFolder(uuid) {
  return apiGet(`/folder/${uuid}`)
}

export function createFolder(name, parentUuid) {
  const body = { name }
  if (parentUuid != null) body.parent_uuid = parentUuid
  return apiPost('/folders', body)
}

export function renameFolder(uuid, name) {
  return apiPut(`/folder/${uuid}`, { name })
}

export function deleteFolder(uuid) {
  return apiDelete(`/folder/${uuid}`)
}
