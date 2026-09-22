import { ref } from 'vue'
import { fetchSettings } from '../api/system'

export const DEFAULT_APP_NAME = 'Magrathea Explorer'

// Module-level so the name is fetched once and shared by every view that shows it.
const appName = ref(DEFAULT_APP_NAME)
let loaded = null

function load() {
  if (!loaded) {
    loaded = fetchSettings()
      .then((settings) => {
        if (settings?.app_name) appName.value = settings.app_name
        document.title = appName.value
      })
      .catch(() => {
        // Keep the default; allow a retry on the next mount.
        loaded = null
      })
  }
  return loaded
}

export function useAppName() {
  load()
  return { appName }
}
