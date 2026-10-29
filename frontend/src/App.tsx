import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
  type ReactNode,
} from 'react'
import {
  ArrowLeft,
  ArrowRight,
  BadgeCheck,
  CircleAlert,
  FilePlus2,
  FileText,
  Filter,
  LoaderCircle,
  LogOut,
  Plus,
  Search,
  Trash2,
  WalletCards,
} from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ApiError, api, type CreateInvoiceInput, type Invoice, type InvoiceFilters, type InvoiceItemInput, type InvoicePage } from '@/lib/api'

const emptyFilters: InvoiceFilters = {
  status: '',
  invoice_number: '',
  invoice_date_from: '',
  invoice_date_to: '',
  page: 1,
}

function today(): string {
  const date = new Date()
  const offset = date.getTimezoneOffset()
  return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10)
}

function emptyInvoice(): CreateInvoiceInput {
  return {
    invoice_number: '',
    invoice_date: today(),
    customer_name: '',
    customer_tax_id: '',
    customer_email: '',
    currency: 'USD',
    items: [{ description: '', quantity: '1', unit_price: '', tax_rate: '' }],
  }
}

function errorText(error: unknown): string {
  if (error instanceof Error) return error.message
  return 'Something went wrong. Please try again.'
}

function fieldError(error: ApiError | null, field: string): string | undefined {
  return error?.errors[field]?.[0]
}

function formatCurrency(value: string | number, currency = 'USD'): string {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return `${currency} ${value}`
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(amount)
  } catch {
    return `${currency} ${amount.toFixed(2)}`
  }
}

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

function StatusBadge({ status }: { status: Invoice['status'] }) {
  const styles: Record<Invoice['status'], string> = {
    pending: 'border-amber-200 bg-amber-50 text-amber-800',
    processing: 'border-sky-200 bg-sky-50 text-sky-800',
    submitted: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    failed: 'border-rose-200 bg-rose-50 text-rose-800',
  }

  return (
    <Badge variant="outline" className={`capitalize ${styles[status]}`}>
      <span className="mr-1.5 size-1.5 rounded-full bg-current" />
      {status}
    </Badge>
  )
}

function LoginScreen({
  onLogin,
}: {
  onLogin: (email: string, password: string) => Promise<void>
}) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await onLogin(email, password)
    } catch (loginError) {
      setError(errorText(loginError))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="grid min-h-screen bg-background lg:grid-cols-[1fr_1fr]">
      <section className="relative hidden overflow-hidden bg-slate-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="absolute -right-36 -top-32 size-[34rem] rounded-full border border-white/10" />
        <div className="absolute -right-20 -top-16 size-[25rem] rounded-full border border-white/10" />
        <div className="relative flex items-center gap-3">
          <div className="grid size-10 place-items-center rounded-xl bg-teal-300 text-slate-950">
            <FileText className="size-5" />
          </div>
          <span className="text-xl font-semibold tracking-tight">tubo</span>
        </div>
        <div className="relative max-w-xl pb-16">
          <p className="mb-5 text-sm font-medium uppercase tracking-[0.2em] text-teal-300">
            Invoice operations
          </p>
          <h1 className="text-5xl font-semibold leading-[1.08] tracking-tight">
            Invoices in order.
            <br />
            Business in motion.
          </h1>
          <p className="mt-6 max-w-md text-lg leading-relaxed text-slate-300">
            Create, track, and manage every invoice from one dependable workspace.
          </p>
        </div>
        <p className="relative text-sm text-slate-400">A clearer way to keep business moving.</p>
      </section>

      <section className="flex items-center justify-center px-6 py-12">
        <div className="w-full max-w-md">
          <div className="mb-10 flex items-center gap-3 lg:hidden">
            <div className="grid size-10 place-items-center rounded-xl bg-slate-950 text-white">
              <FileText className="size-5" />
            </div>
            <span className="text-xl font-semibold tracking-tight">tubo</span>
          </div>
          <div className="mb-8">
            <p className="mb-2 text-sm font-medium text-primary">Welcome back</p>
            <h2 className="text-3xl font-semibold tracking-tight">Sign in to your account</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              Use the account provided by your company administrator.
            </p>
          </div>

          {error && (
            <Alert variant="destructive" className="mb-5">
              <CircleAlert />
              <AlertTitle>Unable to sign in</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <form onSubmit={submit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="login-email">Work email</Label>
              <Input
                autoComplete="email"
                id="login-email"
                type="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="you@company.com"
                className="h-11"
              />
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="login-password">Password</Label>
              </div>
              <Input
                autoComplete="current-password"
                id="login-password"
                type="password"
                required
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                placeholder="Enter your password"
                className="h-11"
              />
            </div>
            <Button className="h-11 w-full" disabled={submitting}>
              {submitting && <LoaderCircle className="animate-spin" />}
              Sign in
              {!submitting && <ArrowRight className="ml-auto" />}
            </Button>
          </form>

          <p className="mt-8 text-center text-xs text-muted-foreground">
            Your account must be associated with a Tubo company workspace.
          </p>
        </div>
      </section>
    </main>
  )
}

function InvoiceForm({
  token,
  onCancel,
  onCreated,
}: {
  token: string
  onCancel: () => void
  onCreated: () => void
}) {
  const [invoice, setInvoice] = useState<CreateInvoiceInput>(emptyInvoice)
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const estimatedTotal = useMemo(
    () =>
      invoice.items.reduce((total, item) => {
        const line = Number(item.quantity || 0) * Number(item.unit_price || 0)
        const tax = line * (Number(item.tax_rate || 0) / 100)
        return total + line + tax
      }, 0),
    [invoice.items],
  )

  function updateField<K extends keyof CreateInvoiceInput>(
    key: K,
    value: CreateInvoiceInput[K],
  ) {
    setInvoice((current) => ({ ...current, [key]: value }))
  }

  function updateItem(index: number, patch: Partial<InvoiceItemInput>) {
    setInvoice((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) =>
        itemIndex === index ? { ...item, ...patch } : item,
      ),
    }))
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await api.createInvoice(token, invoice)
      onCreated()
    } catch (submitError) {
      setError(
        submitError instanceof ApiError
          ? submitError
          : new ApiError(errorText(submitError), 0),
      )
    } finally {
      setSubmitting(false)
    }
  }

  const messageFor = (field: string) => fieldError(error, field)

  return (
    <div className="mx-auto max-w-5xl">
      <Button variant="ghost" onClick={onCancel} className="mb-5 -ml-2">
        <ArrowLeft />
        Back to invoices
      </Button>
      <div className="mb-7">
        <p className="text-sm font-medium text-primary">Invoice workspace</p>
        <h1 className="mt-1 text-3xl font-semibold tracking-tight">Create an invoice</h1>
        <p className="mt-2 text-muted-foreground">
          Add customer details and line items. Totals are calculated securely by the server.
        </p>
      </div>

      {error && (
        <Alert variant="destructive" className="mb-6">
          <CircleAlert />
          <AlertTitle>Invoice could not be created</AlertTitle>
          <AlertDescription>
            <p>{error.message}</p>
            {error.status === 409 && (
              <p className="mt-1">Choose a different invoice number and try again.</p>
            )}
          </AlertDescription>
        </Alert>
      )}

      <form onSubmit={submit} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Invoice details</CardTitle>
            <CardDescription>Set the reference, date, and currency for this invoice.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 md:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="invoice-number">Invoice number</Label>
              <Input
                id="invoice-number"
                required
                maxLength={100}
                value={invoice.invoice_number}
                onChange={(event) => updateField('invoice_number', event.target.value)}
                aria-invalid={Boolean(messageFor('invoice_number'))}
                placeholder="INV-2025-001"
              />
              {messageFor('invoice_number') && (
                <p className="text-sm text-destructive">{messageFor('invoice_number')}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="invoice-date">Invoice date</Label>
              <Input
                id="invoice-date"
                type="date"
                required
                value={invoice.invoice_date}
                onChange={(event) => updateField('invoice_date', event.target.value)}
                aria-invalid={Boolean(messageFor('invoice_date'))}
              />
              {messageFor('invoice_date') && (
                <p className="text-sm text-destructive">{messageFor('invoice_date')}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="currency">Currency</Label>
              <Input
                id="currency"
                required
                minLength={3}
                maxLength={3}
                pattern="[A-Z]{3}"
                value={invoice.currency}
                onChange={(event) => updateField('currency', event.target.value.toUpperCase())}
                aria-invalid={Boolean(messageFor('currency'))}
                placeholder="USD"
              />
              {messageFor('currency') && (
                <p className="text-sm text-destructive">{messageFor('currency')}</p>
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Customer</CardTitle>
            <CardDescription>Who is receiving this invoice?</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-5 md:grid-cols-3">
            <div className="space-y-2">
              <Label htmlFor="customer-name">Customer name</Label>
              <Input
                id="customer-name"
                required
                maxLength={255}
                value={invoice.customer_name}
                onChange={(event) => updateField('customer_name', event.target.value)}
                aria-invalid={Boolean(messageFor('customer_name'))}
                placeholder="Acme Inc."
              />
              {messageFor('customer_name') && (
                <p className="text-sm text-destructive">{messageFor('customer_name')}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="customer-email">Email address</Label>
              <Input
                id="customer-email"
                type="email"
                maxLength={255}
                value={invoice.customer_email}
                onChange={(event) => updateField('customer_email', event.target.value)}
                aria-invalid={Boolean(messageFor('customer_email'))}
                placeholder="billing@acme.com"
              />
              {messageFor('customer_email') && (
                <p className="text-sm text-destructive">{messageFor('customer_email')}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="customer-tax">Tax ID <span className="text-muted-foreground">(optional)</span></Label>
              <Input
                id="customer-tax"
                maxLength={255}
                value={invoice.customer_tax_id}
                onChange={(event) => updateField('customer_tax_id', event.target.value)}
                aria-invalid={Boolean(messageFor('customer_tax_id'))}
                placeholder="Customer tax ID"
              />
              {messageFor('customer_tax_id') && (
                <p className="text-sm text-destructive">{messageFor('customer_tax_id')}</p>
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex-row items-start justify-between">
            <div>
              <CardTitle>Line items</CardTitle>
              <CardDescription className="mt-1">Add the goods or services being invoiced.</CardDescription>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                updateField('items', [
                  ...invoice.items,
                  { description: '', quantity: '1', unit_price: '', tax_rate: '' },
                ])
              }
              disabled={invoice.items.length >= 100}
            >
              <Plus />
              Add item
            </Button>
          </CardHeader>
          <CardContent className="space-y-4">
            {invoice.items.map((item, index) => (
              <div
                key={index}
                className="grid gap-4 rounded-lg border bg-muted/20 p-4 md:grid-cols-[minmax(12rem,2fr)_1fr_1fr_1fr_auto]"
              >
                <div className="space-y-2">
                  <Label htmlFor={`item-description-${index}`}>Description</Label>
                  <Input
                    id={`item-description-${index}`}
                    required
                    maxLength={10000}
                    value={item.description}
                    onChange={(event) => updateItem(index, { description: event.target.value })}
                    aria-invalid={Boolean(messageFor(`items.${index}.description`))}
                    placeholder="Product or service"
                  />
                  {messageFor(`items.${index}.description`) && (
                    <p className="text-sm text-destructive">
                      {messageFor(`items.${index}.description`)}
                    </p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor={`item-quantity-${index}`}>Quantity</Label>
                  <Input
                    id={`item-quantity-${index}`}
                    type="number"
                    required
                    min="0.001"
                    max="999999999.999"
                    step="0.001"
                    value={item.quantity}
                    onChange={(event) => updateItem(index, { quantity: event.target.value })}
                    aria-invalid={Boolean(messageFor(`items.${index}.quantity`))}
                  />
                  {messageFor(`items.${index}.quantity`) && (
                    <p className="text-sm text-destructive">
                      {messageFor(`items.${index}.quantity`)}
                    </p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor={`item-price-${index}`}>Unit price</Label>
                  <Input
                    id={`item-price-${index}`}
                    type="number"
                    required
                    min="0"
                    max="99999999999.9999"
                    step="0.0001"
                    value={item.unit_price}
                    onChange={(event) => updateItem(index, { unit_price: event.target.value })}
                    aria-invalid={Boolean(messageFor(`items.${index}.unit_price`))}
                  />
                  {messageFor(`items.${index}.unit_price`) && (
                    <p className="text-sm text-destructive">
                      {messageFor(`items.${index}.unit_price`)}
                    </p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor={`item-tax-${index}`}>Tax % <span className="text-muted-foreground">(optional)</span></Label>
                  <Input
                    id={`item-tax-${index}`}
                    type="number"
                    min="0"
                    max="100"
                    step="0.0001"
                    value={item.tax_rate}
                    onChange={(event) => updateItem(index, { tax_rate: event.target.value })}
                    aria-invalid={Boolean(messageFor(`items.${index}.tax_rate`))}
                    placeholder="0"
                  />
                  {messageFor(`items.${index}.tax_rate`) && (
                    <p className="text-sm text-destructive">
                      {messageFor(`items.${index}.tax_rate`)}
                    </p>
                  )}
                </div>
                <div className="flex items-end">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove item ${index + 1}`}
                    disabled={invoice.items.length === 1}
                    onClick={() =>
                      updateField(
                        'items',
                        invoice.items.filter((_, itemIndex) => itemIndex !== index),
                      )
                    }
                  >
                    <Trash2 className="text-muted-foreground" />
                  </Button>
                </div>
                {messageFor(`items.${index}`) && (
                  <p className="text-sm text-destructive md:col-span-5">
                    {messageFor(`items.${index}`)}
                  </p>
                )}
              </div>
            ))}
            {messageFor('items') && (
              <p className="text-sm text-destructive">{messageFor('items')}</p>
            )}
            <div className="flex items-center justify-between border-t pt-4">
              <span className="text-sm text-muted-foreground">
                Estimated total · final total calculated by the server
              </span>
              <span className="text-lg font-semibold tabular-nums">
                {formatCurrency(estimatedTotal, invoice.currency)}
              </span>
            </div>
          </CardContent>
        </Card>

        <div className="flex flex-col-reverse justify-end gap-3 sm:flex-row">
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? <LoaderCircle className="animate-spin" /> : <FilePlus2 />}
            {submitting ? 'Creating invoice…' : 'Create invoice'}
          </Button>
        </div>
      </form>
    </div>
  )
}

function InvoiceDashboard({
  token,
  userName,
  onLogout,
  onCreate,
}: {
  token: string
  userName: string
  onLogout: () => void
  onCreate: () => void
}) {
  const [draftFilters, setDraftFilters] = useState(emptyFilters)
  const [filters, setFilters] = useState(emptyFilters)
  const [page, setPage] = useState<InvoicePage | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    api
      .listInvoices(token, filters)
      .then((result) => {
        if (active) setPage(result)
      })
      .catch((loadError: unknown) => {
        if (!active) return
        setError(errorText(loadError))
        if (loadError instanceof ApiError && loadError.status === 401) {
          onLogout()
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [filters, onLogout, token])

  const invoices = page?.data ?? []
  const pageTotal = invoices.reduce((sum, invoice) => sum + Number(invoice.total_amount), 0)
  const submittedCount = invoices.filter((invoice) => invoice.status === 'submitted').length
  const inProgressCount = invoices.filter(
    (invoice) => invoice.status === 'pending' || invoice.status === 'processing',
  ).length

  function applyFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoading(true)
    setError('')
    setFilters({ ...draftFilters, page: 1 })
  }

  function changePage(nextPage: number) {
    setLoading(true)
    setError('')
    setFilters((current) => ({ ...current, page: nextPage }))
  }

  return (
    <div className="min-h-screen bg-slate-50/70">
      <header className="sticky top-0 z-10 border-b bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-3">
            <div className="grid size-9 place-items-center rounded-lg bg-slate-950 text-white">
              <FileText className="size-4" />
            </div>
            <span className="text-lg font-semibold tracking-tight">tubo</span>
            <span className="hidden border-l pl-3 text-sm text-muted-foreground sm:inline">
              Invoices
            </span>
          </div>
          <div className="flex items-center gap-3">
            <span className="hidden text-sm text-muted-foreground sm:inline">{userName}</span>
            <Button variant="ghost" size="sm" onClick={onLogout} aria-label="Sign out">
              <LogOut />
              <span className="hidden sm:inline">Sign out</span>
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-8 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
          <div>
            <p className="text-sm font-medium text-primary">Invoice workspace</p>
            <h1 className="mt-1 text-3xl font-semibold tracking-tight">Good to see you, {userName}</h1>
            <p className="mt-2 text-muted-foreground">
              Here’s what’s happening with your invoices.
            </p>
          </div>
          <Button onClick={onCreate} className="shrink-0">
            <Plus />
            Create invoice
          </Button>
        </div>

        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard
            label="All invoices"
            value={page?.meta.total ?? '—'}
            caption="Matching your current filters"
            icon={<FileText />}
          />
          <SummaryCard
            label="Invoice value"
            value={formatCurrency(pageTotal)}
            caption="Visible on this page"
            icon={<WalletCards />}
          />
          <SummaryCard
            label="In progress"
            value={loading ? '—' : inProgressCount}
            caption="Pending or processing on this page"
            icon={<LoaderCircle />}
          />
          <SummaryCard
            label="Successfully submitted"
            value={loading ? '—' : submittedCount}
            caption="On this page"
            icon={<BadgeCheck />}
          />
        </div>

        <Card className="overflow-visible">
          <CardHeader className="gap-4 border-b pb-5">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
              <div>
                <CardTitle className="text-lg">All invoices</CardTitle>
                <CardDescription className="mt-1">
                  View status, customer, date, and invoice value.
                </CardDescription>
              </div>
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Filter className="size-4" />
                Filter invoices
              </div>
            </div>
            <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_2fr_auto]">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label="Invoice number"
                  placeholder="Exact invoice number"
                  value={draftFilters.invoice_number}
                  onChange={(event) =>
                    setDraftFilters((current) => ({
                      ...current,
                      invoice_number: event.target.value,
                    }))
                  }
                  className="pl-9"
                />
              </div>
              <Select
                value={draftFilters.status || 'all'}
                onValueChange={(value) =>
                  setDraftFilters((current) => ({
                    ...current,
                    status: value === 'all' || value === null ? '' : value,
                  }))
                }
              >
                <SelectTrigger aria-label="Filter by status">
                  <SelectValue>
                    {draftFilters.status
                      ? draftFilters.status.charAt(0).toUpperCase() +
                        draftFilters.status.slice(1)
                      : 'Any status'}
                  </SelectValue>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Any status</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="processing">Processing</SelectItem>
                  <SelectItem value="submitted">Submitted</SelectItem>
                  <SelectItem value="failed">Failed</SelectItem>
                </SelectContent>
              </Select>
              <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                  <Label htmlFor="filter-date-from" className="text-xs text-muted-foreground">
                    From
                  </Label>
                  <Input
                    id="filter-date-from"
                    aria-label="From date"
                    type="date"
                    value={draftFilters.invoice_date_from}
                    onChange={(event) =>
                      setDraftFilters((current) => ({
                        ...current,
                        invoice_date_from: event.target.value,
                      }))
                    }
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="filter-date-to" className="text-xs text-muted-foreground">
                    To
                  </Label>
                  <Input
                    id="filter-date-to"
                    aria-label="To date"
                    type="date"
                    value={draftFilters.invoice_date_to}
                    onChange={(event) =>
                      setDraftFilters((current) => ({
                        ...current,
                        invoice_date_to: event.target.value,
                      }))
                    }
                  />
                </div>
              </div>
              <Button type="submit" variant="outline" disabled={loading}>
                Apply filters
              </Button>
            </form>
          </CardHeader>

          {error && (
            <div className="px-6 pt-5">
              <Alert variant="destructive">
                <CircleAlert />
                <AlertTitle>Could not load invoices</AlertTitle>
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            </div>
          )}

          <CardContent className="px-0 pt-0">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30 hover:bg-muted/30">
                  <TableHead className="pl-6">Invoice</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="pr-6 text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={5} className="h-40 text-center">
                      <span className="inline-flex items-center gap-2 text-muted-foreground">
                        <LoaderCircle className="size-4 animate-spin" />
                        Loading invoices…
                      </span>
                    </TableCell>
                  </TableRow>
                ) : invoices.length > 0 ? (
                  invoices.map((invoice) => (
                    <TableRow key={invoice.id}>
                      <TableCell className="pl-6 font-medium">{invoice.invoice_number}</TableCell>
                      <TableCell>
                        <div className="font-medium">{invoice.customer.name}</div>
                        {invoice.customer.email && (
                          <div className="mt-0.5 text-xs text-muted-foreground">
                            {invoice.customer.email}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {formatDate(invoice.invoice_date)}
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={invoice.status} />
                      </TableCell>
                      <TableCell className="pr-6 text-right font-medium tabular-nums">
                        {formatCurrency(invoice.total_amount, invoice.currency)}
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={5} className="h-52">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="mb-3 grid size-11 place-items-center rounded-xl bg-muted">
                          <FileText className="size-5 text-muted-foreground" />
                        </div>
                        <p className="font-medium">No invoices found</p>
                        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                          Try adjusting your filters, or create an invoice to get started.
                        </p>
                        <Button variant="outline" size="sm" className="mt-4" onClick={onCreate}>
                          <Plus />
                          Create invoice
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <div className="flex flex-col justify-between gap-3 border-t px-6 py-4 sm:flex-row sm:items-center">
              <p className="text-sm text-muted-foreground">
                {page ? (
                  <>
                    Showing {page.meta.total === 0 ? 0 : (page.meta.current_page - 1) * page.meta.per_page + 1}
                    {'–'}
                    {Math.min(page.meta.current_page * page.meta.per_page, page.meta.total)} of{' '}
                    {page.meta.total} invoices
                  </>
                ) : (
                  ' '
                )}
              </p>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={loading || !page || page.meta.current_page <= 1}
                  onClick={() => page && changePage(page.meta.current_page - 1)}
                >
                  <ArrowLeft />
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={loading || !page || page.meta.current_page >= page.meta.last_page}
                  onClick={() => page && changePage(page.meta.current_page + 1)}
                >
                  Next
                  <ArrowRight />
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
        <p className="mt-5 text-center text-xs text-muted-foreground">
          Invoice totals are calculated by Tubo and protected from client-side changes.
        </p>
      </main>
    </div>
  )
}

function SummaryCard({
  label,
  value,
  caption,
  icon,
}: {
  label: string
  value: string | number
  caption: string
  icon: ReactNode
}) {
  return (
    <Card>
      <CardContent className="flex items-start justify-between p-5">
        <div>
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
          <p className="mt-1 text-xs text-muted-foreground">{caption}</p>
        </div>
        <div className="grid size-9 place-items-center rounded-lg bg-primary/10 text-primary">
          {icon}
        </div>
      </CardContent>
    </Card>
  )
}

function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem('tubo_access_token') ?? '')
  const [userName, setUserName] = useState('')
  const [checkingSession, setCheckingSession] = useState(Boolean(token))
  const [sessionError, setSessionError] = useState('')
  const [showCreateForm, setShowCreateForm] = useState(false)

  const clearSession = useCallback(() => {
    sessionStorage.removeItem('tubo_access_token')
    setToken('')
    setUserName('')
    setShowCreateForm(false)
  }, [])

  useEffect(() => {
    if (!token) return

    let active = true
    api
      .me(token)
      .then(({ user }) => {
        if (active) {
          setUserName(user.name)
          setSessionError('')
        }
      })
      .catch((error: unknown) => {
        if (!active) return
        if (error instanceof ApiError && error.status === 401) {
          clearSession()
        } else {
          setSessionError(errorText(error))
        }
      })
      .finally(() => {
        if (active) setCheckingSession(false)
      })

    return () => {
      active = false
    }
  }, [clearSession, token])

  async function login(email: string, password: string) {
    const result = await api.login(email, password)
    const { user } = await api.me(result.access_token)
    sessionStorage.setItem('tubo_access_token', result.access_token)
    setUserName(user.name)
    setSessionError('')
    setToken(result.access_token)
  }

  const logout = useCallback(async () => {
    try {
      await api.logout(token)
      clearSession()
    } catch (error) {
      clearSession()
      setSessionError(
        `Signed out on this device, but server sign-out could not be confirmed: ${errorText(error)}`,
      )
    }
  }, [clearSession, token])

  if (checkingSession) {
    return (
      <main className="grid min-h-screen place-items-center bg-background">
        <div className="flex items-center gap-3 text-sm text-muted-foreground">
          <LoaderCircle className="size-5 animate-spin text-primary" />
          Checking your session…
        </div>
      </main>
    )
  }

  if (!token || !userName) {
    return (
      <div>
        {sessionError && (
          <div className="fixed inset-x-4 top-4 z-20 mx-auto max-w-md">
            <Alert variant="destructive">
              <CircleAlert />
              <AlertTitle>Session could not be verified</AlertTitle>
              <AlertDescription>{sessionError}</AlertDescription>
            </Alert>
          </div>
        )}
        <LoginScreen onLogin={login} />
      </div>
    )
  }

  if (showCreateForm) {
    return (
      <main className="min-h-screen bg-slate-50/70 px-4 py-8 sm:px-6 lg:px-8">
        <InvoiceForm
          token={token}
          onCancel={() => setShowCreateForm(false)}
          onCreated={() => setShowCreateForm(false)}
        />
      </main>
    )
  }

  return (
    <InvoiceDashboard
      token={token}
      userName={userName}
      onLogout={logout}
      onCreate={() => setShowCreateForm(true)}
    />
  )
}

export default App
