const BASE_URL = '/api/v1'

const KEY_STORAGE = 'magrathea_explorer_key'

export function getKey() {
  return localStorage.getItem(KEY_STORAGE)
}

export function setKey(key) {
  localStorage.setItem(KEY_STORAGE, key)
}

export function clearKey() {
  localStorage.removeItem(KEY_STORAGE)
}

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message)
    this.status = status
    this.code = code
  }
}

let onUnauthorized = null

export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

async function request(path, { method = 'GET', body, headers = {}, isForm = false } = {}) {
  const key = getKey()
  const finalHeaders = { ...headers }
  if (key) finalHeaders['Authorization'] = `Bearer ${key}`
  if (body && !isForm) finalHeaders['Content-Type'] = 'application/x-www-form-urlencoded'

  const res = await fetch(BASE_URL + path, {
    method,
    headers: finalHeaders,
    body: isForm ? body : body ? new URLSearchParams(body) : undefined,
  })

  if (res.status === 204) return null

  // Every response the framework returns is wrapped as { success, data }.
  // Some framework error paths (e.g. an unmatched route) ship success:false
  // with an HTTP 200, so `success` -- not res.ok -- is the real signal.
  let envelope = null
  try {
    envelope = await res.json()
  } catch {
    // ignore -- handled as a failure below since envelope stays null
  }

  if (!res.ok || !envelope || envelope.success !== true) {
    const code = envelope?.data?.code ?? null
    const message = envelope?.data?.message || `Request failed (${res.status})`
    if (res.status === 401 || code === 401) {
      clearKey()
      if (onUnauthorized) onUnauthorized()
    }
    throw new ApiError(res.status, code, message)
  }

  return envelope.data
}

export function apiGet(path) {
  return request(path, { method: 'GET' })
}

export function apiPost(path, body) {
  return request(path, { method: 'POST', body })
}

export function apiPut(path, body) {
  return request(path, { method: 'PUT', body })
}

export function apiDelete(path) {
  return request(path, { method: 'DELETE' })
}

export function apiPostForm(path, formData) {
  return request(path, { method: 'POST', body: formData, isForm: true })
}
