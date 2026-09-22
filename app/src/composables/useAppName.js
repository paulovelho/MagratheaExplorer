export const DEFAULT_APP_NAME = 'Magrathea Explorer'

// api/src/app-index.php injects ConfigApp's app_name into this meta tag (and
// <title>) when it serves index.html, so there's nothing to fetch. Under the
// Vite dev server index.html isn't served through PHP, so it stays the default.
const appName =
  document.querySelector('meta[name="application-name"]')?.content || DEFAULT_APP_NAME

export function useAppName() {
  return { appName }
}
