<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { invoicesApi, type Invoice, type InvoicePayload, type InvoiceItem, type WorkReportItem } from '@/api/invoices'
import { useHotkey } from '@/composables/useHotkey'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency } from '@/api/codebooks'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const error = ref('')
const isForce = computed(() => route.query.force === '1')
const editedStatus = ref<string>('draft')
const editedVarsymbol = ref<string | null>(null)

const clients = ref<Client[]>([])
const projects = ref<Project[]>([])
const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])

const form = ref<{
  invoice_type: 'invoice' | 'proforma' | 'credit_note'
  client_id: number | null
  project_id: number | null
  issue_date: string
  tax_date: string
  due_date: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  items: InvoiceItem[]
}>({
  invoice_type: 'invoice',
  client_id: null,
  project_id: null,
  issue_date: today(),
  tax_date: today(),
  due_date: addDays(today(), 7),
  currency_id: 0,
  currency: 'CZK',
  reverse_charge: false,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  items: [],
})

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function addDays(date: string, days: number): string {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function defaultVatRateId(): number {
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

function blankItem(): InvoiceItem {
  // Dobropis = záporné množství (sleva/refundace), default -1
  const qty = form.value.invoice_type === 'credit_note' ? -1 : 1
  return {
    description: '',
    quantity: qty,
    unit: 'h',
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(),
    order_index: form.value.items.length,
  }
}

// Při přepnutí typu na credit_note převrať množství všech existujících položek na záporná.
watch(() => form.value.invoice_type, (newType, oldType) => {
  if (newType === 'credit_note' && oldType !== 'credit_note') {
    for (const it of form.value.items) {
      if (it.quantity > 0) it.quantity = -it.quantity
    }
  }
  if (oldType === 'credit_note' && newType !== 'credit_note') {
    for (const it of form.value.items) {
      if (it.quantity < 0) it.quantity = -it.quantity
    }
  }
})

onMounted(async () => {
  const [vr, cur] = await Promise.all([codebooksApi.vatRates('CZ'), codebooksApi.currencies()])
  vatRates.value = vr
  currencies.value = cur
  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) {
      form.value.currency_id = def.id
      form.value.currency = def.code
    }
  }

  // Load clients (for dropdown)
  const cl = await clientsApi.list({ archived: false })
  clients.value = cl.data

  if (isEdit.value && invoiceId.value) {
    const inv = await invoicesApi.get(invoiceId.value)
    editedStatus.value = inv.status
    editedVarsymbol.value = inv.varsymbol
    Object.assign(form.value, {
      invoice_type: (inv.invoice_type === 'proforma' || inv.invoice_type === 'credit_note')
        ? inv.invoice_type
        : 'invoice',
      client_id: inv.client_id,
      project_id: inv.project_id,
      issue_date: inv.issue_date.slice(0, 10),
      tax_date: (inv.tax_date ?? inv.issue_date).slice(0, 10),
      due_date: inv.due_date.slice(0, 10),
      currency_id: inv.currency_id,
      currency: inv.currency,
      reverse_charge: inv.reverse_charge,
      language: inv.language,
      note_above_items: inv.note_above_items ?? '',
      note_below_items: inv.note_below_items ?? '',
      advance_paid_amount: inv.advance_paid_amount,
      items: inv.items.map(i => ({ ...i })),
    })
    if (inv.client_id) {
      await loadProjects(inv.client_id)
      await verifyClientVies(inv.client_id)
    }
    // Načti existující work_report (pokud existuje)
    await loadWorkReport()
  } else {
    // New invoice — pre-select from query
    if (route.query.client_id) {
      form.value.client_id = Number(route.query.client_id)
      await loadProjects(form.value.client_id!)
      await applyClientDefaults(form.value.client_id!)
    }
    if (route.query.project_id) {
      form.value.project_id = Number(route.query.project_id)
      await applyProjectDefaults(form.value.project_id!)
    }
    if (form.value.items.length === 0) {
      form.value.items = [blankItem()]
    }
  }

  loaded.value = true
})

async function loadProjects(clientId: number) {
  projects.value = await projectsApi.listForClient(clientId)
}

async function onClientChange() {
  form.value.project_id = null
  if (form.value.client_id) {
    await loadProjects(form.value.client_id)
    await applyClientDefaults(form.value.client_id)
    await verifyClientVies(form.value.client_id)
  } else {
    viesResult.value = null
  }
}

async function applyClientDefaults(clientId: number) {
  const c = clients.value.find(c => c.id === clientId)
  if (!c) return
  form.value.currency_id = c.currency_default_id
  form.value.currency = c.currency_default
  form.value.language = c.language
  form.value.reverse_charge = c.reverse_charge
  if (c.payment_due_default) {
    form.value.due_date = addDays(form.value.issue_date, c.payment_due_default)
  }
}

// VIES ověření DIČ vybraného klienta (jen pokud má DIČ)
const viesResult = ref<{ status: 'checking' | 'valid' | 'invalid' | 'no_dic' | 'error'; dic?: string; name?: string; message?: string } | null>(null)

async function verifyClientVies(clientId: number) {
  const c = clients.value.find(cc => cc.id === clientId)
  if (!c) { viesResult.value = null; return }
  const dic = (c.dic || '').trim()
  if (!dic) { viesResult.value = { status: 'no_dic' }; return }
  viesResult.value = { status: 'checking', dic }
  try {
    const r: ViesLookupResult = await clientsApi.lookupVies(dic)
    if (r.valid) {
      viesResult.value = { status: 'valid', dic, name: r.name }
    } else {
      viesResult.value = { status: 'invalid', dic, message: r.source === 'error' ? t('invoice.vies.service_unavailable') : t('invoice.vies.not_valid') }
    }
  } catch (e: any) {
    viesResult.value = { status: 'error', dic, message: e?.response?.data?.error?.message || t('invoice.vies.verify_error') }
  }
}

async function onProjectChange() {
  if (form.value.project_id) await applyProjectDefaults(form.value.project_id)
}

function onCurrencyChange() {
  const c = currencies.value.find(x => x.id === form.value.currency_id)
  if (c) form.value.currency = c.code
}

async function applyProjectDefaults(projectId: number) {
  const p = projects.value.find(p => p.id === projectId)
  if (!p) return
  form.value.currency_id = p.currency_id
  form.value.currency = p.currency
  form.value.due_date = addDays(form.value.issue_date, p.payment_due_days)
  // Pokud nemá žádné položky se sazbou, předvyplň sazbu
  if (form.value.items.length === 1 && form.value.items[0].unit_price_without_vat === 0) {
    form.value.items[0].unit_price_without_vat = p.hourly_rate
    form.value.items[0].unit = 'h'
  }
}

// (žádné watch hooky pro typ ani datumy — proforma nemá DUZP, viz template)

function addItem() {
  form.value.items.push(blankItem())
}

function removeItem(index: number) {
  form.value.items.splice(index, 1)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveUp(index: number) {
  if (index === 0) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index - 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveDown(index: number) {
  if (index >= form.value.items.length - 1) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index + 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

// Live výpočet sumace na frontendu (server přepočítá při uložení)
const computed_totals = computed(() => {
  const breakdown = new Map<number, { rate: number; base: number; vat: number }>()
  let totalBase = 0
  let totalVat = 0

  for (const item of form.value.items) {
    const vatRate = form.value.reverse_charge
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2(item.quantity * item.unit_price_without_vat)
    const vat = round2(base * (vatRate / 100))

    totalBase += base
    totalVat += vat

    if (!breakdown.has(vatRate)) {
      breakdown.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    }
    const b = breakdown.get(vatRate)!
    b.base += base
    b.vat += vat
  }

  return {
    without_vat: round2(totalBase),
    vat: round2(totalVat),
    with_vat: round2(totalBase + totalVat),
    amount_to_pay: round2(totalBase + totalVat - form.value.advance_paid_amount),
    breakdown: Array.from(breakdown.values())
      .map(b => ({ rate: b.rate, base: round2(b.base), vat: round2(b.vat) }))
      .sort((a, b) => b.rate - a.rate),
  }
})

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

function itemTotal(item: InvoiceItem): number {
  return round2(item.quantity * item.unit_price_without_vat)
}

// ─── WORK REPORT ────────────────────────────────────────────────
const wrOpen = ref(false)
const wrTitle = ref('')
const wrItems = ref<WorkReportItem[]>([])

async function loadWorkReport() {
  if (!invoiceId.value) return
  const wr = await invoicesApi.getWorkReport(invoiceId.value)
  if (wr) {
    wrTitle.value = wr.title
    wrItems.value = wr.items.map(i => ({ ...i }))
    wrOpen.value = true
  }
}

// Pro výpočty + uložení: jen řádky s vyplněným popisem. Prázdné řádky uživatel
// typicky nevyplní (přidal Přidat řádek a zapomněl), automaticky je ignorujeme,
// aby totals v položce faktury seděly s tím, co se opravdu uloží.
const wrItemsValid = computed(() => wrItems.value.filter(i => (i.description || '').trim() !== ''))
const wrTotalHours = computed(() => wrItemsValid.value.reduce((s, i) => s + (Number(i.hours) || 0), 0))
const wrTotalAmount = computed(() => wrItemsValid.value.reduce((s, i) => s + (Number(i.hours) || 0) * (Number(i.rate) || 0), 0))

function addWrItem() {
  // 1. project hourly rate, 2. existing WR row rate (z editace), 3. default 1500
  const projectRate = projects.value.find(p => p.id === form.value.project_id)?.hourly_rate
  const previousRate = wrItems.value[wrItems.value.length - 1]?.rate
  const defaultRate = (projectRate && projectRate > 0) ? projectRate
    : (previousRate && previousRate > 0) ? previousRate
    : 1500
  wrItems.value.push({ description: '', hours: 1, rate: defaultRate, order_index: wrItems.value.length })
}
function removeWrItem(idx: number) {
  wrItems.value.splice(idx, 1)
}
function openWorkReport() {
  if (wrItems.value.length === 0) {
    const date = (form.value.tax_date || form.value.issue_date || '').slice(0, 7) // YYYY-MM
    wrTitle.value = date ? `Výkaz víceprací — ${date}` : 'Výkaz víceprací'
    addWrItem()
  }
  wrOpen.value = true
}

// Přenese sumu výkazu jako jednu položku faktury (popis = title výkazu, qty = hodiny, sazba = avg).
// Pokud už existuje položka se stejným popisem (= title výkazu), AKTUALIZUJE ji
// (množství / sazba / DPH zůstává); jinak přidá novou. Tím se opětovné kliknutí
// "Přenést jako položku faktury" po editaci výkazu chová jako sync, ne jako duplicate.
function pushWrToInvoiceItem() {
  if (wrItemsValid.value.length === 0) return
  const totalHours = wrTotalHours.value
  const totalAmount = wrTotalAmount.value
  const avgRate = totalHours > 0 ? Math.round((totalAmount / totalHours) * 100) / 100 : 0
  const defaultVatId = vatRates.value.find(v => v.is_default)?.id ?? vatRates.value[0]?.id ?? 1
  const description = wrTitle.value || 'Výkaz víceprací'

  // 1. Položka se shodným popisem → sync (aktualizace hodin/sazby).
  // 2. Jinak prázdná položka (z blankItem na nové faktuře) → naplň ji, ne push.
  // 3. Jinak nová položka.
  const existing = form.value.items.find(it => (it.description || '').trim() === description.trim())
  const empty = !existing
    ? form.value.items.find(it => (it.description || '').trim() === ''
        && (Number(it.unit_price_without_vat) || 0) === 0)
    : undefined
  const target = existing || empty

  if (target) {
    target.description = description
    target.quantity = totalHours
    target.unit = 'h'
    target.unit_price_without_vat = avgRate
    // vat_rate_id záměrně neměníme — uživatel ho mohl ručně změnit
  } else {
    form.value.items.push({
      description,
      quantity: totalHours,
      unit: 'h',
      unit_price_without_vat: avgRate,
      vat_rate_id: defaultVatId,
      order_index: form.value.items.length,
    })
  }
}

async function deleteWorkReport() {
  if (!confirm(t('invoice.wr_delete_confirm'))) return
  // Pokud je faktura už uložená, smaž i z DB; jinak jen lokálně.
  if (invoiceId.value) {
    try {
      await invoicesApi.deleteWorkReport(invoiceId.value, isForce.value)
    } catch (e: any) {
      // 404 = výkaz v DB neexistuje (nový), pokračuj s lokálním clear
      if (e?.response?.status !== 404) {
        error.value = apiErrorMessage(e, 'Smazání výkazu selhalo')
        return
      }
    }
  }
  wrItems.value = []
  wrTitle.value = ''
  wrOpen.value = false
}

/**
 * Pokud uživatel má otevřený výkaz s položkami, ověř jestli odpovídá faktuře.
 * Vrací null = OK, jinak warning string pro confirm().
 */
function checkWorkReportSync(): string | null {
  if (!wrOpen.value || wrItemsValid.value.length === 0) return null
  const totalHours = Math.round(wrTotalHours.value * 100) / 100
  const totalAmount = Math.round(wrTotalAmount.value * 100) / 100
  const avgRate = totalHours > 0 ? Math.round((totalAmount / totalHours) * 100) / 100 : 0
  const description = (wrTitle.value || (locale.value === 'cs' ? 'Výkaz víceprací' : 'Work report')).trim()
  if (description === '') return null

  const ccy = currencies.value.find(c => c.id === form.value.currency_id)?.code || ''
  const item = form.value.items.find(it => (it.description || '').trim() === description)

  if (!item) {
    return locale.value === 'cs'
      ? `Výkaz „${description}" obsahuje ${totalHours} h za ${totalAmount.toLocaleString('cs')} ${ccy}, ale není zapsán jako položka faktury.\n\nPokračovat bez přenosu výkazu do faktury?`
      : `Work report "${description}" has ${totalHours}h for ${totalAmount.toLocaleString('en-US')} ${ccy}, but is not listed as an invoice item.\n\nContinue without transferring the work report to the invoice?`
  }

  const itemQty = Number(item.quantity) || 0
  const itemRate = Number(item.unit_price_without_vat) || 0
  const qtyDiff = Math.abs(itemQty - totalHours) > 0.01
  const rateDiff = Math.abs(itemRate - avgRate) > 0.01

  if (qtyDiff || rateDiff) {
    return locale.value === 'cs'
      ? `Výkaz se liší od přenesené položky faktury:\n\n• Výkaz:   ${totalHours} h × ${avgRate.toLocaleString('cs')} = ${totalAmount.toLocaleString('cs')} ${ccy}\n• Položka: ${itemQty} h × ${itemRate.toLocaleString('cs')} = ${(itemQty * itemRate).toLocaleString('cs')} ${ccy}\n\nPokračovat bez aktualizace položky?`
      : `Work report differs from invoice item:\n\n• Report: ${totalHours}h × ${avgRate} = ${totalAmount} ${ccy}\n• Item:   ${itemQty}h × ${itemRate} = ${(itemQty * itemRate)} ${ccy}\n\nContinue without updating the item?`
  }
  return null
}

async function submit() {
  // Detekce nesouladu mezi výkazem a položkou faktury — uživatel má šanci se vrátit
  const wrWarning = checkWorkReportSync()
  if (wrWarning && !confirm(wrWarning)) return

  submitting.value = true
  error.value = ''
  try {
    const payload: InvoicePayload = {
      invoice_type: form.value.invoice_type,
      client_id: form.value.client_id!,
      project_id: form.value.project_id,
      issue_date: form.value.issue_date,
      tax_date: form.value.invoice_type === 'proforma' ? null : form.value.tax_date,
      due_date: form.value.due_date,
      currency_id: form.value.currency_id,
      reverse_charge: form.value.reverse_charge,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
    }

    let saved: Invoice
    if (isEdit.value && invoiceId.value) {
      saved = await invoicesApi.update(invoiceId.value, payload, isForce.value)
    } else {
      saved = await invoicesApi.create(payload)
    }
    // Po uložení faktury — pokud uživatel otevřel work report, ulož ho
    // (jen řádky s vyplněným popisem; prázdné řádky tiše ignorujeme — viz wrItemsValid)
    if (wrOpen.value && wrItemsValid.value.length > 0) {
      try {
        await invoicesApi.saveWorkReport(saved.id, {
          project_id: saved.project_id!,
          title: wrTitle.value,
          items: wrItemsValid.value.map((it, i) => ({
            description: it.description,
            work_date: it.work_date || null,
            hours: Number(it.hours) || 0,
            rate: Number(it.rate) || 0,
            order_index: i,
          })),
        }, isForce.value)
      } catch (e: any) {
        // Faktura je uložená, výkaz ne — nepokračuj v redirectu, ať uživatel nepřijde o data ve formuláři
        error.value = apiErrorMessage(e, locale.value === 'cs' ? 'Uložení výkazu selhalo' : 'Work report save failed')
        return
      }
    }
    router.push(`/invoices/${saved.id}`)
  } catch (e: any) {
    error.value = apiErrorMessage(e, locale.value === 'cs' ? 'Uložení selhalo' : 'Save failed')
  } finally {
    submitting.value = false
  }
}

async function deleteDraft() {
  if (!invoiceId.value) return
  if (!confirm(locale.value === 'cs' ? 'Smazat koncept faktury?' : 'Delete invoice draft?')) return
  try {
    await invoicesApi.delete(invoiceId.value)
    router.push('/invoices')
  } catch (e: any) {
    error.value = apiErrorMessage(e, locale.value === 'cs' ? 'Smazání selhalo' : 'Delete failed')
  }
}
</script>

<template>
  <div v-if="!loaded" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else class="max-w-5xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('invoice.back_to_list') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ isEdit ? t('invoice.edit_title') : t('invoice.new_title') }}
          <span class="text-sm font-normal text-neutral-500 ml-2">
            <span v-if="form.invoice_type === 'proforma'" class="px-2 py-0.5 bg-accent-100 text-accent-600 rounded">{{ t('type.proforma') }}</span>
            <span v-else-if="form.invoice_type === 'credit_note'" class="px-2 py-0.5 bg-danger-50 text-danger-500 rounded">{{ t('type.credit_note') }}</span>
            <span v-else-if="editedStatus !== 'draft'" class="px-2 py-0.5 bg-warning-50 text-warning-600 rounded">{{ t(`status.${editedStatus}`) }}</span>
            <span v-else class="px-2 py-0.5 bg-neutral-100 text-neutral-600 rounded">{{ t('status.draft') }}</span>
          </span>
        </h1>
      </div>
      <button v-if="isEdit && editedStatus === 'draft'" @click="deleteDraft" class="text-sm text-danger-500 hover:text-danger-600 cursor-pointer">
        {{ t('invoice.delete_draft_btn') }}
      </button>
    </div>

    <!-- Banner pro úpravu vystavené faktury (admin force=1) -->
    <div v-if="isForce && editedStatus !== 'draft'" class="mb-4 rounded-md border border-warning-500/50 bg-warning-50 p-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-warning-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        <div class="text-sm text-warning-600">
          <div class="font-semibold mb-1">{{ t('invoice.edit_issued_warning') }}</div>
          <p>{{ t('invoice.edit_issued_body', { varsymbol: editedVarsymbol, status: editedStatus }) }}</p>
        </div>
      </div>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
      <!-- Klient + zakázka + datumy -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.client') }} &amp; {{ t('invoice.project') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.doc_type') }} *</label>
              <select v-model="form.invoice_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                <option value="invoice">{{ t('invoice.doc_invoice') }}</option>
                <option value="proforma">{{ t('invoice.doc_proforma') }}</option>
                <option value="credit_note">{{ t('invoice.doc_credit_note') }}</option>
              </select>
              <p v-if="form.invoice_type === 'credit_note'" class="text-xs text-warning-600 mt-1">
                {{ t('invoice.credit_note_warning') }}
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.client') }} *</label>
              <select v-model="form.client_id" @change="onClientChange" required
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                <option :value="null" disabled>{{ t('invoice.select_client') }}</option>
                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
              </select>
              <!-- VIES výsledek -->
              <div v-if="viesResult" class="mt-1 text-xs flex items-start gap-1.5">
                <template v-if="viesResult.status === 'checking'">
                  <span class="text-neutral-500">⏳ Ověřuji DIČ {{ viesResult.dic }} v systému VIES…</span>
                </template>
                <template v-else-if="viesResult.status === 'valid'">
                  <svg class="w-4 h-4 text-success-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                  <span class="text-success-600">{{ t('common.dic') }} <span class="font-mono font-semibold">{{ viesResult.dic }}</span> ověřeno ve VIES
                    <span v-if="viesResult.name" class="text-neutral-500"> — {{ viesResult.name }}</span>
                  </span>
                </template>
                <template v-else-if="viesResult.status === 'invalid'">
                  <svg class="w-4 h-4 text-danger-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                  <span class="text-danger-500">{{ t('common.dic') }} <span class="font-mono">{{ viesResult.dic }}</span>: {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'error'">
                  <span class="text-warning-600">⚠ {{ viesResult.message }}</span>
                </template>
                <template v-else-if="viesResult.status === 'no_dic'">
                  <span class="text-neutral-400">{{ t('invoice.vies.no_dic') }}</span>
                </template>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.project') }}</label>
              <select v-model="form.project_id" @change="onProjectChange"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white"
                :disabled="!form.client_id">
                <option :value="null">{{ t('invoice.no_project') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">
                  {{ p.name }}<span v-if="p.status !== 'active'"> ({{ p.status }})</span>
                </option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.currency') }}</label>
                <select v-model.number="form.currency_id" @change="onCurrencyChange"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.language') }}</label>
                <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option value="cs">CZ</option>
                  <option value="en">EN</option>
                </select>
              </div>
            </div>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('invoice.reverse_charge') }} ({{ t('invoice.totals.vat') }} 0 %)</span>
            </label>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">Datumy</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.issue_date') }} *</label>
              <input v-model="form.issue_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-if="form.invoice_type !== 'proforma'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.tax_date') }} *</label>
              <input v-model="form.tax_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-else class="rounded-md bg-accent-50 border border-accent-100 p-3 text-sm text-accent-600">
              {{ locale === 'cs' ? 'Zálohová faktura nemá DUZP. Daňový doklad k záloze se vystaví po platbě.' : 'Proforma has no tax point. The tax document is issued after payment.' }}
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.due_date') }} *</label>
              <input v-model="form.due_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.items') }}</h3>
          <button type="button" @click="addItem" class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
            {{ t('invoice.add_item') }}
          </button>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-8"></th>
              <th class="px-3 py-2 text-left font-medium">Popis</th>
              <th class="px-3 py-2 text-right font-medium w-20">{{ t('invoice.items_table.qty') }}</th>
              <th class="px-3 py-2 text-left font-medium w-16">{{ t('invoice.items_table.unit') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.items_table.unit_price') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('invoice.totals.vat') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.totals.total') }}</th>
              <th class="px-3 py-2 w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in form.items" :key="i">
              <td class="px-2 py-2 text-center text-xs text-neutral-400">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
              </td>
              <td class="px-3 py-2">
                <textarea v-model="item.description" rows="1" :placeholder="t('invoice.items_table.description')"
                  class="w-full px-2 py-1.5 border border-neutral-200 rounded text-sm resize-y min-h-[36px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
              </td>
              <td class="px-3 py-2">
                <input v-model.number="item.quantity" type="number" step="0.001" min="0"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm" />
              </td>
              <td class="px-3 py-2">
                <input v-model="item.unit"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-sm" />
              </td>
              <td class="px-3 py-2">
                <input v-model.number="item.unit_price_without_vat" type="number" step="0.01" min="0"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm" />
              </td>
              <td class="px-3 py-2">
                <select v-model.number="item.vat_rate_id" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ r.rate_percent }} %</option>
                </select>
              </td>
              <td class="px-3 py-2 text-right font-mono text-sm">
                {{ formatMoney(itemTotal(item), form.currency) }}
              </td>
              <td class="px-2 py-2 text-center">
                <button type="button" @click="removeItem(i)" class="text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
              </td>
            </tr>
            <tr v-if="form.items.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-neutral-400 text-sm">
                {{ t('invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('invoice.add_first') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Sumace + poznámky -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_above') }}</label>
            <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_below') }}</label>
            <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.summary') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <div v-for="b in computed_totals.breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
              <dt>Základ {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.base, form.currency) }}</dd>
            </div>
            <div v-for="b in computed_totals.breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between text-neutral-600">
              <dt>DPH {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.vat, form.currency) }}</dd>
            </div>
            <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 font-semibold">
              <dt>{{ t('invoice.totals.without_vat') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.without_vat, form.currency) }}</dd>
            </div>
            <div class="flex justify-between font-semibold">
              <dt>{{ t('invoice.totals.vat_total') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.vat, form.currency) }}</dd>
            </div>
            <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
              <dt>{{ t('invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.with_vat, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
              <dt>{{ t('invoice.totals.advance_deduction') }}</dt>
              <dd class="font-mono">−{{ formatMoney(form.advance_paid_amount, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-base font-semibold pt-1">
              <dt>{{ t('invoice.totals.amount_due') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.amount_to_pay, form.currency) }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Výkaz víceprací -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.work_report') }}</h3>
          <div class="flex items-center gap-2">
            <button v-if="!wrOpen" type="button" @click="openWorkReport"
              class="cursor-pointer px-4 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_add') }}
            </button>
            <button v-if="wrOpen && wrItems.length > 0" type="button" @click="pushWrToInvoiceItem"
              class="cursor-pointer px-4 h-9 text-sm bg-emerald-700 hover:bg-emerald-800 text-white font-semibold rounded-md inline-flex items-center gap-1.5 shadow-sm">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
              {{ t('invoice.wr_push_to_item') }}
            </button>
            <button v-if="wrOpen && wrItems.length > 0" type="button" @click="deleteWorkReport"
              class="cursor-pointer px-3 h-8 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('invoice.wr_delete') }}
            </button>
          </div>
        </header>
        <div v-if="wrOpen" class="p-5 space-y-3">
          <input v-model="wrTitle" type="text" :placeholder="t('invoice.wr_title')"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                <th class="px-3 py-2 text-left font-medium w-36">{{ t('invoice.wr_date') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_hours') }}</th>
                <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.wr_rate') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('invoice.wr_total') }}</th>
                <th class="px-2 py-2 w-8"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(it, i) in wrItems" :key="i">
                <td class="px-2 py-1.5">
                  <input v-model="it.description" type="text" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model="it.work_date" type="date" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.hours" type="number" step="0.25" min="0" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-2 py-1.5">
                  <input v-model.number="it.rate" type="number" step="1" min="0" class="w-full h-9 px-2 border border-neutral-200 rounded text-sm text-right font-mono" />
                </td>
                <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                  {{ formatMoney((Number(it.hours) || 0) * (Number(it.rate) || 0), form.currency) }}
                </td>
                <td class="px-2 py-1.5 text-center">
                  <button type="button" @click="removeWrItem(i)" class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
                </td>
              </tr>
              <tr>
                <td colspan="6" class="p-2">
                  <button type="button" @click="addWrItem"
                    class="cursor-pointer px-3 h-8 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    {{ t('invoice.wr_add_row') }}
                  </button>
                </td>
              </tr>
              <tr v-if="wrItems.length > 0" class="bg-neutral-50 font-semibold">
                <td class="px-3 py-2 text-right">Σ</td>
                <td></td>
                <td class="px-3 py-2 text-right font-mono">{{ wrTotalHours.toFixed(2) }} h</td>
                <td></td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(wrTotalAmount, form.currency) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
          <p class="text-xs text-neutral-500">
            Výkaz se vytiskne na druhou stránku PDF faktury. Pokud je vyplněný, položku faktury si nastav ručně (např. „Vícepráce {{ wrTitle }} — celkem {{ wrTotalHours.toFixed(2) }} h × {{ wrItems[0]?.rate || 0 }} {{ form.currency }}").
          </p>
        </div>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <!-- Action bar -->
      <div class="bg-white border border-neutral-200 rounded-lg p-4 flex justify-between items-center sticky bottom-3 shadow-md">
        <RouterLink to="/invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('common.cancel') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>
  </div>
</template>
