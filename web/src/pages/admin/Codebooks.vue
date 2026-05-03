<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type VatRate, type Country, type CurrencyAccount } from '@/api/settings'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

type Tab = 'currencies' | 'vat' | 'countries'
const tab = ref<Tab>('currencies')

const currencies = ref<CurrencyAccount[]>([])
const vatRates   = ref<VatRate[]>([])
const countries  = ref<Country[]>([])
const loading    = ref(false)

async function loadAll() {
  loading.value = true
  try {
    [currencies.value, vatRates.value, countries.value] = await Promise.all([
      settingsApi.listCurrencies(),
      settingsApi.listVatRates(),
      settingsApi.listCountries(),
    ])
  } finally { loading.value = false }
}
onMounted(loadAll)

// ─── Currencies ───────────────────────────────────────────
const currencyDraft = reactive<Partial<CurrencyAccount> & { _new?: boolean }>({})
const currencyOpen = ref(false)
function newCurrency() {
  Object.assign(currencyDraft, {
    id: undefined, code: '', label: '', symbol: '', name_cs: '', name_en: '',
    decimals: 2, is_active: true, is_default: false,
    account_number: null, bank_code: null, bank_name: null, iban: null, bic: null,
    _new: true,
  })
  currencyOpen.value = true
}
function editCurrency(c: CurrencyAccount) {
  Object.assign(currencyDraft, { ...c, _new: false })
  currencyOpen.value = true
}
async function saveCurrency() {
  try {
    if (currencyDraft._new) {
      await settingsApi.createCurrency({
        code: currencyDraft.code, label: currencyDraft.label, symbol: currencyDraft.symbol,
        name_cs: currencyDraft.name_cs, name_en: currencyDraft.name_en,
        decimals: currencyDraft.decimals, is_active: currencyDraft.is_active, is_default: currencyDraft.is_default,
        account_number: currencyDraft.account_number || null,
        bank_code: currencyDraft.bank_code || null,
        bank_name: currencyDraft.bank_name || null,
        iban: currencyDraft.iban || null,
        bic: currencyDraft.bic || null,
      })
    } else if (currencyDraft.id) {
      await settingsApi.updateCurrency(currencyDraft.id, {
        label: currencyDraft.label,
        is_active: currencyDraft.is_active,
        is_default: currencyDraft.is_default,
        account_number: currencyDraft.account_number || null,
        bank_code: currencyDraft.bank_code || null,
        bank_name: currencyDraft.bank_name || null,
        iban: currencyDraft.iban || null,
        bic: currencyDraft.bic || null,
      })
    }
    currencyOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCurrency(c: CurrencyAccount) {
  if (!confirm(`Smazat ${c.label}?`)) return
  try {
    await settingsApi.deleteCurrency(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── VAT rates ────────────────────────────────────────────
const vatDraft = reactive<Partial<VatRate> & { _new?: boolean }>({})
const vatOpen = ref(false)
function newVat() {
  Object.assign(vatDraft, {
    id: undefined, code: '', rate_percent: 21, country: 'CZ',
    label_cs: '', label_en: '', is_default: false, is_reverse_charge: false,
    valid_from: new Date().toISOString().slice(0, 10), valid_to: null, _new: true,
  })
  vatOpen.value = true
}
function editVat(v: VatRate) {
  Object.assign(vatDraft, { ...v, _new: false })
  vatOpen.value = true
}
async function saveVat() {
  try {
    if (vatDraft._new) await settingsApi.createVatRate(vatDraft)
    else if (vatDraft.id) await settingsApi.updateVatRate(vatDraft.id, vatDraft)
    vatOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteVat(v: VatRate) {
  if (!confirm(`Smazat sazbu ${v.code} (${v.rate_percent} %)?`)) return
  try {
    await settingsApi.deleteVatRate(v.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Countries ────────────────────────────────────────────
const countryDraft = reactive<Partial<Country> & { _new?: boolean }>({})
const countryOpen = ref(false)

useHotkey('escape', () => {
  if (currencyOpen.value) currencyOpen.value = false
  else if (vatOpen.value) vatOpen.value = false
  else if (countryOpen.value) countryOpen.value = false
})
function newCountry() {
  Object.assign(countryDraft, { id: undefined, iso2: '', iso3: '', name_cs: '', name_en: '', is_eu: false, _new: true })
  countryOpen.value = true
}
function editCountry(c: Country) {
  Object.assign(countryDraft, { ...c, _new: false })
  countryOpen.value = true
}
async function saveCountry() {
  try {
    if (countryDraft._new) await settingsApi.createCountry(countryDraft)
    else if (countryDraft.id) await settingsApi.updateCountry(countryDraft.id, countryDraft)
    countryOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCountry(c: Country) {
  if (!confirm(`Smazat zemi ${c.iso2} – ${c.name_cs}?`)) return
  try {
    await settingsApi.deleteCountry(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('codebooks.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('codebooks.subtitle') }}</p>
    </div>

    <!-- Tabs -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1">
      <button v-for="tt in (['currencies', 'vat', 'countries'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'currencies' ? t('codebooks.tab_currencies') : tt === 'vat' ? t('codebooks.tab_vat') : t('codebooks.tab_countries') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- ====== CURRENCIES ====== -->
    <section v-else-if="tab === 'currencies'">
      <div class="flex justify-end mb-3">
        <button @click="newCurrency"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_currency') }}
        </button>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">Účet (label)</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.decimals') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_cz') }} / {{ t('settings.iban') }}</th>
              <th class="px-3 py-2 text-center font-medium">Výchozí</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('settings.active') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in currencies" :key="c.id">
              <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
              <td class="px-3 py-2 text-xs">{{ c.label }}</td>
              <td class="px-3 py-2 text-center font-mono">{{ c.decimals }}</td>
              <td class="px-3 py-2 font-mono text-xs">
                <span v-if="c.account_number">{{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span></span>
                <span v-else-if="c.iban">{{ c.iban }}</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_default" class="text-primary-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_active" class="text-success-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteCurrency(c)" :disabled="(c.invoices_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(c.invoices_count ?? 0) > 0 ? t('codebooks.in_use_currency', { n: c.invoices_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== VAT RATES ====== -->
    <section v-else-if="tab === 'vat'">
      <div class="flex justify-end mb-3">
        <button @click="newVat"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_vat') }}
        </button>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.country') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-right font-medium">%</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_reverse_charge') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.valid') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="v in vatRates" :key="v.id">
              <td class="px-3 py-2 text-center font-mono">{{ v.country }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ v.code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ v.rate_percent }} %</td>
              <td class="px-3 py-2">{{ v.label_cs }}</td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_reverse_charge" class="text-warning-600">⇄</span></td>
              <td class="px-3 py-2 text-xs text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editVat(v)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== COUNTRIES ====== -->
    <section v-else>
      <div class="flex justify-end mb-3">
        <button @click="newCountry"
          class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('codebooks.new_country') }}
        </button>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso2') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso3') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_eu') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in countries" :key="c.id">
              <td class="px-3 py-2 text-center font-mono">{{ c.iso2 }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.iso3 }}</td>
              <td class="px-3 py-2">{{ c.name_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ c.name_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="c.is_eu" class="text-primary-600">EU</span></td>
              <td class="px-3 py-2 text-right text-xs">
                <button @click="editCountry(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  {{ t('common.delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== Modals ====== -->
    <div v-if="currencyOpen" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4" @click.self="currencyOpen = false">
      <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ currencyDraft._new ? t('codebooks.new_currency') : t('settings.edit_currency', { code: currencyDraft.code }) }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3" v-if="currencyDraft._new">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="currencyDraft.code" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">Symbol</label>
              <input v-model="currencyDraft.symbol" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.decimals') }}</label>
              <input v-model.number="currencyDraft.decimals" type="number" min="0" max="6" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3" v-if="currencyDraft._new">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="currencyDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="currencyDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">Název účtu (label) *</label>
            <input v-model="currencyDraft.label" type="text" placeholder="CZK — Fio Bank"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_account_cz') }}</label>
            <input v-model="currencyDraft.account_number" type="text" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_bank_code') }}</label>
              <input v-model="currencyDraft.bank_code" type="text" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('settings.currency_bank_name') }}</label>
              <input v-model="currencyDraft.bank_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.iban') }}</label>
            <input v-model="currencyDraft.iban" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('settings.bic') }}</label>
            <input v-model="currencyDraft.bic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('settings.active') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            Výchozí účet pro tuto měnu (per kód lze mít vždy jen jeden)
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="currencyOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="vatOpen" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4" @click.self="vatOpen = false">
      <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ vatDraft._new ? t('codebooks.new_vat') : vatDraft.code }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.country') }}</label>
              <input v-model="vatDraft.country" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="vatDraft.code" type="text" placeholder="STD" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">% *</label>
              <input v-model.number="vatDraft.rate_percent" type="number" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="vatDraft.label_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="vatDraft.label_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_from') }}</label>
              <input v-model="vatDraft.valid_from" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_to') }}</label>
              <input v-model="vatDraft.valid_to" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_default_for_country') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_reverse_charge_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="vatOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveVat" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="countryOpen" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4" @click.self="countryOpen = false">
      <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ countryDraft._new ? t('codebooks.new_country') : countryDraft.iso2 }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso2') }} *</label>
              <input v-model="countryDraft.iso2" :disabled="!countryDraft._new" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-50" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso3') }}</label>
              <input v-model="countryDraft.iso3" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
            <input v-model="countryDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
            <input v-model="countryDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="countryDraft.is_eu" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_eu_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="countryOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCountry" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
