/**
 * TS port výpočetního jádra (zrcadlo api/src/Service/Tax/TaxOptimizer.php).
 * Slouží k okamžitému přepočtu při změně profilu na stránce — konstanty roku
 * (`c`) chodí z backendu (TaxConstants), takže existuje jediný zdroj čísel.
 * Autoritativní výpočet zůstává na backendu (PHP), tohle je pro svižné UI.
 */
import type { TaxConstantsData, TaxProfile } from '@/api/tax'

export interface EngineProfile extends TaxProfile {
  is_vat_payer: boolean
}

const ORDER = ['band1', 'band2', 'band3'] as const

export interface BandResult {
  ok: boolean
  reason?: string
  declared?: string
  eff?: string
  ceiling?: number
  surcharge?: number
}

function effBand(declared: string, rate: number, income: number, c: TaxConstantsData): BandResult {
  if (declared === 'none' || !(declared in c.pausal_annual)) return { ok: false, reason: 'not_in_pausal' }
  if (income > c.vat_limit_low) return { ok: false, reason: 'over_2m' }
  const cl = c.band_ceilings[rate] || c.band_ceilings[40]
  let i = ORDER.indexOf(declared as typeof ORDER[number])
  while (i < 2 && income > cl[ORDER[i]]) i++
  const eff = ORDER[i]
  if (income > cl[eff]) return { ok: false, reason: 'over_2m' }
  return {
    ok: true, declared, eff, ceiling: cl[eff],
    surcharge: eff !== declared ? c.pausal_annual[eff] - c.pausal_annual[declared] : 0,
  }
}

export interface PausalResult {
  ok: boolean
  reason?: string
  total: number | null
  eff?: string
  declared?: string
  surcharge?: number
  note?: boolean
}

export function pausal(p: EngineProfile, income: number, c: TaxConstantsData): PausalResult {
  if (p.is_vat_payer) return { ok: false, reason: 'vat_payer', total: null }
  const b = effBand(p.flat_tax_band, p.activity_rate, income, c)
  if (!b.ok) return { ok: false, reason: b.reason, total: null }
  return { ok: true, eff: b.eff, declared: b.declared, surcharge: b.surcharge, total: c.pausal_annual[b.eff!], note: b.eff !== b.declared }
}

function kidSum(n: number, arr: number[]): number {
  let s = 0
  for (let i = 1; i <= n; i++) s += arr[Math.min(i, arr.length) - 1]
  return s
}

export interface RegularResult {
  exp: number; ded: number; base: number; tax: number; kids: number
  incomeTax: number; isBonus: boolean; soc: number; hea: number; total: number
  net: number; eff: number; useActual: boolean
}

export function regular(p: EngineProfile, income: number, c: TaxConstantsData): RegularResult {
  // Skutečné výdaje (daňová evidence) NEBO výdajový paušál % se stropem.
  const exp = p.use_actual_expenses
    ? Math.max(0, p.actual_expenses || 0)
    : Math.min(income * p.activity_rate / 100, c.expense_caps[p.activity_rate])
  const ded = Math.min(p.mortgage_interest, c.mortgage_cap) + Math.min(p.pension_contrib, c.pension_cap)
    + (p.life_insurance || 0) + (p.donations || 0)
  const base = Math.max(0, income - exp - ded)
  const tax = base <= c.tax_high_threshold
    ? base * c.tax_rate_low
    : c.tax_high_threshold * c.tax_rate_low + (base - c.tax_high_threshold) * c.tax_rate_high
  const nonRef = c.credit_taxpayer + (p.spouse_credit ? c.credit_spouse : 0)
  const afterNonRef = Math.max(0, tax - nonRef)
  const kids = kidSum(p.children_count, c.child_credits)
  const incomeTax = afterNonRef - kids
  const profit = income - exp
  // Sociální 55 % zisku, zdravotní 50 % zisku (od 2024 odlišné), s ročními minimy.
  const soc = Math.max(profit * c.social_assessment_pct, p.is_secondary ? c.social_min_base_secondary : c.social_min_base_main) * c.social_rate
  const hea = Math.max(profit * c.health_assessment_pct, c.health_min_base) * c.health_rate
  const total = incomeTax + soc + hea
  // Čistý příjem = co reálně zbyde (paušální výdaje nejsou reálný výdaj); eff = odvody/příjem.
  return { exp, ded, base, tax, kids, incomeTax, isBonus: incomeTax < 0, soc, hea, total, net: income - total, eff: income > 0 ? total / income : 0, useActual: !!p.use_actual_expenses }
}

export interface CompareResult {
  pausal: PausalResult
  regular: RegularResult
  winner: 'pausal' | 'regular'
  delta: number | null
}

export function compare(p: EngineProfile, income: number, c: TaxConstantsData): CompareResult {
  const pa = pausal(p, income, c)
  const rg = regular(p, income, c)
  const winner: 'pausal' | 'regular' = !pa.ok ? 'regular' : (pa.total! <= rg.total ? 'pausal' : 'regular')
  const delta = pa.ok ? Math.round(rg.total - pa.total!) : null
  return { pausal: pa, regular: rg, winner, delta }
}

export interface Crossing { key: string; val: number; will: boolean; month: number | null }
export interface PredictResult {
  run: number; proj: number; cross: Crossing[]; deferMonth: number | null; ytd: number; months: number
}

export function predict(p: EngineProfile, ytd: number, months: number, c: TaxConstantsData): PredictResult {
  const run = months > 0 ? ytd / months : 0
  const proj = run * 12
  const T: { key: string; val: number }[] = []
  const cl = c.band_ceilings[p.activity_rate]
  if (p.flat_tax_band !== 'none' && cl && cl[p.flat_tax_band] != null) {
    T.push({ key: 'band', val: cl[p.flat_tax_band] })
  }
  T.push({ key: 'vatLow', val: c.vat_limit_low })
  T.push({ key: 'vatHigh', val: c.vat_limit_high })

  const cross: Crossing[] = T.map(t => {
    const m = run > 0 ? (t.val - ytd) / run + months : 99
    const will = proj >= t.val
    return { key: t.key, val: t.val, will, month: will ? Math.ceil(m) : null }
  })

  const v = cross.find(x => x.key === 'vatLow')
  const deferMonth = v && v.will && v.month! >= 11 && v.month! <= 12 ? v.month : null

  return { run, proj, cross, deferMonth, ytd, months }
}
