import { api } from './client'

export interface SearchClient {
  id: number
  company_name: string
  main_email: string | null
  is_customer: boolean
  is_vendor: boolean
}

export interface SearchInvoice {
  id: number
  varsymbol: string | null
  invoice_type: string
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

export interface SearchPurchaseInvoice {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  document_kind: string | null
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

export interface SearchResults {
  q: string
  clients: SearchClient[]
  invoices: SearchInvoice[]
  purchase_invoices: SearchPurchaseInvoice[]
}

export const searchApi = {
  /** Globální vyhledávání — klienti/dodavatelé + vydané/přijaté faktury (dle čísla dokladu). */
  query: (q: string) => api.get<SearchResults>('/search', { params: { q } }).then(r => r.data),
}
