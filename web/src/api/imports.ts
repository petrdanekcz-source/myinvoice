import { api } from './client'

export interface ImportResultRow {
  file: string
  status: 'created' | 'skipped' | 'failed'
  reason?: string
  invoice_id?: number
  client_id?: number
  client_created?: boolean
  project_id?: number | null
  varsymbol?: string
  imported_status?: 'paid' | 'issued'
}

export interface ImportReport {
  summary: { created: number; skipped: number; failed: number }
  results: ImportResultRow[]
}

export async function uploadImport(files: File[]): Promise<ImportReport> {
  const fd = new FormData()
  for (const f of files) fd.append('files[]', f, f.name)
  const r = await api.post<ImportReport>('/admin/import', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return r.data
}
