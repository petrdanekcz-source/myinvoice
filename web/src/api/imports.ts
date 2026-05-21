import { api } from './client'

export type ImportKind = 'auto' | 'issued' | 'purchase'

export interface ImportResultRow {
  file: string
  status: 'created' | 'skipped' | 'failed'
  reason?: string
  kind?: 'issued' | 'purchase' | null   // backend dispatch route (auto → konkrétní)
  invoice_id?: number          // pro issued
  purchase_invoice_id?: number  // pro purchase
  client_id?: number
  client_created?: boolean
  vendor_id?: number
  project_id?: number | null
  varsymbol?: string
  imported_status?: 'paid' | 'issued'
}

export interface ImportReport {
  summary: { created: number; skipped: number; failed: number }
  results: ImportResultRow[]
}

/**
 * Upload import s explicit kind:
 *   - 'auto'     (default) — per-soubor detekce dle IČO buyer/supplier
 *   - 'issued'   — vynutí issued route (vydané faktury)
 *   - 'purchase' — vynutí purchase route (přijaté faktury)
 */
export async function uploadImport(files: File[], kind: ImportKind = 'auto'): Promise<ImportReport> {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f, f.name)
  const r = await api.post<ImportReport>(`/admin/import?kind=${kind}`, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return r.data
}
