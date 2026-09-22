import { apiGet, apiPut, apiDelete, apiPostForm, getKey } from './client'

export function fetchFiles(folderUuid) {
  const qs = folderUuid != null ? `?folder_uuid=${encodeURIComponent(folderUuid)}` : ''
  return apiGet(`/files${qs}`)
}

export function fetchFile(uuid) {
  return apiGet(`/file/${uuid}`)
}

export function renameFile(uuid, name) {
  return apiPut(`/file/${uuid}`, { name })
}

export function deleteFile(uuid) {
  return apiDelete(`/file/${uuid}`)
}

/**
 * Uploads a file via XHR (not fetch) so upload progress is observable.
 * onProgress receives a 0-100 percentage.
 */
export function uploadFile(file, folderUuid, { onProgress } = {}) {
  return new Promise((resolve, reject) => {
    const formData = new FormData()
    formData.append('file', file)
    if (folderUuid != null) formData.append('folder_uuid', folderUuid)

    const xhr = new XMLHttpRequest()
    xhr.open('POST', '/api/v1/files')
    const key = getKey()
    if (key) xhr.setRequestHeader('Authorization', `Bearer ${key}`)

    xhr.upload.onprogress = (event) => {
      if (onProgress && event.lengthComputable) {
        onProgress(Math.round((event.loaded / event.total) * 100))
      }
    }

    xhr.onload = () => {
      let data = null
      try {
        data = JSON.parse(xhr.responseText)
      } catch {
        // ignore
      }
      // Every response the framework returns is wrapped as { success, data }.
      // Some framework error paths ship success:false with an HTTP 200, so
      // `success` -- not just xhr.status -- is the real signal.
      if (xhr.status >= 200 && xhr.status < 300 && data?.success === true) {
        resolve(data.data)
      } else {
        const message = data?.data?.message || `Upload failed (${xhr.status})`
        reject(new Error(message))
      }
    }

    xhr.onerror = () => reject(new Error('Network error during upload'))

    xhr.send(formData)
  })
}
