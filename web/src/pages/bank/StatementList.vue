<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankStatement, type ImportResult } from '@/api/bank'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

const router = useRouter()
const statements = ref<BankStatement[]>([])
const loading = ref(false)
const uploading = ref(false)
const scanning = ref(false)
const lastResult = ref<ImportResult | null>(null)
const error = ref('')

async function onScan() {
  scanning.value = true
  error.value = ''
  try {
    const r = await bankApi.scan()
    toast.success(t('bank.scan_done', { scanned: r.scanned, imported: r.imported, duplicate: r.duplicate, errors: r.errors }))
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('bank.scan_failed')))
  } finally {
    scanning.value = false
  }
}
async function load() {
  loading.value = true
  try { statements.value = await bankApi.list() }
  finally { loading.value = false }
}
onMounted(load)

async function onDelete(s: BankStatement, ev: MouseEvent) {
  ev.stopPropagation()
  if (!confirm(t('bank.delete_confirm', { name: s.file_name }))) return
  try {
    await bankApi.delete(s.id)
    toast.success(t('bank.delete_done'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('bank.delete_failed')))
  }
}

async function onFileSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploading.value = true
  error.value = ''
  try {
    const r = await bankApi.upload(file)
    lastResult.value = r
    await load()
    if (!r.duplicate) {
      router.push(`/bank/${r.statement_id}`)
    }
  } catch (e: any) {
    error.value = apiErrorMessage(e, 'Upload selhal')
  } finally {
    uploading.value = false
    if (input) input.value = ''
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('bank.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button @click="onScan" :disabled="scanning"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
          {{ scanning ? '…' : t('bank.scan_folder') }}
        </button>
        <label class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
          {{ uploading ? '…' : t('bank.upload_gpc') }}
          <input type="file" accept=".gpc,.txt,*/*" class="hidden" @change="onFileSelected" />
        </label>
      </div>
    </div>

    <div v-if="lastResult" class="rounded-md px-4 py-2 text-sm mb-4"
      :class="lastResult.duplicate ? 'bg-warning-50 border border-warning-500/40 text-warning-600' : 'bg-success-50 border border-success-500/40 text-success-600'">
      <span v-if="lastResult.duplicate">{{ t('bank.import_duplicate', { id: lastResult.statement_id }) }}</span>
      <span v-else>{{ t('bank.import_done', { transactions: lastResult.transactions, matched: lastResult.matched }) }}</span>
    </div>

    <div v-if="error" class="rounded-md px-4 py-2 text-sm mb-4 bg-danger-50 border border-danger-500/40 text-danger-500">
      {{ error }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!statements.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm p-12 text-center text-neutral-500">
      {{ t('bank.no_data') }}
    </div>

    <div v-else class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">Datum</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.account') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.currency') }}</th>
            <th class="px-3 py-2 text-left font-medium">Soubor</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('bank.balance') }}</th>
            <th class="px-3 py-2 text-center font-medium">Transakce</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('bank.matched') }}</th>
            <th class="px-3 py-2 text-center font-medium w-24"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="s in statements" :key="s.id" @click="router.push(`/bank/${s.id}`)" class="cursor-pointer hover:bg-neutral-50">
            <td class="px-3 py-2 text-xs">{{ formatDate(s.statement_date) }}<span v-if="s.statement_number" class="text-neutral-400 ml-1">#{{ s.statement_number }}</span></td>
            <td class="px-3 py-2 font-mono text-xs">{{ s.account_number }}</td>
            <td class="px-3 py-2">
              <span v-if="s.currency" class="text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ s.currency }}</span>
              <span v-else class="text-xs text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-xs text-neutral-600 truncate max-w-xs">{{ s.file_name }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(s.curr_balance, s.currency ?? 'CZK') }}</td>
            <td class="px-3 py-2 text-center">{{ s.transaction_count }}</td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium"
                :class="s.matched_count === s.transaction_count ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                {{ s.matched_count }} / {{ s.transaction_count }}
              </span>
            </td>
            <td class="px-3 py-2 text-center whitespace-nowrap">
              <a v-if="s.has_file" :href="bankApi.downloadUrl(s.id)" @click.stop
                 :title="t('bank.download')"
                 class="inline-flex w-7 h-7 items-center justify-center text-neutral-400 hover:text-primary-600 hover:bg-primary-50 rounded">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              </a>
              <button type="button" @click="onDelete(s, $event)"
                 :title="t('bank.delete')"
                 class="cursor-pointer inline-flex w-7 h-7 items-center justify-center text-neutral-400 hover:text-danger-500 hover:bg-danger-50 rounded">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="s in statements" :key="`m-${s.id}`"
          @click="router.push(`/bank/${s.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-3 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5">
              {{ formatDate(s.statement_date) }}<span v-if="s.statement_number" class="text-neutral-400 ml-1">#{{ s.statement_number }}</span>
              <span v-if="s.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ s.currency }}</span>
            </div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">{{ formatMoney(s.curr_balance, s.currency ?? 'CZK') }}</div>
          </div>
          <div class="font-mono text-xs text-neutral-500 mt-0.5">{{ s.account_number }}</div>
          <div class="text-xs text-neutral-500 truncate mt-0.5">{{ s.file_name }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-2">
            <span class="text-xs text-neutral-500">{{ s.transaction_count }} transakcí</span>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap"
              :class="s.matched_count === s.transaction_count ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
              {{ s.matched_count }} / {{ s.transaction_count }} {{ t('bank.matched') }}
            </span>
          </div>
          <div class="flex items-center gap-1.5 mt-2">
            <a v-if="s.has_file" :href="bankApi.downloadUrl(s.id)" @click.stop
               class="inline-flex items-center gap-1 px-2 h-7 text-xs border border-neutral-200 text-neutral-700 hover:bg-neutral-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              {{ t('bank.download') }}
            </a>
            <button type="button" @click="onDelete(s, $event)"
               class="cursor-pointer inline-flex items-center gap-1 px-2 h-7 text-xs border border-danger-500/40 text-danger-600 hover:bg-danger-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
              {{ t('bank.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
