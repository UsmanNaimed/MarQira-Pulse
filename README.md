# MarQira Pulse

MarQira Pulse is a commercial WordPress management and monitoring SaaS. It pairs the
MarQira Connector WordPress plugin with a Laravel + PostgreSQL + Redis control plane
that enrolls sites, ingests heartbeats, and tracks origin/network information securely.

## Repository structure

```
.
├── apps/
│   ├── api/              # Laravel 11 API (PHP 8.3) — the control plane
│   └── dashboard/        # React dashboard (Phase 5, placeholder)
├── wordpress/
│   └── marqira-connector/  # MarQira Connector plugin (PHP 7.4 compatible)
├── packages/shared/      # Shared packages (placeholder)
├── infrastructure/
│   ├── docker/           # Dockerfiles, nginx, docker-compose for local dev
│   └── coolify/          # Coolify deployment config (placeholder)
├── scripts/              # Operational scripts (placeholder)
└── docs/                 # Operational documentation
```

## Quick start (API)

Requirements: PHP 8.3, Composer 2, PostgreSQL 16+, Redis 7.x. For containerized local
development, `infrastructure/docker/docker-compose.dev.yml` provisions all services.

```bash
# 1. Install dependencies
cd apps/api
composer install

# 2. Configure the environment
cp .env.example .env
php artisan key:generate
# Generate the site-secret encryption key and set MARQIRA_SECRET_KEY in .env:
php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"

# 3. Run database migrations
php artisan migrate

# 4. Create the first administrator
php artisan marqira:create-admin

# 5. Verify the API is up
curl http://localhost/api/health
```

### Local development with Docker

```bash
cd infrastructure/docker
cp ../../apps/api/.env.example ../../apps/api/.env   # then edit values
docker compose -f docker-compose.dev.yml up --build
# API available via nginx on http://localhost:8080
```

### Running the test suite

```bash
cd apps/api
php vendor/bin/pest   # uses in-memory SQLite — no live database required
```

## Documentation

See [`docs/`](docs/README.md) for operational guides: security, Coolify deployment,
the WordPress plugin, backups, and troubleshooting.

## Security

- External identifiers are UUID v7 — sequential database IDs are never exposed.
- Every tenant-owned table carries `organization_id`; `TenantContext` fails closed.
- Site secrets are encrypted at rest with AES-256-GCM.
- `audit_logs` is append-only (updates and deletes are rejected at the model layer).
- `.env` and all secret material are gitignored and never committed.
