import { apiGetPublic } from './client'

/**
 * The public share pages. apiGetPublic sends no Authorization header -- a visitor has no
 * key, and a visitor who *does* have one stored must not have it cleared by a share
 * link's 404 (see client.js).
 */
export function fetchShare(uuid) {
  return apiGetPublic(`/shared/${uuid}`)
}

/** Browsing into a shared folder. Anything outside the shared subtree 404s server-side. */
export function fetchShareFolder(uuid, folderUuid) {
  return apiGetPublic(`/shared/${uuid}/folder/${folderUuid}`)
}
