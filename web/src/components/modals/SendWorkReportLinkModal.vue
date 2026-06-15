<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { workReportLinkApi, type WrLinkScope } from '@/api/workReportTracking'

const props = defineProps<{
  open: boolean
  scope: WrLinkScope
  entityId: number
}>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(false)
const busy = ref<'send' | 'revoke' | null>(null)
const toText = ref('')
const ccText = ref('')
const bccText = ref('')
const note = ref('')
const currentUrl = ref<string | null>(null)
const lastSentAt = ref<string | null>(null)
const lastViewedAt = ref<string | null>(null)
const copied = ref(false)

function parseEmails(s: string): string[] {
  return s.split(',').map(e => e.trim()).filter(Boolean)
}

watch(() => props.open, async (isOpen) => {
  if (!isOpen) return
  // Reset + načti příjemce a stav odkazu.
  toText.value = ''
  ccText.value = ''
  bccText.value = ''
  note.value = ''
  currentUrl.value = null
  lastSentAt.value = null
  lastViewedAt.value = null
  copied.value = false
  loading.value = true
  try {
    const [rec, status] = await Promise.all([
      workReportLinkApi.recipients(props.scope, props.entityId).catch(() => null),
      workReportLinkApi.status(props.scope, props.entityId).catch(() => null),
    ])
    if (rec) {
      toText.value = rec.to.join(', ')
      ccText.value = rec.cc.join(', ')
      bccText.value = rec.bcc.join(', ')
    }
    if (status?.exists) {
      currentUrl.value = status.url ?? null
      lastSentAt.value = status.last_sent_at ?? null
      lastViewedAt.value = status.last_viewed_at ?? null
    }
  } finally {
    loading.value = false
  }
})

async function send() {
  const to = parseEmails(toText.value)
  if (!to.length) {
    toast.error(t('workReportTracking.modal.no_recipients'))
    return
  }
  busy.value = 'send'
  try {
    const r = await workReportLinkApi.send(props.scope, props.entityId, {
      to,
      cc: parseEmails(ccText.value),
      bcc: parseEmails(bccText.value),
      note: note.value.trim() || null,
    })
    currentUrl.value = r.url
    lastSentAt.value = r.sent_at
    toast.success(t('workReportTracking.modal.sent', { recipients: r.sent_to.join(', ') }))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('workReportTracking.modal.send_failed'))
  } finally {
    busy.value = null
  }
}

async function revoke() {
  if (!confirm(t('workReportTracking.modal.revoke_confirm'))) return
  busy.value = 'revoke'
  try {
    await workReportLinkApi.revoke(props.scope, props.entityId)
    currentUrl.value = null
    lastSentAt.value = null
    lastViewedAt.value = null
    toast.success(t('workReportTracking.modal.revoked'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('workReportTracking.modal.send_failed'))
  } finally {
    busy.value = null
  }
}

async function copyUrl() {
  if (!currentUrl.value) return
  try {
    await navigator.clipboard.writeText(currentUrl.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1500)
  } catch { /* ignore */ }
}

function fmtDateTime(s: string | null): string {
  if (!s) return t('workReportTracking.modal.never')
  return s.slice(0, 16).replace('T', ' ')
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 max-h-[90vh] overflow-y-auto">
      <div class="flex items-start justify-between gap-3 mb-3">
        <h3 class="text-lg font-semibold inline-flex items-center gap-2">
          <svg class="w-5 h-5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 015.656 5.656l-1.5 1.5"/></svg>
          {{ t('workReportTracking.modal.title') }}
        </h3>
        <button @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-lg leading-none">✕</button>
      </div>

      <p class="text-sm text-neutral-600 mb-4">
        {{ scope === 'project' ? t('workReportTracking.modal.explain_project') : t('workReportTracking.modal.explain_client') }}
      </p>

      <div v-if="loading" class="text-sm text-neutral-500 py-6 text-center">{{ t('workReportTracking.public.loading') }}</div>

      <template v-else>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.modal.to') }}</label>
            <input v-model="toText" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" :placeholder="t('workReportTracking.public.email_placeholder')" />
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.modal.cc') }}</label>
              <input v-model="ccText" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.modal.bcc') }}</label>
              <input v-model="bccText" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <p class="text-xs text-neutral-500">{{ t('workReportTracking.modal.recipients_hint') }}</p>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('workReportTracking.modal.note') }}</label>
            <textarea v-model="note" rows="2" :placeholder="t('workReportTracking.modal.note_placeholder')"
              class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <!-- Aktuální odkaz (existuje-li) -->
        <div v-if="currentUrl" class="mt-4 p-3 rounded-md bg-neutral-50 border border-neutral-200">
          <div class="text-xs font-medium text-neutral-500 mb-1">{{ t('workReportTracking.modal.current_link') }}</div>
          <div class="flex items-center gap-2">
            <input :value="currentUrl" readonly class="flex-1 h-9 px-2 border border-neutral-300 rounded text-xs font-mono bg-surface text-neutral-700" />
            <button @click="copyUrl" class="cursor-pointer h-9 px-3 text-xs border border-neutral-300 rounded hover:bg-neutral-100 whitespace-nowrap">
              {{ copied ? t('workReportTracking.modal.copied') : t('workReportTracking.modal.copy') }}
            </button>
          </div>
          <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-neutral-500">
            <span>{{ t('workReportTracking.modal.last_sent') }}: {{ fmtDateTime(lastSentAt) }}</span>
            <span>{{ t('workReportTracking.modal.last_viewed') }}: {{ fmtDateTime(lastViewedAt) }}</span>
          </div>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mt-5">
          <button v-if="currentUrl" @click="revoke" :disabled="busy !== null"
            class="cursor-pointer h-9 px-3 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md disabled:opacity-50">
            {{ t('workReportTracking.modal.revoke') }}
          </button>
          <span v-else></span>
          <div class="flex gap-2 sm:justify-end">
            <button @click="emit('close')" :disabled="busy !== null"
              class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md">
              {{ t('workReportTracking.modal.cancel') }}
            </button>
            <button @click="send" :disabled="busy !== null"
              class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
              {{ busy === 'send' ? t('workReportTracking.modal.sending') : t('workReportTracking.modal.send') }}
            </button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
