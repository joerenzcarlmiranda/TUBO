# Tubo

Tubo is an electronic invoicing application built with a Laravel API, a React
and TypeScript frontend, and MySQL.

## Requirements

- PHP 8.2 or newer and Composer
- Node.js and npm
- MySQL 8.0 or newer

## Local setup

### Backend

From the repository root:

```powershell
cd backend
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
```

Create the MySQL database before starting the API:

```sql
CREATE DATABASE tubo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Update the `DB_*` values in `backend/.env` to match your local MySQL
credentials. Start the Laravel development server:

```powershell
php artisan serve
```

The API health check is available at `http://127.0.0.1:8000/api/health` and
returns `{"status":"ok"}`. Laravel's framework health endpoint is available at
`http://127.0.0.1:8000/up`.

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
