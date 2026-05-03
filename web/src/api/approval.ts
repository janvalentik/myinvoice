import axios from 'axios'

// Samostatný axios klient — public schvalování má být dostupné i nepřihlášeným,
// proto neimplementujeme 401 redirect na /login (to dělá @/api/client).
const publicApi = axios.create({
  baseURL: '/api/public',
  withCredentials: false,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

publicApi.interceptors.request.use((config) => {
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)
  return config
})

export interface PublicApprovalWorkReportItem {
  id: number
  description: string
  work_date: string | null
  hours: number
  rate: number
  total_amount: number
  order_index: number
}

export interface PublicApprovalWorkReport {
  id: number
  invoice_id: number
  project_id: number
  title: string
  total_hours: number
  total_amount: number
  items: PublicApprovalWorkReportItem[]
}

export interface PublicApprovalInvoice {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note'
  currency: string
  language: 'cs' | 'en'
  client_company_name: string | null
  project_name: string | null
  total_with_vat: number | null
  amount_to_pay: number | null
  requested_at: string | null
}

export interface PublicApprovalData {
  invoice: PublicApprovalInvoice
  work_report: PublicApprovalWorkReport
  supplier_name: string
  captcha_site_key: string
  captcha_provider: 'turnstile' | 'none' | string
}

export interface DecidePayload {
  decision: 'approve' | 'reject'
  decided_by_email?: string | null
  rejection_reason?: string | null
  cf_turnstile_response?: string | null
}

export interface DecideResult {
  decision: 'approved' | 'rejected'
  message: string
  sent_to?: string[]
  auto_send_error?: string
}

export const approvalApi = {
  get: (token: string) =>
    publicApi.get<PublicApprovalData>(`/approval/${token}`).then(r => r.data),

  decide: (token: string, payload: DecidePayload) =>
    publicApi.post<DecideResult>(`/approval/${token}/decide`, payload).then(r => r.data),
}
