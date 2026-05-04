<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
import { dashboardApi, type DashboardSummary } from '@/api/dashboard'
import { formatMoney, formatDate } from '@/composables/useFormat'
import RevenueChart from '@/components/charts/RevenueChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import StatusDoughnutChart from '@/components/charts/StatusDoughnutChart.vue'

const router = useRouter()
const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    summary.value = await dashboardApi.summary()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
})

const kpiGridCols = computed(() => {
  if (!summary.value) return 'lg:grid-cols-6'
  const showApprovals = isAdmin.value
    && (summary.value.pending_approvals?.requested ?? 0) > 0
  const currencies = summary.value.kpi.per_currency?.length ?? 0
  // Revenue tile spans 2 cols (lg:col-span-2), standardní boxy 1 col.
  // Sloty = currencies*2 + 4 standard (Vystaveno/Po splatnosti/Před splatností/Ø) [+ 1 schvalování]
  const slots = currencies * 2 + 4 + (showApprovals ? 1 : 0)
  // Tailwind musí vidět tyto třídy staticky — explicitní mapping.
  return ({
    6: 'lg:grid-cols-6',   // 1 měna: 1×wide + 4×slim, 1 řada
    7: 'lg:grid-cols-7',   // 1 měna + schvalování, 1 řada
    8: 'lg:grid-cols-4',   // 2 měny: 2 řady × 4 (revenue span 2)
    9: 'lg:grid-cols-3',   // 2 měny + schvalování
    10: 'lg:grid-cols-5',  // 3 měny: 2 řady × 5
  } as Record<number, string>)[slots] ?? 'lg:grid-cols-6'
})

const upcomingPerCurrency = computed(() => {
  if (!summary.value) return [] as Array<{ currency: string; total: number }>
  const map = new Map<string, number>()
  for (const i of summary.value.unpaid_upcoming) {
    map.set(i.currency, (map.get(i.currency) ?? 0) + Number(i.amount_to_pay || 0))
  }
  return Array.from(map, ([currency, total]) => ({ currency, total }))
})

const hasAnyRevenue = computed(() => {
  if (!summary.value) return false
  return summary.value.kpi.per_currency.some(c => c.this_year > 0 || c.prev_year > 0)
})

const hasAnyData = computed(() => {
  if (!summary.value) return false
  return summary.value.kpi.issued_count_ytd > 0
      || summary.value.overdue.length > 0
      || summary.value.unpaid_upcoming.length > 0
})

function openInvoice(id: number) {
  router.push(`/invoices/${id}`)
}
</script>

<template>
  <div>
    <div class="flex items-start justify-between gap-3 mb-6 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold mb-1">{{ t('dashboard.title') }}</h1>
        <p class="text-sm text-neutral-500">
          {{ t('dashboard.welcome_back', { name: auth.user?.name }) }}
        </p>
      </div>
      <div class="flex gap-2">
        <RouterLink to="/invoices/new"
          class="inline-flex items-center h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md shadow-sm">
          {{ t('invoice.new') }}
        </RouterLink>
        <RouterLink to="/clients/new"
          class="inline-flex items-center h-9 px-4 border border-primary-500/40 bg-white hover:bg-primary-50 text-primary-700 text-sm font-medium rounded-md">
          {{ t('client.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <div v-else-if="!hasAnyData" class="bg-white border border-neutral-200 rounded-lg p-8 text-center">
      <h2 class="text-lg font-semibold mb-2">{{ t('dashboard.welcome') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('common.no_data') }}</p>
      <div class="flex justify-center gap-3">
        <RouterLink to="/clients/new" class="px-4 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
          {{ t('client.new') }}
        </RouterLink>
        <RouterLink to="/invoices/new" class="px-4 h-10 inline-flex items-center border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-else-if="summary" class="space-y-6">
      <!-- KPI tiles — dynamicky N sloupců dle počtu měn (1 měna → 4 boxy, 2 měny → 5 boxů…) -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="kpiGridCols">
        <div v-for="c in summary.kpi.per_currency" :key="c.currency" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm lg:col-span-2">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.revenue', { year: summary.year, currency: c.currency }) }}</div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(c.this_year, c.currency) }}</div>
          <div v-if="c.change_pct !== null" class="text-xs mt-1" :class="c.change_pct >= 0 ? 'text-success-600' : 'text-danger-500'"
            :title="t('dashboard.yoy_ytd_tooltip', { year: summary.prev_year, total: formatMoney(c.prev_year, c.currency), ytd: formatMoney(c.prev_year_ytd, c.currency) })">
            {{ c.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(c.change_pct) }} % {{ t('dashboard.vs_prev_ytd', { year: summary.prev_year }) }}
          </div>
          <div v-else class="text-xs text-neutral-400 mt-1">{{ t('dashboard.no_prev_year', { year: summary.prev_year }) }}</div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.issued_count', { year: summary.year }) }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.kpi.issued_count_ytd }}</div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.invoices_unit') }}</div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm" :class="{'border-danger-500/40': summary.kpi.overdue_count > 0}">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.overdue') }}</div>
          <div class="text-2xl font-semibold" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-900'">
            {{ summary.kpi.overdue_count }}
          </div>
          <div class="text-xs mt-1 flex flex-wrap gap-x-3" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">
            <span v-for="o in summary.kpi.overdue_per_currency" :key="o.currency">
              {{ formatMoney(o.total, o.currency) }}
            </span>
            <span v-if="summary.kpi.overdue_count === 0">{{ t('dashboard.all_ok') }}</span>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.upcoming') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.unpaid_upcoming.length }}</div>
          <div class="text-xs mt-1 text-neutral-400 flex flex-wrap gap-x-3">
            <span v-for="u in upcomingPerCurrency" :key="u.currency">
              {{ formatMoney(u.total, u.currency) }}
            </span>
            <span v-if="!upcomingPerCurrency.length">{{ t('dashboard.upcoming_none') }}</span>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.avg_payment') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">
            {{ summary.kpi.avg_payment_days !== null ? summary.kpi.avg_payment_days + ' ' + t('dashboard.days') : '—' }}
          </div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.this_year_paid') }}</div>
        </div>

        <!-- Pending approvals tile (admin only, jen pokud existují requested) -->
        <RouterLink
          v-if="isAdmin && summary.pending_approvals && summary.pending_approvals.requested > 0"
          to="/admin/approvals"
          class="bg-white border rounded-lg p-5 shadow-sm hover:bg-primary-50 transition cursor-pointer"
          :class="summary.pending_approvals.overdue > 0 ? 'border-warning-500/50' : 'border-primary-500/40'">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.pending_approvals') }}</div>
          <div class="text-2xl font-semibold"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-primary-700'">
            {{ summary.pending_approvals.requested }}
          </div>
          <div class="text-xs mt-1"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-neutral-400'">
            <span v-if="summary.pending_approvals.overdue > 0">
              {{ t('dashboard.pending_approvals_overdue', { n: summary.pending_approvals.overdue }) }}
            </span>
            <span v-else>{{ t('dashboard.pending_approvals_hint') }}</span>
          </div>
        </RouterLink>
      </div>

      <!-- Top klienti — koláč 2026 + 2025 vedle sebe -->
      <div v-if="(summary.top_clients_ytd.length + summary.top_clients_prev_year.length) > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('dashboard.top_clients_year', { year: summary.year }) }}
          </h3>
          <TopClientsPieChart :clients="summary.top_clients_ytd" currency="CZK" />
        </div>
        <!-- Pokud je minulý rok prázdný, místo prázdné pie zobraz stav faktur YTD -->
        <div v-if="summary.top_clients_prev_year.length > 0" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('dashboard.top_clients_year', { year: summary.prev_year }) }}
          </h3>
          <TopClientsPieChart :clients="summary.top_clients_prev_year" currency="CZK" />
        </div>
        <div v-else class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('dashboard.status_for_year', { year: summary.year }) }}
          </h3>
          <StatusDoughnutChart :counts="summary.kpi.status_counts_ytd || {}" />
        </div>
      </div>

      <!-- Revenue chart per currency — posledních 12 měsíců (rolling) -->
      <div v-if="hasAnyRevenue" class="space-y-4">
        <div v-for="rev in summary.revenue_by_month" :key="rev.currency" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('dashboard.revenue_last_12_months', { currency: rev.currency }) }}
          </h3>
          <RevenueChart :months="rev.months" :prev-year="rev.prev_year" :currency="rev.currency" />
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Po splatnosti -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.overdue_table') }}</h3>
            <span v-if="summary.overdue.length" class="text-xs px-2 py-0.5 rounded bg-danger-50 text-danger-500">
              {{ summary.overdue.length }}
            </span>
          </header>
          <div v-if="!summary.overdue.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.overdue_none') }}
          </div>
          <table v-else class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('dashboard.overdue') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.overdue" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">{{ i.varsymbol }}</td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium">
                    +{{ i.days_overdue }}d
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Nezaplacené -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.unpaid_upcoming') }}</h3>
            <span v-if="summary.unpaid_upcoming.length" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">
              {{ summary.unpaid_upcoming.length }}
            </span>
          </header>
          <div v-if="!summary.unpaid_upcoming.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.unpaid_none') }}
          </div>
          <table v-else class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('invoice.due_date') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.unpaid_upcoming" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">{{ i.varsymbol }}</td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center text-xs">{{ formatDate(i.due_date) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Top klienti YTD -->
      <div v-if="summary.top_clients_ytd.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold">{{ t('dashboard.top_clients_year', { year: summary.year }) }}</h3>
        </header>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium w-8">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
              <th class="px-4 py-2 text-center font-medium">Faktur</th>
              <th class="px-4 py-2 text-right font-medium">Obrat</th>
              <th class="px-4 py-2 text-left font-medium w-32">{{ t('common.share') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(c, i) in summary.top_clients_ytd" :key="c.client_id + c.currency" class="hover:bg-neutral-50 cursor-pointer"
                @click="router.push(`/clients/${c.client_id}`)">
              <td class="px-4 py-2.5 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 font-medium">{{ c.company_name }}</td>
              <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ c.invoice_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(c.total, c.currency) }}</td>
              <td class="px-4 py-2.5">
                <div class="h-2 bg-neutral-100 rounded-full overflow-hidden">
                  <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total / summary.top_clients_ytd[0].total * 100) + '%' }"></div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
