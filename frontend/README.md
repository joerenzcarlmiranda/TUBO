# Tubo frontend

The frontend is a React and TypeScript application built with Vite, Tailwind
CSS, and shadcn/ui components.

## Development

From `frontend/`:

```powershell
npm install
Copy-Item .env.example .env
npm run dev
```

Set `VITE_API_BASE_URL` in `.env` to the Laravel API URL. The default is
`http://127.0.0.1:8000/api`.

## Validation

```powershell
npm test
npm run lint
npm run build
```

The test suite uses Vitest, Testing Library, and jsdom to verify API request
construction, validation-error handling, sign-in, invoice listing, and invoice
creation. Tests mock API responses and do not require a running backend.
