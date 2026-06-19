import { api } from './client'
import type { PaymentAccountSource } from './purchaseInvoices'

/** Stav ověření platebního účtu protistrany (CRPDPH / registr plátců). */
export type PaymentAccountVerified = 'verified' | 'not_listed' | 'unreliable' | 'na'

/** Plátcovský (vlastní) bankovní účet, ze kterého se hradí příkaz. */
export interface PayerAccount {
  id: number
  code: string
  label: string | null
  symbol: string | null
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  bic: string | null
  is_default: boolean
  is_active: boolean
}

/** Kandidát na zařazení do platebního příkazu (nezaplacená přijatá faktura). */
export interface PaymentCandidate {
  id: number
  vendor_id: number
  vendor_company_name: string | null
  vendor_dic: string | null
  vendor_invoice_number: string | null
  varsymbol: string | null
  document_kind: string | null
  issue_date: string | null
  due_date: string | null
  currency: string
  currency_symbol: string | null
  amount_to_pay: number
  total_with_vat: number
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  variable_symbol: string | null
  constant_symbol: string | null
  payment_account_source: PaymentAccountSource | null
  payment_ordered_at: string | null
  has_account: boolean
  has_pdf: boolean
  can_verify: boolean
  abo_eligible: boolean
  account_verified: PaymentAccountVerified
}

export interface VerifyAccountResponse {
  account_verified: PaymentAccountVerified
  found: boolean
  unreliable: boolean | null
  accounts: string[]
  dic: string | null
}

export interface PaymentOrderCandidatesResponse {
  payer_accounts: PayerAccount[]
  candidates: PaymentCandidate[]
}

/** Položka uloženého platebního příkazu (detail) — kanonický pohled z backendu. */
export interface PaymentOrderItem {
  purchase_invoice_id: number
  payee_name: string | null
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  amount: number
  currency: string
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  message: string | null
  account_verified: PaymentAccountVerified
}

/** Detail platebního příkazu (hlavička + položky) — kanonický pohled `view()`. */
export interface PaymentOrderView {
  id: number
  currency: string
  payment_date: string
  total_amount: number
  item_count: number
  mark_paid: boolean
  note: string | null
  created_at: string
  payer: {
    account_number: string | null
    bank_code: string | null
    iban: string | null
    bic: string | null
    label: string | null
  }
  supplier: {
    company_name: string | null
    abo_client_number: string | null
  }
  items: PaymentOrderItem[]
}

/** Položka v přehledu historie platebních příkazů. */
export interface PaymentOrderListItem {
  id: number
  currency: string
  payment_date: string
  total_amount: number
  item_count: number
  mark_paid: boolean
  note: string | null
  created_at: string
  payer_account_label: string | null
  payer_account_number: string | null
  payer_bank_code: string | null
  payer_iban: string | null
}

export interface CreatePaymentOrderPayload {
  invoice_ids: number[]
  payer_currency_id: number
  payment_date: string
  constant_symbol?: string
  note?: string
  mark_paid?: boolean
}

/** Důvod přeskočení faktury při vytváření příkazu. */
export type PaymentOrderSkipReason =
  | 'not_found'
  | 'currency_mismatch'
  | 'nothing_to_pay'
  | 'no_account'

export interface CreatePaymentOrderResponse {
  order_id: number
  view: PaymentOrderView
  skipped: Array<{ id: number; reason: PaymentOrderSkipReason | string }>
  clamped_date: boolean
}

export type PaymentOrderFormat = 'abo' | 'csv' | 'pdf'

export const paymentOrdersApi = {
  /** Kandidáti + plátcovské účty, volitelně filtrované měnou plátce. */
  candidates: (currency?: string) =>
    api.get<PaymentOrderCandidatesResponse>('/purchase-invoices/payment-orders/candidates', {
      params: currency ? { currency } : {},
    }).then(r => r.data),

  create: (payload: CreatePaymentOrderPayload) =>
    api.post<CreatePaymentOrderResponse>('/purchase-invoices/payment-orders', payload).then(r => r.data),

  /** „Jen označit" — zařadí faktury k úhradě bez exportu (volitelně rovnou paid). */
  markOrdered: (payload: { invoice_ids: number[]; mark_paid?: boolean }) =>
    api.post<{ count: number }>('/purchase-invoices/payment-orders/mark', payload).then(r => r.data),

  list: () =>
    api.get<{ data: PaymentOrderListItem[] }>('/purchase-invoices/payment-orders').then(r => r.data.data),

  get: (id: number) =>
    api.get<PaymentOrderView>(`/purchase-invoices/payment-orders/${id}`).then(r => r.data),

  /** On-demand kontrola účtu faktury proti zveřejněným účtům plátce DPH (CRPDPH). */
  verifyAccount: (invoiceId: number) =>
    api.get<VerifyAccountResponse>('/purchase-invoices/payment-orders/verify-account', {
      params: { invoice_id: invoiceId },
    }).then(r => r.data),

  /**
   * Stažení vygenerovaného souboru příkazu (ABO/KPC, CSV nebo PDF).
   * Mirror Export.vue: přímá navigace v prohlížeči (session cookie),
   * supplier_id v query param (X-Supplier-Id header se při window.open neposílá).
   */
  downloadUrl: (id: number, format: PaymentOrderFormat): string => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ format })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/purchase-invoices/payment-orders/${id}/download?${params.toString()}`
  },

  downloadPaymentOrder: (id: number, format: PaymentOrderFormat): void => {
    window.open(paymentOrdersApi.downloadUrl(id, format), '_blank')
  },
}
