<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  uploading?: boolean
  maxSizeBytes?: number
  hint?: string
  /** Když true, dropzone zmizí jakmile user začne psát do formuláře (anti-confusion) */
  hideWhenInteracted?: boolean
}>(), {
  uploading: false,
  maxSizeBytes: 20 * 1024 * 1024,
  hideWhenInteracted: false,
})

const emit = defineEmits<{
  'file-dropped': [file: File]
  'error': [code: string, message: string]
}>()

const { t } = useI18n()
const isDragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

function onDragEnter(e: DragEvent) {
  e.preventDefault()
  if (props.uploading) return
  isDragging.value = true
}
function onDragOver(e: DragEvent) {
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}
function onDragLeave(e: DragEvent) {
  // jen pokud opouštíme zónu, ne dítě
  if (e.target === e.currentTarget) isDragging.value = false
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  isDragging.value = false
  if (props.uploading) return
  const file = e.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}

function onPick() {
  if (props.uploading) return
  fileInput.value?.click()
}

function onFileInput(e: Event) {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) handleFile(file)
  // reset, aby šlo vybrat ten samý soubor znovu po chybě
  target.value = ''
}

function handleFile(file: File) {
  // Klient-side validation; server samozřejmě udělá auto-magic-bytes check znovu.
  if (file.size > props.maxSizeBytes) {
    emit('error', 'file_too_large',
      t('purchase_invoice.pdf.upload_error', { error: `> ${Math.round(props.maxSizeBytes / 1024 / 1024)} MiB` }))
    return
  }
  // Akceptujeme PDF, obrázky (fotka z telefonu → server konvertuje na PDF),
  // ISDOC / ISDOCX balíček (server rozbalí + naparsuje), NEBO empty type
  // (některé browsery type=""). Server validuje magic bytes znovu.
  const accepted = !file.type || file.type === 'application/pdf' || file.type.startsWith('image/') ||
    /\.(pdf|jpe?g|png|webp|heic|heif|gif|bmp|isdoc|isdocx)$/i.test(file.name)
  if (!accepted) {
    emit('error', 'invalid_pdf', t('purchase_invoice.pdf.invalid_pdf'))
    return
  }
  emit('file-dropped', file)
}

const cls = computed(() => {
  if (props.uploading) return 'border-primary-300 bg-primary-50/50 cursor-wait'
  if (isDragging.value) return 'border-primary-500 bg-primary-50'
  return 'border-neutral-300 bg-neutral-50 hover:border-primary-400 hover:bg-primary-50/30 cursor-pointer'
})
</script>

<template>
  <div
    class="rounded-lg border-2 border-dashed transition-colors px-4 py-6 text-center"
    :class="cls"
    @dragenter="onDragEnter"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop"
    @click="onPick"
    role="button"
    tabindex="0"
    @keydown.enter.prevent="onPick"
    @keydown.space.prevent="onPick"
  >
    <input
      ref="fileInput"
      type="file"
      accept="application/pdf,.pdf,image/*,.isdoc,.isdocx"
      class="hidden"
      @change="onFileInput"
      :disabled="uploading"
    />

    <div v-if="uploading" class="flex items-center justify-center gap-2 text-sm text-primary-700">
      <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
        <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
      </svg>
      <span>{{ t('purchase_invoice.pdf.uploading') }}</span>
    </div>
    <div v-else>
      <svg class="w-8 h-8 mx-auto mb-2 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6m-7-2a4 4 0 0 1 0-8 5 5 0 0 1 9.584-1.06A4 4 0 0 1 19 14" />
      </svg>
      <p class="text-sm text-neutral-700">
        {{ isDragging ? t('purchase_invoice.pdf.dropzone_active') : (hint || t('purchase_invoice.pdf.dropzone_hint')) }}
      </p>
      <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.pdf.max_size_hint') }}</p>
    </div>
  </div>
</template>
