const EXTENSION_ICONS = {
  pdf: '📕',
  doc: '📄', docx: '📄', odt: '📄', rtf: '📄',
  xls: '📊', xlsx: '📊', csv: '📊', ods: '📊',
  ppt: '📽️', pptx: '📽️', odp: '📽️',
  zip: '🗜️', rar: '🗜️', '7z': '🗜️', tar: '🗜️', gz: '🗜️',
  mp3: '🎵', wav: '🎵', flac: '🎵', ogg: '🎵', m4a: '🎵', aac: '🎵',
  mp4: '🎬', mov: '🎬', avi: '🎬', mkv: '🎬', webm: '🎬',
  jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️', svg: '🖼️', bmp: '🖼️',
  txt: '📝', md: '📝',
  json: '🧾', xml: '🧾', yaml: '🧾', yml: '🧾',
  html: '💻', css: '💻', js: '💻', ts: '💻', vue: '💻',
  php: '💻', py: '💻', java: '💻', c: '💻', cpp: '💻', sh: '💻',
}

const FILE_TYPE_FALLBACK = {
  image: '🖼️',
  audio: '🎵',
  video: '🎬',
  document: '📄',
  other: '📦',
}

export function getFileIcon(file) {
  const ext = (file.extension || '').toLowerCase()
  return EXTENSION_ICONS[ext] || FILE_TYPE_FALLBACK[file.file_type] || '📦'
}

export const FOLDER_ICON = '📁'
