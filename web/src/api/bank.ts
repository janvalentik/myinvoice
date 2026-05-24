import { api } from './client'

export interface BankStatement {
  id: number
  file_name: string
  account_number: string
  currency: string | null
  statement_date: string
  statement_number: string | null
  prev_balance: number
  curr_balance: number
  transaction_count: number
  matched_count: number
  imported_at: string
  has_file: boolean
}

export type MatchStatus = 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'

export interface BankTransaction {
  id: number
  statement_id: number
  posted_at: string
  amount: number
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  counterparty_account: string | null
  counterparty_bank: string | null
  counterparty_name: string | null
  description: string | null
  bank_ref: string | null
  matched_invoice_id: number | null
  matched_varsymbol?: string | null
  matched_invoice_amount?: number | null
  matched_client_name?: string | null
  match_status: MatchStatus
  matched_at: string | null
}

export interface BankStatementDetail extends BankStatement {
  credit_total: number
  debit_total: number
  transactions: BankTransaction[]
}

export interface ImportResult {
  statement_id: number
  transactions: number
  matched: number
  duplicate: boolean
}

export const bankApi = {
  list: () => api.get<BankStatement[]>('/bank-statements').then(r => r.data),
  get: (id: number) => api.get<BankStatementDetail>(`/bank-statements/${id}`).then(r => r.data),
  upload: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<ImportResult>('/bank-statements/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  matchManual: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; varsymbol?: string }) =>
    api.post<{ matched: true; paid_at?: string; purchase_invoice_id?: number }>(`/bank-transactions/${txId}/match`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.varsymbol ? { varsymbol: ref.varsymbol } : {}),
    }).then(r => r.data),
  ignore: (txId: number) =>
    api.post<{ ignored: true }>(`/bank-transactions/${txId}/ignore`, {}).then(r => r.data),
  unmatch: (txId: number) =>
    api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unmatch`, {}).then(r => r.data),
  rematch: (statementId: number) =>
    api.post<{ considered: number; newly_matched: number; newly_partial: number; still_unmatched: number }>(
      `/bank-statements/${statementId}/rematch`, {}).then(r => r.data),
  scan: () => api.post<{ scanned: number; imported: number; duplicate: number; errors: number }>(
    '/bank-statements/scan', {},
  ).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}`).then(r => r.data),
  /**
   * Build download URL pro originální GPC. Vrací absolutní URL — UI ji použije
   * v `<a href>` (browser stáhne přímo). Auth cookie se posílá automaticky.
   */
  downloadUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/download`
  },
}
