# Tubo

## API authentication

The API uses short-lived JWT bearer tokens. Install backend dependencies, then
generate a private signing secret for the local environment:

```powershell
cd backend
composer install
php artisan jwt:secret
```

Keep `JWT_SECRET` in the untracked `backend/.env`; never commit the generated
secret. `JWT_TTL` controls token lifetime in minutes.

- `POST /api/auth/login` — exchange `email` and `password` for a bearer token.
- `GET /api/auth/me` — return the authenticated user's basic profile.
- `POST /api/auth/refresh` — refresh a valid bearer token.
- `POST /api/auth/logout` — invalidate the current bearer token.

Send protected requests with `Authorization: Bearer <access_token>`. Login is
limited to five attempts per minute. Accounts must be assigned to a company;
account provisioning is intentionally not exposed as a public registration API.

Invoice view and update authorization is enforced by `InvoicePolicy`: users can
only access invoices whose `company_id` matches their own. Invoice endpoints
must use this policy when added.

Tubo is an electronic invoicing application built with a Laravel API, a React
and TypeScript frontend, and SQLite for local development. The schema is also
designed to run on MySQL.

## Requirements

- PHP 8.2 or newer and Composer
- Node.js and npm
- SQLite (included with PHP) for local development
- MySQL 8.0 or newer when switching the backend environment

## Local setup

### Backend

From the repository root:

```powershell
cd backend
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
```

Create the SQLite database file and run the migrations:

```powershell
if (-not (Test-Path database\database.sqlite)) {
    New-Item -ItemType File -Path database\database.sqlite | Out-Null
}
php artisan migrate
```

The SQLite connection is configured in `backend/.env.example`. When MySQL is
ready, change `DB_CONNECTION` to `mysql`, set the `DB_*` connection values, and
create the database before running `php artisan migrate`.

Start the API:

```powershell
php artisan serve
```

The API health check is available at `http://127.0.0.1:8000/api/health` and
returns `{"status":"ok"}`. Laravel's framework health endpoint is available at
`http://127.0.0.1:8000/up`.

### Database design

- A company can have multiple users and invoices. A user's company association
  is nullable to support existing accounts during setup; application access
  should be limited to users with an assigned company.
- Invoices retain seller and customer details as snapshots, so company profile
  changes do not rewrite previously issued invoices.
- Invoice numbers are unique within each company, enforced by a composite
  database constraint. The same number can be used by a different company.
- Invoice items and processing attempts belong to an invoice. Attempt records
  retain their status, start/end times, HTTP response details, and error
  message; attempt numbers are unique within their invoice.
- Monetary amounts use fixed-precision decimal columns. Invoice statuses are
  represented by backed enums in the application and stored as strings.

### Frontend

In a second terminal from the repository root:

```powershell
cd frontend
npm install
npm run dev
```

Vite prints the local frontend URL when the development server starts.

## Project structure

- `backend/` — Laravel application and REST API
- `frontend/` — React and TypeScript application built with Vite

Keep local environment files and credentials out of Git. Use `.env.example` as
the tracked reference for required backend settings.
