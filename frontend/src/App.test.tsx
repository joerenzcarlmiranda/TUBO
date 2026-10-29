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
})
