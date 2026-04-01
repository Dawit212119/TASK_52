# VetOps Frontend

Vue 3 + TypeScript SPA for the VetOps Unified Operations Portal.
Connects to the Laravel backend via `/api/v1` (proxied by Vite in dev mode
and by Nginx in production).

## Prerequisites

Node.js 20+

## Environment variables

| Variable              | Default        | Description                              |
|-----------------------|----------------|------------------------------------------|
| VITE_API_BASE_PATH    | /api/v1        | Backend API base path                    |
| VITE_WORKSTATION_ID   | ws-unknown     | Workstation identifier sent in headers   |
| VITE_BACKEND_ORIGIN   | http://localhost:8000 | Backend origin for Vite dev proxy |

Copy `.env.example` to `.env` and adjust values for your LAN setup.

## Local development (standalone, no Docker)

```bash
cd frontend
npm install
npm run dev
# App available at http://localhost:5173
# API calls are proxied to VITE_BACKEND_ORIGIN
```

## Run tests

```bash
npm run test
```

## Type-check

```bash
npm run lint
```

## Production build

```bash
npm run build
# Output in dist/ — served by Nginx in the Docker stack
```

## Full-stack startup

See ../README.md for Docker Compose instructions.
