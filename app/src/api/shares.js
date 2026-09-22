import { apiGet, apiPost, apiDelete } from './client'

/** Exactly one of fileUuid / folderUuid -- the API rejects both or neither with a 4001. */
export function createShare({ fileUuid, folderUuid }) {
  const body = {}
  if (fileUuid != null) body.file_uuid = fileUuid
  if (folderUuid != null) body.folder_uuid = folderUuid
  return apiPost('/shares', body)
}

/** All of this key's shares, or just the ones pointing at one target. */
export function fetchShares({ fileUuid, folderUuid } = {}) {
  const params = new URLSearchParams()
  if (fileUuid != null) params.set('file_uuid', fileUuid)
  if (folderUuid != null) params.set('folder_uuid', folderUuid)
  const qs = params.toString()
  return apiGet(`/shares${qs ? `?${qs}` : ''}`)
}

export function deleteShare(uuid) {
  return apiDelete(`/share/${uuid}`)
}
