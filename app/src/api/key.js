import { apiGet } from './client'

export function fetchKey() {
  return apiGet('/key')
}

export function fetchKeyUsage() {
  return apiGet('/key/usage')
}
