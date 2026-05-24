<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankStatementDetail, type BankTransaction } from '@/api/bank'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

const route = useRoute()
const statement = ref<BankStatementDetail | null>(null)
const loading = ref(true)
const rematching = ref(false)
const matchingTx = ref<number | null>(null)
const matchVarsymbol = ref<string>('')
const matchError = ref<string>('')

useHotkey('escape', () => { if (matchingTx.value !== null) matchingTx.value = null })

async function load() {
  loading.value = true
  try {
    statement.value = await bankApi.get(Number(route.params.id))
  } finally { loading.value = false }
}
onMounted(load)

function statusBadge(s: string): string {
  if (s === 'auto_exact') return 'bg-success-50 text-success-600'
  if (s === 'auto_partial') return 'bg-warning-50 text-warning-600'
  if (s === 'manual') return 'bg-primary-100 text-primary-700'
  if (s === 'ignored') return 'bg-neutral-100 text-neutral-500'
  return 'bg-danger-50 text-danger-500'
}

function statusLabel(s: string): string {
  const key = `bank.match_status.${s}`
  const label = t(key)
  return label === key ? s : label
}

function startMatch(tx: BankTransaction) {
  matchingTx.value = tx.id
  // Prefill VS z transakce — typicky uživatel jen klikne potvrdit
  matchVarsymbol.value = tx.variable_symbol || ''
  matchError.value = ''
}

async function confirmMatch() {
  if (!matchingTx.value || !matchVarsymbol.value.trim()) return
  matchError.value = ''
  try {
    await bankApi.matchManual(matchingTx.value, { varsymbol: matchVarsymbol.value.trim() })
    matchingTx.value = null
    await load()
  } catch (e: any) {
    matchError.value = apiErrorMessage(e, t('bank.match_failed'))
  }
}

async function ignoreTx(tx: BankTransaction) {
  if (!confirm(t('bank.ignore_confirm'))) return
  await bankApi.ignore(tx.id)
  await load()
}

async function unmatchTx(tx: BankTransaction) {
  if (!confirm(t('bank.unmatch_confirm'))) return
  try {
    await bankApi.unmatch(tx.id)
    await load()
  } catch (e: any) {
    alert(apiErrorMessage(e, t('bank.unmatch_failed')))
  }
}

async function rematchStatement() {
  if (!statement.value || rematching.value) return
  if (!confirm(t('bank.rematch_confirm'))) return
  rematching.value = true
  try {
    const r = await bankApi.rematch(statement.value.id)
    toast.success(t('bank.rematch_done', {
      matched: r.newly_matched,
      partial: r.newly_partial,
      remaining: r.still_unmatched,
    }))
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('bank.rematch_failed')))
  } finally {
    rematching.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

  <div v-else-if="statement">
    <RouterLink to="/bank" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('bank.back') }}</RouterLink>
    <div class="flex items-start justify-between gap-3 mt-1 flex-wrap">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">
          {{ t('bank.statement_title', { number: statement.statement_number, date: formatDate(statement.statement_date) }) }}
        </h1>
        <p class="text-sm text-neutral-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
          <span>{{ t('bank.account') }}<span class="font-mono">{{ statement.account_number }}</span></span>
          <span v-if="statement.account_label" class="text-neutral-400">— {{ statement.account_label }}</span>
          <span v-if="statement.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ statement.currency }}</span>
          <span>· {{ statement.file_name }}</span>
        </p>
      </div>
      <a v-if="statement.has_file" :href="bankApi.downloadUrl(statement.id)"
         :title="t('bank.download_hint')"
         class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md shrink-0">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        {{ t('bank.download') }}
      </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
      <div class="bg-white border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.prev_balance') }}</div>
        <div class="text-lg font-mono">{{ formatMoney(statement.prev_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.curr_balance') }}</div>
        <div class="text-lg font-mono font-semibold">{{ formatMoney(statement.curr_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.credit_total') }}</div>
        <div class="text-lg font-mono text-success-600">+{{ formatMoney(statement.credit_total, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.debit_total') }}</div>
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(statement.debit_total, statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('bank.transactions') }} ({{ statement.transactions.length }})
        </h2>
        <button type="button" @click="rematchStatement" :disabled="rematching"
          class="cursor-pointer h-8 px-3 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 rounded-md font-medium inline-flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" :class="{ 'animate-spin': rematching }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15" />
          </svg>
          {{ rematching ? t('bank.rematch_running') : t('bank.rematch') }}
        </button>
      </header>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.date') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('bank.amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.vs_ks') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.counterparty') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.invoice') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('invoice.status_label') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="tx in statement.transactions" :key="tx.id" :class="{ 'opacity-50': tx.match_status === 'ignored' }">
            <td class="px-3 py-2 text-xs">{{ formatDate(tx.posted_at) }}</td>
            <td class="px-3 py-2 text-right font-mono text-xs"
              :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
              {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, tx.currency ?? statement.currency ?? 'CZK') }}
            </td>
            <td class="px-3 py-2 font-mono text-xs">
              <span v-if="tx.variable_symbol">{{ tx.variable_symbol }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
            </td>
            <td class="px-3 py-2 text-xs">
              <div class="font-mono text-neutral-600">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
              <div v-if="tx.description" class="text-neutral-500 truncate max-w-xs">{{ tx.description }}</div>
            </td>
            <td class="px-3 py-2 text-xs">
              <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
                class="text-primary-600 hover:underline">
                {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
              </RouterLink>
              <span v-else class="text-neutral-400">—</span>
              <div v-if="tx.matched_client_name" class="text-neutral-500 text-xs">{{ tx.matched_client_name }}</div>
            </td>
            <td class="px-3 py-2 text-center">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(tx.match_status)">
                {{ statusLabel(tx.match_status) }}
              </span>
            </td>
            <td class="px-3 py-2 text-right text-xs">
              <button v-if="tx.match_status === 'unmatched' || tx.match_status === 'auto_partial'"
                @click="startMatch(tx)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-2">
                {{ t('bank.match') }}
              </button>
              <button v-if="tx.match_status === 'unmatched'" @click="ignoreTx(tx)"
                class="cursor-pointer text-neutral-500 hover:text-neutral-700">
                {{ t('bank.ignore') }}
              </button>
              <button v-if="['auto_exact','auto_partial','manual','ignored'].includes(tx.match_status)"
                @click="unmatchTx(tx)" class="cursor-pointer text-neutral-500 hover:text-danger-600">
                {{ t('bank.unmatch') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>

      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="tx in statement.transactions" :key="`m-${tx.id}`"
          class="p-3 space-y-2"
          :class="{ 'opacity-50': tx.match_status === 'ignored' }">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono text-base font-semibold whitespace-nowrap"
              :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
              {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, tx.currency ?? statement.currency ?? 'CZK') }}
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="statusBadge(tx.match_status)">
              {{ statusLabel(tx.match_status) }}
            </span>
          </div>
          <div class="flex items-baseline justify-between text-xs text-neutral-500">
            <span class="font-mono">{{ formatDate(tx.posted_at) }}</span>
            <span class="font-mono">
              <span v-if="tx.variable_symbol">VS {{ tx.variable_symbol }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
            </span>
          </div>
          <div class="text-xs">
            <div class="font-mono text-neutral-600 truncate">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
            <div v-if="tx.description" class="text-neutral-500 truncate">{{ tx.description }}</div>
          </div>
          <div v-if="tx.matched_invoice_id" class="text-xs">
            <RouterLink :to="`/invoices/${tx.matched_invoice_id}`"
              class="text-primary-600 hover:underline font-mono">
              {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
            </RouterLink>
            <span v-if="tx.matched_client_name" class="text-neutral-500 ml-2">{{ tx.matched_client_name }}</span>
          </div>
          <div class="flex gap-2 pt-1">
            <button v-if="tx.match_status === 'unmatched' || tx.match_status === 'auto_partial'"
              @click="startMatch(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md">
              {{ t('bank.match') }}
            </button>
            <button v-if="tx.match_status === 'unmatched'" @click="ignoreTx(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-neutral-50 rounded-md">
              {{ t('bank.ignore') }}
            </button>
            <button v-if="['auto_exact','auto_partial','manual','ignored'].includes(tx.match_status)"
              @click="unmatchTx(tx)"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-danger-50 hover:text-danger-600 rounded-md">
              {{ t('bank.unmatch') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Manual match modal — párování přes variabilní symbol faktury -->
    <div v-if="matchingTx" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('bank.manual_match_title') }}</h3>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.invoice_vs') }}</label>
        <input v-model="matchVarsymbol" type="text" inputmode="numeric"
          placeholder="2603001" autofocus
          @keyup.enter="confirmMatch"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono mb-2" />
        <p class="text-xs text-neutral-500 mb-4">
          {{ t('bank.vs_hint') }}
        </p>
        <div v-if="matchError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-3">
          {{ matchError }}
        </div>
        <div class="flex justify-end gap-2">
          <button @click="matchingTx = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="confirmMatch" :disabled="!matchVarsymbol.trim()"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ t('bank.match') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
