/**
 * navigator.clipboard only exists on a secure origin (https, or localhost) -- the docker
 * dev stack is served over plain http on a LAN address, where it's undefined. The
 * deprecated execCommand path is the fallback that still works there.
 *
 * Returns true when the text made it to the clipboard.
 */
export async function copyText(text) {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // fall through to the legacy path below
  }

  try {
    const el = document.createElement('textarea')
    el.value = text
    // Off-screen rather than hidden: execCommand('copy') needs a selectable,
    // rendered element, so display:none would silently copy nothing.
    el.style.position = 'fixed'
    el.style.left = '-9999px'
    document.body.appendChild(el)
    el.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(el)
    return ok
  } catch {
    return false
  }
}
