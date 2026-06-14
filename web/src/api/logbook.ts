import { api } from './client'

export type FuelType = 'diesel' | 'petrol' | 'lpg' | 'cng' | 'electric' | 'hybrid' | 'other'

export interface Car {
  id: number
  registration: string
  name: string | null
  brand: string | null
  model: string | null
  vin: string | null
  fuel_type: FuelType | null
  odometer_start: number | null
  odometer_start_date: string | null
  is_default: boolean
  is_archived: boolean
  note: string | null
  created_at: string
  trips_count?: number | null
  fuelings_count?: number | null
  /** Poslední známý konečný tachometr (pro předvyplnění nového záznamu). */
  last_odometer?: number | null
}

export interface CarPayload {
  registration: string
  name?: string | null
  brand?: string | null
  model?: string | null
  vin?: string | null
  fuel_type?: FuelType | null
  odometer_start?: number | null
  odometer_start_date?: string | null
  is_default?: boolean
  is_archived?: boolean
  note?: string | null
}

export interface TripCategory {
  id: number
  code: string
  label: string
  is_private: boolean
  display_order: number
  is_archived: boolean
  trips_count?: number | null
}

export interface TripCategoryPayload {
  code: string
  label: string
  is_private?: boolean
  display_order?: number
  is_archived?: boolean
}

export interface Trip {
  id: number
  car_id: number
  car_registration: string | null
  car_name: string | null
  trip_date: string
  time_start: string | null
  time_end: string | null
  odometer_start: number | null
  odometer_end: number | null
  distance_km: number
  category_id: number | null
  category_label: string | null
  category_is_private: boolean | null
  purpose: string | null
  origin: string | null
  destination: string | null
  note: string | null
  created_at: string
}

export interface TripPayload {
  car_id: number
  trip_date: string
  time_start?: string | null
  time_end?: string | null
  odometer_start?: number | null
  odometer_end?: number | null
  distance_km?: number | null
  category_id?: number | null
  purpose?: string | null
  origin?: string | null
  destination?: string | null
  note?: string | null
}

export type FuelingSource = 'manual' | 'invoice' | 'axigon' | 'axigon_ai' | 'import'

export interface Fueling {
  id: number
  car_id: number | null
  car_registration: string | null
  car_name: string | null
  fueled_date: string
  fueled_time: string | null
  fuel_type: string | null
  quantity: number | null
  unit: string
  unit_price: number | null
  amount_without_vat: number | null
  amount_vat: number | null
  amount_with_vat: number
  currency: string
  odometer: number | null
  odometer_estimated: number | null
  station: string | null
  vendor_id: number | null
  vendor_name: string | null
  source: FuelingSource
  source_purchase_invoice_id: number | null
  source_invoice_number: string | null
  receipt_number: string | null
  raw_text: string | null
  note: string | null
  created_at: string
}

export interface FuelingPayload {
  car_id?: number | null
  fueled_date: string
  fueled_time?: string | null
  fuel_type?: string | null
  quantity?: number | null
  unit?: string
  unit_price?: number | null
  amount_without_vat?: number | null
  amount_vat?: number | null
  amount_with_vat: number
  currency?: string
  odometer?: number | null
  station?: string | null
  vendor_id?: number | null
  note?: string | null
}

export interface FuelInvoice {
  id: number
  vendor_id: number
  vendor_name: string
  vendor_ic: string | null
  issue_date: string | null
  vendor_invoice_number: string | null
  document_kind: string | null
  total_with_vat: number
  currency: string
  has_pdf: boolean
  fuelings_count: number
  scanned: boolean
}

export interface FuelInvoicesList {
  invoices: FuelInvoice[]
  cars: Car[]
  has_cars: boolean
}

export interface FuelInvoiceItem {
  description: string
  quantity: number | null
  unit: string
  total_without_vat: number | null
  total_with_vat: number | null
  is_fuel: boolean
}

export interface FuelInvoiceDetail {
  id: number
  vendor_name: string
  issue_date: string | null
  currency: string
  total_with_vat: number
  items: FuelInvoiceItem[]
}

export interface ScanResult {
  ok: boolean
  invoice_id: number
  created: number
  duplicates: number
  updated?: number
  fuel_rows: number
  parser: string
  status: string
  skipped?: boolean
  reassigned?: number
  error?: string
}

export interface BackfillReport {
  ok: boolean
  processed: number
  created: number
  duplicates: number
  updated: number
  remaining: number
}

export interface TripImportReport {
  ok: boolean
  dry_run?: boolean
  created: number
  failed: number
  new_categories?: string[]
  rows: Array<{ line: number; status: string; reason?: string; trip_id?: number }>
  error?: string
}

export interface SummaryVehicle {
  car_id: number
  registration: string
  name: string | null
  trips_count: number
  km: number
  business_km: number
  private_km: number
  uncategorized_km: number
  private_ratio: number
  business_ratio: number
  odometer_start: number | null
  odometer_end: number | null
  fuel_count: number
  liters: number
  liters_incomplete: boolean
  fuel_cost: number
  avg_consumption: number | null
  continuity_issues: number
  continuity_detail: Array<{ prev_date: string; prev_end: number; date: string; start: number; gap: number }>
  pausal_months: number
  pausal_rate: number
  pausal_year: number
}

export interface SummaryTotals {
  vehicles_count: number
  trips_count: number
  km: number
  business_km: number
  private_km: number
  uncategorized_km: number
  private_ratio: number
  fuel_count: number
  liters: number
  fuel_cost: number
  avg_consumption: number | null
  liters_incomplete: boolean
  continuity_issues: number
}

export interface MonthlyKm {
  year: number
  prev_year: number
  current: number[]
  previous: number[]
}

export interface LogbookSummary {
  year: number
  available_years: number[]
  vehicles: SummaryVehicle[]
  totals: SummaryTotals
  monthly: MonthlyKm
}

export const logbookApi = {
  // Cars
  listCars: (includeArchived = false) =>
    api.get<Car[]>('/logbook/cars', { params: includeArchived ? { include_archived: 1 } : undefined }).then(r => r.data),
  getCar: (id: number) => api.get<Car>(`/logbook/cars/${id}`).then(r => r.data),
  createCar: (data: CarPayload) => api.post<Car>('/logbook/cars', data).then(r => r.data),
  updateCar: (id: number, data: CarPayload) => api.put<Car>(`/logbook/cars/${id}`, data).then(r => r.data),
  deleteCar: (id: number) => api.delete<{ deleted: boolean; archived: boolean; usage_count?: number }>(`/logbook/cars/${id}`).then(r => r.data),

  // Trip categories
  listCategories: (includeArchived = false) =>
    api.get<TripCategory[]>('/logbook/trip-categories', { params: includeArchived ? { include_archived: 1 } : undefined }).then(r => r.data),
  createCategory: (data: TripCategoryPayload) => api.post<TripCategory>('/logbook/trip-categories', data).then(r => r.data),
  updateCategory: (id: number, data: TripCategoryPayload) => api.put<TripCategory>(`/logbook/trip-categories/${id}`, data).then(r => r.data),
  deleteCategory: (id: number) => api.delete<{ deleted: boolean; archived: boolean; usage_count?: number }>(`/logbook/trip-categories/${id}`).then(r => r.data),

  // Trips
  listTrips: (params?: Record<string, string | number>) => api.get<Trip[]>('/logbook/trips', { params }).then(r => r.data),
  tripPurposes: () => api.get<string[]>('/logbook/trips/purposes').then(r => r.data),
  tripPlaces: () => api.get<string[]>('/logbook/trips/places').then(r => r.data),
  getTrip: (id: number) => api.get<Trip>(`/logbook/trips/${id}`).then(r => r.data),
  createTrip: (data: TripPayload) => api.post<Trip>('/logbook/trips', data).then(r => r.data),
  updateTrip: (id: number, data: TripPayload) => api.put<Trip>(`/logbook/trips/${id}`, data).then(r => r.data),
  deleteTrip: (id: number) => api.delete<{ deleted: boolean }>(`/logbook/trips/${id}`).then(r => r.data),
  importTrips: (file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<TripImportReport>('/logbook/trips/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  // Vrací celou odpověď (blob) — komponenta si z hlaviček vytáhne název souboru.
  exportTrips: (format: 'xlsx' | 'pdf', params: Record<string, string | number>) =>
    api.get('/logbook/trips/export', { params: { format, ...params }, responseType: 'blob' }),

  // Fuelings
  listFuelings: (params?: Record<string, string | number>) => api.get<Fueling[]>('/logbook/fuelings', { params }).then(r => r.data),
  createFueling: (data: FuelingPayload) => api.post<Fueling>('/logbook/fuelings', data).then(r => r.data),
  updateFueling: (id: number, data: FuelingPayload) => api.put<Fueling>(`/logbook/fuelings/${id}`, data).then(r => r.data),
  deleteFueling: (id: number) => api.delete<{ deleted: boolean }>(`/logbook/fuelings/${id}`).then(r => r.data),
  exportFuelings: (format: 'xlsx' | 'pdf', params: Record<string, string | number>) =>
    api.get('/logbook/fuelings/export', { params: { format, ...params }, responseType: 'blob' }),

  // Fuel invoices (from gas stations)
  listFuelInvoices: (params?: Record<string, string | number>) =>
    api.get<FuelInvoicesList>('/logbook/fuel-invoices', { params }).then(r => r.data),
  fuelInvoiceItems: (id: number) =>
    api.get<FuelInvoiceDetail>(`/logbook/fuel-invoices/${id}/items`).then(r => r.data),
  assignFuelInvoice: (id: number, carId: number | null) =>
    api.post<ScanResult>(`/logbook/fuel-invoices/${id}/assign`, { car_id: carId }).then(r => r.data),
  backfillFuelInvoices: (limit = 25) =>
    api.post<BackfillReport>('/logbook/fuel-invoices/backfill', { limit }).then(r => r.data),

  // Souhrny (daňové/účetní)
  summary: (year?: number) =>
    api.get<LogbookSummary>('/logbook/summary', { params: year ? { year } : undefined }).then(r => r.data),
  exportSummary: (year: number, format: 'xlsx' | 'pdf') =>
    api.get('/logbook/summary/export', { params: { year, format }, responseType: 'blob' }),
}
