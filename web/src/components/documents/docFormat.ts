import type { DocType } from '@/api/documents'

/** Barevné badge pro typ dokumentu (sjednocený design language). */
export function docTypeBadge(t: DocType): { label: string; class: string } {
  switch (t) {
    case 'pdf':   return { label: 'PDF',  class: 'bg-danger-50 text-danger-500' }
    case 'docx':  return { label: 'DOC',  class: 'bg-primary-50 text-primary-700' }
    case 'xlsx':  return { label: 'XLS',  class: 'bg-success-50 text-success-600' }
    case 'xml':   return { label: 'XML',  class: 'bg-warning-50 text-warning-600' }
    case 'zfo':   return { label: 'ZFO',  class: 'bg-primary-100 text-primary-700' }
    case 'p7s':   return { label: 'P7S',  class: 'bg-neutral-200 text-neutral-700' }
    case 'zip':   return { label: 'ZIP',  class: 'bg-warning-100 text-warning-700' }
    case 'image': return { label: 'IMG',  class: 'bg-success-100 text-success-700' }
    default:      return { label: 'FILE', class: 'bg-neutral-100 text-neutral-600' }
  }
}

export function formatBytes(n: number): string {
  if (n < 1024) return n + ' B'
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KiB'
  if (n < 1024 * 1024 * 1024) return (n / 1024 / 1024).toFixed(1) + ' MiB'
  return (n / 1024 / 1024 / 1024).toFixed(2) + ' GiB'
}

/** Lze dokument zobrazit inline (PDF / obrázek)? */
export function canInline(t: DocType): boolean {
  return t === 'pdf' || t === 'image'
}
