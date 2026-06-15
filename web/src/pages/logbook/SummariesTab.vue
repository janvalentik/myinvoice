<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { logbookApi, type LogbookSummary } from '@/api/logbook'
import MonthlyKmChart from '@/components/charts/MonthlyKmChart.vue'
import CumulativeKmChart from '@/components/charts/CumulativeKmChart.vue'

const { t } = useI18n()
const toast = useToast()
const props = defineProps<{ resetToken?: number }>()

const data = ref<LogbookSummary | null>(null)
const loading = ref(false)
const year = ref<number | ''>('')
const exporting = ref(false)
const showContinuity = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    data.value = await logbookApi.summary(year.value ? Number(year.value) : undefined)
    if (year.value === '') year.value = data.value.year
  } finally { loading.value = false }
}
onMounted(load)
watch(year, (y, old) => { if (y !== old && y !== '') load() })
watch(() => props.resetToken, () => { year.value = ''; load() })

async function downloadExport(format: 'xlsx' | 'pdf') {
  if (!data.value) return
  exporting.value = true
  try {
    const r = await logbookApi.exportSummary(Number(data.value.year), format)
    const cd = (r.headers['content-disposition'] as string) || ''
    const m = /filename="?([^"]+)"?/.exec(cd)
    const a = document.createElement('a')
    a.href = URL.createObjectURL(r.data as Blob)
    a.download = m ? m[1] : `kniha-jizd-souhrn.${format}`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch { toast.error(t('logbook.export_failed')) } finally { exporting.value = false }
}

function km(n: number): string { return n.toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) }
function liters(n: number): string { return n.toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) }
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <select v-model="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
        <option v-for="y in (data?.available_years ?? [])" :key="y" :value="y">{{ y }}</option>
      </select>
      <div class="flex gap-2">
        <button @click="downloadExport('xlsx')" :disabled="exporting || !data?.vehicles.length"
          class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 16V4m0 12l-4-4m4 4l4-4"/></svg>
          XLSX
        </button>
        <button @click="downloadExport('pdf')" :disabled="exporting || !data?.vehicles.length"
          class="cursor-pointer h-9 px-3 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 16V4m0 12l-4-4m4 4l4-4"/></svg>
          PDF
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="!data || data.vehicles.length === 0" class="text-center text-neutral-500 py-12 text-sm">{{ t('logbook.no_summary') }}</div>

    <template v-else>
      <p class="text-sm text-neutral-500 mb-3">{{ t('logbook.summary_hint', { year: data.year }) }}</p>

      <div class="grid gap-3 sm:grid-cols-2">
        <div v-for="v in data.vehicles" :key="v.car_id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="flex items-baseline justify-between gap-2 mb-3">
            <h3 class="font-mono font-semibold text-neutral-900">{{ v.registration }}<span v-if="v.name" class="text-neutral-500 font-sans font-normal text-sm"> — {{ v.name }}</span></h3>
            <span class="text-xs text-neutral-500">{{ v.trips_count }} {{ t('logbook.summary_trips') }}</span>
          </div>

          <dl class="space-y-1.5 text-sm">
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.summary_km') }}</dt><dd class="font-mono font-semibold">{{ km(v.km) }} km</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.business') }}</dt><dd class="font-mono"><span class="text-success-600">{{ km(v.business_km + v.uncategorized_km) }} km</span> <span class="text-neutral-400">({{ v.business_ratio }} %)</span></dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.private') }}</dt><dd class="font-mono"><span :class="v.private_km > 0 ? 'text-warning-600' : 'text-neutral-400'">{{ km(v.private_km) }} km</span> <span class="text-neutral-400">({{ v.private_ratio }} %)</span></dd></div>
            <div v-if="v.uncategorized_km > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.summary_uncategorized') }}</dt><dd class="font-mono text-neutral-500">{{ km(v.uncategorized_km) }} km</dd></div>
            <div class="flex justify-between border-t border-neutral-100 pt-1.5"><dt class="text-neutral-500">{{ t('logbook.summary_odometer') }}</dt><dd class="font-mono">{{ v.odometer_start != null ? v.odometer_start.toLocaleString('cs-CZ') : '—' }} → {{ v.odometer_end != null ? v.odometer_end.toLocaleString('cs-CZ') : '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.summary_fuel') }}</dt><dd class="font-mono text-right">{{ v.fuel_count }}× · {{ formatMoney(v.fuel_cost, 'CZK') }}</dd></div>
            <div v-if="v.liters_count > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.summary_fuel_l') }}</dt>
              <dd class="font-mono text-right">{{ liters(v.liters) }} l<span v-if="v.liters_incomplete" class="text-warning-600">*</span><template v-if="v.avg_consumption != null"><span class="text-neutral-400"> · {{ v.liters_incomplete ? '≈ ' : '' }}{{ v.avg_consumption }} l/100 km</span><span v-if="v.liters_incomplete" class="text-warning-600" :title="t('logbook.consumption_approx')">*</span></template></dd>
            </div>
            <div v-if="v.kwh_count > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('logbook.summary_charging') }}</dt>
              <dd class="font-mono text-right">{{ liters(v.kwh) }} kWh<span v-if="v.kwh_incomplete" class="text-warning-600">*</span><template v-if="v.avg_consumption_kwh != null"><span class="text-neutral-400"> · {{ v.kwh_incomplete ? '≈ ' : '' }}{{ v.avg_consumption_kwh }} kWh/100 km</span><span v-if="v.kwh_incomplete" class="text-warning-600" :title="t('logbook.consumption_approx')">*</span></template></dd>
            </div>
            <div>
              <div class="flex justify-between">
                <dt class="text-neutral-500">{{ t('logbook.summary_continuity') }}</dt>
                <dd v-if="v.continuity_issues === 0" class="text-success-600">{{ t('logbook.summary_continuity_ok') }}</dd>
                <dd v-else>
                  <button @click="showContinuity = showContinuity === v.car_id ? null : v.car_id"
                    class="cursor-pointer text-warning-600 hover:underline">{{ t('logbook.summary_continuity_warn', { n: v.continuity_issues }) }} ›</button>
                </dd>
              </div>
              <div v-if="showContinuity === v.car_id && v.continuity_detail.length" class="mt-1.5 text-xs bg-warning-50 border border-warning-500/30 rounded-md p-2 space-y-1">
                <p class="text-neutral-600 mb-1">{{ t('logbook.continuity_explain') }}</p>
                <div v-for="(c, i) in v.continuity_detail" :key="i" class="text-neutral-600">
                  {{ formatDate(c.prev_date) }} (<span class="font-mono">{{ c.prev_end.toLocaleString('cs-CZ') }}</span>) → {{ formatDate(c.date) }} (<span class="font-mono">{{ c.start.toLocaleString('cs-CZ') }}</span>):
                  <span class="text-warning-700 font-medium">{{ c.gap > 0 ? t('logbook.continuity_gap', { km: Math.abs(c.gap).toLocaleString('cs-CZ') }) : t('logbook.continuity_overlap', { km: Math.abs(c.gap).toLocaleString('cs-CZ') }) }}</span>
                </div>
              </div>
            </div>
            <div class="flex justify-between border-t border-neutral-100 pt-1.5"><dt class="text-neutral-500">{{ t('logbook.summary_pausal') }}</dt><dd class="font-mono text-neutral-600">{{ formatMoney(v.pausal_year, 'CZK') }} <span class="text-neutral-400 text-xs">({{ v.pausal_months }}× {{ formatMoney(v.pausal_rate, 'CZK') }})</span></dd></div>
          </dl>
        </div>
      </div>

      <div class="bg-neutral-50 border border-neutral-200 rounded-lg p-4 mt-3 text-sm">
        <div class="font-semibold text-neutral-700 mb-2">{{ t('logbook.summary_total') }}</div>
        <div class="flex flex-wrap gap-x-6 gap-y-1 font-mono">
          <span>{{ t('logbook.summary_km') }}: <b>{{ km(data.totals.km) }} km</b></span>
          <span>{{ t('logbook.business') }}: <span class="text-success-600">{{ km(data.totals.business_km + data.totals.uncategorized_km) }} km</span></span>
          <span>{{ t('logbook.private') }}: <span :class="data.totals.private_km > 0 ? 'text-warning-600' : ''">{{ km(data.totals.private_km) }} km ({{ data.totals.private_ratio }} %)</span></span>
          <span v-if="data.totals.liters > 0">{{ t('logbook.summary_fuel_l') }}: {{ liters(data.totals.liters) }} l</span>
          <span v-if="data.totals.kwh > 0">{{ t('logbook.summary_charging') }}: {{ liters(data.totals.kwh) }} kWh</span>
          <span>{{ t('logbook.summary_fuel') }}: {{ formatMoney(data.totals.fuel_cost, 'CZK') }}</span>
          <span v-if="data.totals.continuity_issues > 0" class="text-warning-600">{{ t('logbook.summary_continuity_warn', { n: data.totals.continuity_issues }) }}</span>
        </div>
      </div>

      <div class="grid gap-3 lg:grid-cols-2 mt-3">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h3 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('logbook.monthly_km_title', { year: data.year, prev: data.monthly.prev_year }) }}</h3>
          <MonthlyKmChart :current="data.monthly.current" :previous="data.monthly.previous" :year="data.monthly.year" :prev-year="data.monthly.prev_year" />
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h3 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('logbook.cumulative_km_title', { year: data.year }) }}</h3>
          <CumulativeKmChart :current="data.monthly.current" :previous="data.monthly.previous" :year="data.monthly.year" :prev-year="data.monthly.prev_year" />
        </div>
      </div>

      <p class="text-xs text-neutral-400 mt-3">{{ t('logbook.summary_pausal_note') }}</p>
    </template>
  </section>
</template>
