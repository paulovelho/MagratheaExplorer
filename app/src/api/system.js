import { apiGet } from './client'

export function fetchSettings() {
  return apiGet('/settings')
}
