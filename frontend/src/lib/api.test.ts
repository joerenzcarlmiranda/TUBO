import { afterEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('invoice API client', () => {
  it('sends bearer tokens and supported invoice filters', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [],
          meta: { current_page: 2, last_page: 3, total: 25, per_page: 10 },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    vi.stubGlobal('fetch', fetchMock)

    await api.listInvoices('jwt-token', {
      status: 'failed',
      invoice_number: 'INV-42',
      invoice_date_from: '2026-01-01',
      invoice_date_to: '2026-01-31',
      page: 2,
    })

    const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit]
    const requestUrl = new URL(url)
    const headers = new Headers(options.headers)
    expect(requestUrl.pathname).toBe('/api/v1/invoices')
    expect(requestUrl.searchParams.get('status')).toBe('failed')
    expect(requestUrl.searchParams.get('invoice_number')).toBe('INV-42')
    expect(requestUrl.searchParams.get('invoice_date_from')).toBe('2026-01-01')
    expect(requestUrl.searchParams.get('invoice_date_to')).toBe('2026-01-31')
    expect(requestUrl.searchParams.get('page')).toBe('2')
    expect(requestUrl.searchParams.get('per_page')).toBe('10')
    expect(headers.get('Authorization')).toBe('Bearer jwt-token')
  })

  it('omits empty optional item tax and sends customer optionals as null', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { id: 8 } }), {
        status: 202,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await api.createInvoice('jwt-token', {
      invoice_number: 'INV-8',
      invoice_date: '2026-10-29',
      customer_name: 'Customer',
      customer_tax_id: '',
      customer_email: '',
      currency: 'USD',
      items: [
        {
          description: 'Consulting',
          quantity: '2',
          unit_price: '12.50',
          tax_rate: '',
        },
      ],
    })

    const [, options] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(JSON.parse(String(options.body))).toEqual({
      invoice_number: 'INV-8',
      invoice_date: '2026-10-29',
      customer_name: 'Customer',
      customer_tax_id: null,
      customer_email: null,
      currency: 'USD',
      items: [
        { description: 'Consulting', quantity: '2', unit_price: '12.50' },
      ],
    })
  })

  it('preserves Laravel validation errors for the form', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            message: 'The given data was invalid.',
            errors: { invoice_number: ['The invoice number has already been taken.'] },
          }),
          { status: 422, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    )

    await expect(
      api.createInvoice('jwt-token', {
        invoice_number: 'INV-8',
        invoice_date: '2026-10-29',
        customer_name: 'Customer',
        customer_tax_id: '',
        customer_email: '',
        currency: 'USD',
        items: [
          { description: 'Consulting', quantity: '1', unit_price: '10', tax_rate: '' },
        ],
      }),
    ).rejects.toMatchObject({
      name: 'ApiError',
      status: 422,
      message: 'The given data was invalid.',
      errors: { invoice_number: ['The invoice number has already been taken.'] },
    })
  })
})
