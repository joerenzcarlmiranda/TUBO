export type InvoiceStatus = 'pending' | 'processing' | 'submitted' | 'failed'

export type Invoice = {
  id: number
  invoice_number: string
  invoice_date: string
  customer: {
    name: string
    email: string | null
    tax_id: string | null
  }
  currency: string
  subtotal: string
  tax_amount: string
  total_amount: string
  status: InvoiceStatus
}

export type InvoiceDetail = Invoice & {
  seller: {
    name: string
    tax_id: string | null
  }
  customer: {
    name: string
    email: string | null
    tax_id: string | null
  }
  items: {
    id: number
    position: number
    description: string
    quantity: string
    unit_price: string
    tax_rate: string
    tax_amount: string
    line_total: string
  }[]
  processing_attempts: {
    attempt_number: number
    status: 'processing' | 'succeeded' | 'failed'
    started_at: string
    finished_at: string | null
    http_status_code: number | null
    error_message: string | null
  }[]
  retry_available_at: string | null
  created_at: string
}

export type InvoiceItemInput = {
  description: string
  quantity: string
  unit_price: string
  tax_rate: string
}

export type CreateInvoiceInput = {
  invoice_number: string
  invoice_date: string
  customer_name: string
  customer_tax_id: string
  customer_email: string
  currency: string
  items: InvoiceItemInput[]
}

export type InvoiceFilters = {
  status: string
  invoice_number: string
  invoice_date_from: string
  invoice_date_to: string
  page: number
}

export type InvoicePage = {
  data: Invoice[]
  meta: {
    current_page: number
    last_page: number
    total: number
    per_page: number
  }
}

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readValidationErrors(value: unknown): Record<string, string[]> {
  if (!isRecord(value)) return {}

  return Object.fromEntries(
    Object.entries(value).flatMap(([field, messages]) => {
      if (!Array.isArray(messages)) return []
      const textMessages = messages.filter(
        (message): message is string => typeof message === 'string',
      )
      return textMessages.length > 0 ? [[field, textMessages]] : []
    }),
  )
}

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api')
  .replace(/\/+$/, '')

async function request<T>(
  path: string,
  options: {
    method?: 'GET' | 'POST'
    token?: string
    body?: unknown
  } = {},
): Promise<T> {
  const headers = new Headers({ Accept: 'application/json' })
  if (options.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }
  if (options.token) {
    headers.set('Authorization', `Bearer ${options.token}`)
  }

  let response: Response
  try {
    response = await fetch(`${apiBaseUrl}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    })
  } catch {
    throw new ApiError(
      'Unable to reach the API. Check that the backend is running and try again.',
      0,
    )
  }

  const payload: unknown = await response.json().catch(() => null)
  if (!response.ok) {
    const body = isRecord(payload) ? payload : {}
    const message =
      typeof body.message === 'string'
        ? body.message
        : `The request failed with status ${response.status}.`

    throw new ApiError(message, response.status, readValidationErrors(body.errors))
  }

  return payload as T
}

export const api = {
  login(email: string, password: string) {
    return request<{ access_token: string }>('/auth/login', {
      method: 'POST',
      body: { email, password },
    })
  },

  me(token: string) {
    return request<{ user: { name: string; email: string } }>('/auth/me', { token })
  },

  logout(token: string) {
    return request<{ message: string }>('/auth/logout', { method: 'POST', token })
  },

  listInvoices(token: string, filters: InvoiceFilters) {
    const params = new URLSearchParams({ per_page: '10', page: String(filters.page) })
    if (filters.status) params.set('status', filters.status)
    if (filters.invoice_number) params.set('invoice_number', filters.invoice_number)
    if (filters.invoice_date_from) params.set('invoice_date_from', filters.invoice_date_from)
    if (filters.invoice_date_to) params.set('invoice_date_to', filters.invoice_date_to)
    return request<InvoicePage>(`/v1/invoices?${params.toString()}`, { token })
  },

  getInvoice(token: string, invoiceId: number) {
    return request<{ data: InvoiceDetail }>(`/v1/invoices/${invoiceId}`, { token })
  },

  retryInvoice(token: string, invoiceId: number) {
    return request<{ data: InvoiceDetail }>(`/v1/invoices/${invoiceId}/retry`, {
      method: 'POST',
      token,
    })
  },

  createInvoice(token: string, invoice: CreateInvoiceInput) {
    return request<{ data: Invoice }>('/invoices', {
      method: 'POST',
      token,
      body: {
        ...invoice,
        customer_tax_id: invoice.customer_tax_id || null,
        customer_email: invoice.customer_email || null,
        items: invoice.items.map((item) => ({
          description: item.description,
          quantity: item.quantity,
          unit_price: item.unit_price,
          ...(item.tax_rate ? { tax_rate: item.tax_rate } : {}),
        })),
      },
    })
  },
}
