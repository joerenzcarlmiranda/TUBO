import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from '@/App'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('invoice workspace', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('signs in and loads company invoices', async () => {
    const user = userEvent.setup()
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/auth/login')) {
        return jsonResponse({ access_token: 'jwt-token' })
      }
      if (url.endsWith('/auth/me')) {
        return jsonResponse({ user: { name: 'Taylor User', email: 'taylor@example.com' } })
      }
      if (url.includes('/v1/invoices')) {
        return jsonResponse({
          data: [
            {
              id: 42,
              invoice_number: 'INV-0042',
              invoice_date: '2026-10-29',
              customer: { name: 'Northwind Ltd', email: 'billing@northwind.test' },
              currency: 'USD',
              subtotal: '125.00',
              tax_amount: '0.00',
              total_amount: '125.00',
              status: 'submitted',
            },
          ],
          meta: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
        })
      }
      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    render(<App />)
    await user.type(screen.getByLabelText('Work email'), 'taylor@example.com')
    await user.type(screen.getByLabelText('Password'), 'secure-password')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { name: 'Good to see you, Taylor User' }))
      .toBeInTheDocument()
    expect(await screen.findByText('INV-0042')).toBeInTheDocument()
    expect(screen.getByText('Northwind Ltd')).toBeInTheDocument()
    expect(screen.getByText('submitted')).toBeInTheDocument()
    expect(sessionStorage.getItem('tubo_access_token')).toBe('jwt-token')
  })

  it('submits multiple invoice items and returns to the invoice list', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('tubo_access_token', 'jwt-token')
    let submittedBody: Record<string, unknown> | null = null
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.endsWith('/auth/me')) {
        return jsonResponse({ user: { name: 'Taylor User', email: 'taylor@example.com' } })
      }
      if (url.includes('/v1/invoices')) {
        return jsonResponse({
          data: [],
          meta: { current_page: 1, last_page: 1, total: 0, per_page: 10 },
        })
      }
      if (url.endsWith('/invoices') && init?.method === 'POST') {
        submittedBody = JSON.parse(String(init.body)) as Record<string, unknown>
        return jsonResponse({ data: { id: 43, status: 'pending' } }, 202)
      }
      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    render(<App />)
    await screen.findByRole('heading', { name: 'Good to see you, Taylor User' })
    await user.click(screen.getByRole('button', { name: 'Create invoice' }))

    await user.type(screen.getByLabelText('Invoice number'), 'INV-0043')
    await user.type(screen.getByLabelText('Customer name'), 'Contoso')
    await user.type(screen.getByLabelText('Description'), 'Implementation')
    await user.type(screen.getByLabelText('Unit price'), '100.00')
    await user.click(screen.getByRole('button', { name: 'Add item' }))
    await user.type(screen.getAllByLabelText('Description')[1], 'Support')
    await user.type(screen.getAllByLabelText('Unit price')[1], '25.00')
    await user.click(screen.getByRole('button', { name: 'Create invoice' }))

    await waitFor(() => {
      expect(submittedBody).not.toBeNull()
    })
    expect(submittedBody).toMatchObject({
      invoice_number: 'INV-0043',
      customer_name: 'Contoso',
      items: [
        { description: 'Implementation', quantity: '1', unit_price: '100' },
        { description: 'Support', quantity: '1', unit_price: '25' },
      ],
    })
    expect(await screen.findByText('No invoices found')).toBeInTheDocument()
  })

  it('shows invoice status history and queues a retry for a transient failure', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('tubo_access_token', 'jwt-token')
    let retryCount = 0
    const failedInvoice = {
      id: 44,
      invoice_number: 'INV-0044',
      invoice_date: '2026-10-29',
      seller: { name: 'Tubo Demo Company', tax_id: null },
      customer: { name: 'Contoso', email: null, tax_id: null },
      currency: 'USD',
      subtotal: '100.00',
      tax_amount: '0.00',
      total_amount: '100.00',
      status: 'failed',
      items: [
        {
          id: 1,
          position: 1,
          description: 'Support',
          quantity: '1.000',
          unit_price: '100.0000',
          tax_rate: '0.0000',
          tax_amount: '0.00',
          line_total: '100.00',
        },
      ],
      processing_attempts: [
        {
          attempt_number: 1,
          status: 'failed',
          started_at: '2026-10-29T10:00:00.000000Z',
          finished_at: '2026-10-29T10:00:02.000000Z',
          http_status_code: 503,
          error_message: 'The government service is temporarily unavailable.',
        },
      ],
      retry_available_at: new Date(Date.now() - 60_000).toISOString(),
      created_at: '2026-10-29T09:59:00.000000Z',
    }
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)
      if (url.endsWith('/auth/me')) {
        return jsonResponse({ user: { name: 'Taylor User', email: 'taylor@example.com' } })
      }
      if (url.includes('/v1/invoices/44/retry')) {
        retryCount += 1
        return jsonResponse({
          data: {
            ...failedInvoice,
            status: 'pending',
          },
        }, 202)
      }
      if (url.includes('/v1/invoices/44')) {
        return jsonResponse({ data: failedInvoice })
      }
      if (url.includes('/v1/invoices?')) {
        return jsonResponse({
          data: [
            {
              id: 44,
              invoice_number: failedInvoice.invoice_number,
              invoice_date: failedInvoice.invoice_date,
              customer: failedInvoice.customer,
              currency: failedInvoice.currency,
              subtotal: failedInvoice.subtotal,
              tax_amount: failedInvoice.tax_amount,
              total_amount: failedInvoice.total_amount,
              status: failedInvoice.status,
            },
          ],
          meta: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
        })
      }
      throw new Error(`Unexpected request: ${url} ${init?.method ?? 'GET'}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    render(<App />)
    await screen.findByRole('heading', { name: 'Good to see you, Taylor User' })
    await screen.findByText('INV-0044')
    await user.click(screen.getByRole('button', { name: 'View details' }))

    expect(await screen.findByRole('heading', { name: 'INV-0044' })).toBeInTheDocument()
    expect(screen.getByText('Submission failed. Review the attempt history for details.'))
      .toBeInTheDocument()
    expect(screen.getByText('The government service is temporarily unavailable.'))
      .toBeInTheDocument()
    expect(screen.getByText('Support')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Retry submission' }))

    expect(await screen.findByText('Retry queued. The status will update as the worker processes it.'))
      .toBeInTheDocument()
    expect(screen.getByText('Queued and waiting for submission.')).toBeInTheDocument()
    expect(retryCount).toBe(1)
  })

  it('does not offer retry for a permanent government rejection', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('tubo_access_token', 'jwt-token')
    const invoice = {
      id: 45,
      invoice_number: 'INV-0045',
      invoice_date: '2026-10-29',
      seller: { name: 'Tubo Demo Company', tax_id: null },
      customer: { name: 'Contoso', email: null, tax_id: null },
      currency: 'USD',
      subtotal: '100.00',
      tax_amount: '0.00',
      total_amount: '100.00',
      status: 'failed',
      items: [],
      processing_attempts: [
        {
          attempt_number: 1,
          status: 'failed',
          started_at: '2026-10-29T10:00:00.000000Z',
          finished_at: '2026-10-29T10:00:02.000000Z',
          http_status_code: 400,
          error_message: 'The government service rejected the invoice.',
        },
      ],
      retry_available_at: null,
      created_at: '2026-10-29T09:59:00.000000Z',
    }
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input)
        if (url.endsWith('/auth/me')) {
          return jsonResponse({ user: { name: 'Taylor User', email: 'taylor@example.com' } })
        }
        if (url.includes('/v1/invoices/45')) {
          return jsonResponse({ data: invoice })
        }
        if (url.includes('/v1/invoices?')) {
          return jsonResponse({
            data: [
              {
                id: 45,
                invoice_number: invoice.invoice_number,
                invoice_date: invoice.invoice_date,
                customer: invoice.customer,
                currency: invoice.currency,
                subtotal: invoice.subtotal,
                tax_amount: invoice.tax_amount,
                total_amount: invoice.total_amount,
                status: invoice.status,
              },
            ],
            meta: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
          })
        }
        throw new Error(`Unexpected request: ${url}`)
      }),
    )

    render(<App />)
    await screen.findByRole('heading', { name: 'Good to see you, Taylor User' })
    await screen.findByText('INV-0045')
    await user.click(screen.getByRole('button', { name: 'View details' }))

    expect(await screen.findByText(
      'This failure is not eligible for retry. A retry is available only for transient submission failures.',
    ))
      .toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retry submission' })).not.toBeInTheDocument()
  })

  it('disables manual retry until the server-provided cooldown expires', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem('tubo_access_token', 'jwt-token')
    const invoice = {
      id: 46,
      invoice_number: 'INV-0046',
      invoice_date: '2026-10-29',
      seller: { name: 'Tubo Demo Company', tax_id: null },
      customer: { name: 'Contoso', email: null, tax_id: null },
      currency: 'USD',
      subtotal: '100.00',
      tax_amount: '0.00',
      total_amount: '100.00',
      status: 'failed',
      items: [],
      processing_attempts: [
        {
          attempt_number: 1,
          status: 'failed',
          started_at: new Date(Date.now() - 30_000).toISOString(),
          finished_at: new Date(Date.now() - 20_000).toISOString(),
          http_status_code: 503,
          error_message: 'Service unavailable.',
        },
      ],
      retry_available_at: new Date(Date.now() + 300_000).toISOString(),
      created_at: new Date(Date.now() - 60_000).toISOString(),
    }
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.endsWith('/auth/me')) {
        return jsonResponse({ user: { name: 'Taylor User', email: 'taylor@example.com' } })
      }
      if (url.includes('/v1/invoices/46')) {
        return jsonResponse({ data: invoice })
      }
      if (url.includes('/v1/invoices?')) {
        return jsonResponse({
          data: [
            {
              id: 46,
              invoice_number: invoice.invoice_number,
              invoice_date: invoice.invoice_date,
              customer: invoice.customer,
              currency: invoice.currency,
              subtotal: invoice.subtotal,
              tax_amount: invoice.tax_amount,
              total_amount: invoice.total_amount,
              status: invoice.status,
            },
          ],
          meta: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
        })
      }
      throw new Error(`Unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    render(<App />)
    await screen.findByRole('heading', { name: 'Good to see you, Taylor User' })
    await screen.findByText('INV-0046')
    await user.click(screen.getByRole('button', { name: 'View details' }))

    const retryButton = await screen.findByRole('button', {
      name: /Retry available in/,
    })
    expect(retryButton).toBeDisabled()
    expect(screen.getByText(/wait until the cooldown ends/)).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalledWith(
      expect.stringContaining('/retry'),
      expect.objectContaining({ method: 'POST' }),
    )
  })
})
