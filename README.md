# PROJECT: AUDIT-GRADE ACTIVITY LOG API

API-only backend built with Laravel 11 for audit-grade,
tamper-evident activity logging.

This repository is intentionally backend-focused.
No UI, no sessions, no cookies.

------------------------------------------------------------

## PROJECT GOALS

This project demonstrates an audit logging system.

Primary goals:

- append-only data model
- tamper-evident storage
- idempotent write operations
- deterministic serialization
- testable integrity guarantees

------------------------------------------------------------

## CORE CONCEPTS

### Append-only logs

Audit logs are never updated or deleted by application logic.
Any modification must be detectable.

### Tamper-evident design

Each log row stores a cryptographic checksum.
Recomputing the checksum allows detection of data tampering.

### Idempotent writes

Requests using the same X-Request-Id header
will not create duplicate audit log entries.

### Deterministic serialization

Payloads are canonicalized before hashing
to ensure stable and reproducible checksums.

------------------------------------------------------------

## TECH STACK

- PHP 8.4
- Laravel 11 (API-only usage)
- PostgreSQL (production database)
- SQLite (testing database)
- PHPUnit (feature testing)
- Docker (optional, for PostgreSQL)

------------------------------------------------------------

## LARAVEL 11 NOTES

- This project uses Laravel 11 minimal structure
- No Blade templates or web middleware are used
- routes/api.php is the primary entry point
- All endpoints are stateless

------------------------------------------------------------

## DIRECTORY STRUCTURE

```plaintex
.
app/
├── Http
│   └── Controllers
│       └── Api
│           └── AuditLogController.php   // store, index, verify endpoints
└── Models
    └── AuditLog.php                     // append-only audit log model

database/
├── migrations                            // audit_logs schema and indexes
└── factories                             // test data factories

tests/
└── Feature                               // API-level tests

routes/
└── api.php                               // API routes
.
```

------------------------------------------------------------

## INSTALLATION

### Clone repository

```sh
git clone <repo-url>
cd <repo-name>
```

### Install dependencies

```sh
composer install
```

### Environment setup

```sh
cp .env.example .env
php artisan key:generate
```

------------------------------------------------------------

## DATABASE SETUP

### PostgreSQL

```sh
# run docker
docker compose up -d

# Example .env configuration:
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=audit_logs
DB_USERNAME=postgres
DB_PASSWORD=postgres

# Run migrations:
php artisan migrate
```

## SQLite (testing)

```sh
DB_CONNECTION=sqlite
DB_DATABASE=:memory:

CACHE_STORE=array
...
```

------------------------------------------------------------

## RUNNING THE APPLICATION

```sh
php artisan serve
```

Base API URL:
http:localhost:8000/api

------------------------------------------------------------

## API ENDPOINTS

### Create audit log (idempotent)

```sh
POST /api/audit-logs

Headers:
X-Request-Id: <uuid>
Content-Type: application/json

Body:
{
  "action": "user.login",
  "resource_type": "user",
  "resource_id": "123",
  "payload": {
    "ip": "127.0.0.1"
  }
}
```

------------------------------------------------------------

### List audit logs

```sh
GET /api/logs

Query parameters:

- from / to (date range)
- actor_id
- action
- limit
- cursor

# Example:
GET /api/logs?action=user.login&limit=25
```

------------------------------------------------------------

### Verify audit log integrity

```sh
GET /api/audit-logs/{id}/verify

Response:
{
  "data": {
    "id": "...",
    "valid": true
  }
}
```

If the record was tampered with,
the valid flag will be false.

------------------------------------------------------------

## TESTING

Tests use SQLite and reset the database
on every test run.

```sh
# Run all tests:
php artisan test

# Run test for feature only:
php artisan test --testsuite=Feature

# Run a specific test:
php artisan test --filter=AuditLogVerifyApiTest
```

------------------------------------------------------------

## CODE COVERAGE

Requires Xdebug or PCOV.

```sh
# With Xdebug enabled:
php artisan test --coverage

# HTML report:
php artisan test --coverage-html coverage
```

------------------------------------------------------------

## DESIGN NOTES

- Stateless API
- No session storage
- No UI rendering
- Database constraints are part of the integrity model
- Tampering is detectable, not prevented

------------------------------------------------------------

## INTENDED AUDIENCE

- Backend engineers
- System designers
- Developers learning audit/compliance concepts
- Anyone wanting to understand logging beyond CRUD

------------------------------------------------------------

## LICENSE

MIT (adjust as needed)
