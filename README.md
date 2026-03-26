# ACube File Conversion Job API

Async file conversion API built with **PHP 8.2 + Symfony 7.4 + Symfony Messenger + Doctrine ORM + PostgreSQL**.

A client uploads a file (CSV, JSON, XLSX, ODS), specifies the desired output format (JSON or XML), and receives a job ID immediately (`202 Accepted`). The actual conversion runs asynchronously in a background worker. The client polls the status endpoint until the job is `completed`, then downloads the result.

---

## Requirements

| Tool | Minimum version |
|------|-----------------|
| Docker + Docker Compose | any recent version |
| PHP + Composer | 8.2 / 2.x — only needed to run the test suite locally |

---

## Project layout

```
src/        Application source 
tests/      PHPUnit test suite (unit + E2E)
migrations/ Doctrine migration that creates the jobs table
docker/php/ Dockerfile and entrypoint script
var/        Runtime files (cache, uploaded files)
```

---

## Quick start

```bash
docker compose up -d --build
```

This command starts **three separate containers**:

| Container | Role |
|-----------|------|
| `database` | PostgreSQL 16 |
| `web` | HTTP server (`php -S`) — port 8000 |
| `worker` | `messenger:consume` — processes jobs from the queue |

`web` and `worker` wait for `database` to pass the health check before starting up. Both run the Doctrine migrations on startup.

The API is available at `http://localhost:8000` as soon as all containers are running.

To stream logs:

```bash
docker compose logs -f          # all containers
docker compose logs -f worker   # worker only
docker compose logs -f web      # web server only
```

---

## Running the tests

The test suite uses **SQLite** (no Docker needed) and a `sync://` Messenger transport (the worker runs inline — no separate process required).

```bash
composer install
php bin/phpunit
```

Expected output:

```
PHPUnit 12.x

..................                                18 / 18 (100%)

Tests: 18, Assertions: 59, OK
```

---

## API endpoints

### `POST /api/jobs` — Submit a conversion job

Upload a file and specify the desired output format. Returns `202 Accepted` with the job ID.

**Form fields**

| Field | Type | Required | Values |
|-------|------|----------|--------|
| `file` | file | yes | CSV, JSON, XLSX, ODS |
| `output_format` | string | yes | `json`, `xml` |

**Example — convert a JSON file to XML**

```bash
curl -s -X POST http://127.0.0.1:8000/api/jobs \
  -F "file=@/path/to/data.json" \
  -F "output_format=xml" | jq
```

```json
{
  "job_id": "019600ab-1234-7abc-89de-0123456789ab",
  "status": "pending"
}
```

**Example — convert a CSV file to JSON**

```bash
curl -s -X POST http://127.0.0.1:8000/api/jobs \
  -F "file=@/path/to/records.csv" \
  -F "output_format=json" | jq
```

**Error responses** (`application/problem+json`)

| Scenario | Status |
|----------|--------|
| Missing file | `422 Unprocessable Entity` |
| Missing or invalid `output_format` | `422 Unprocessable Entity` |
| Unsupported MIME type | `422 Unprocessable Entity` |

---

### `GET /api/jobs/{id}` — Poll job status

**Example**

```bash
curl -s http://127.0.0.1:8000/api/jobs/019600ab-1234-7abc-89de-0123456789ab | jq
```

```json
{
  "job_id": "019600ab-1234-7abc-89de-0123456789ab",
  "status": "completed",
  "created_at": "2026-03-25T12:00:00+00:00",
  "updated_at": "2026-03-25T12:00:10+00:00"
}
```

**Possible `status` values**

| Value | Meaning |
|-------|---------|
| `pending` | Job queued, not yet picked up by the worker |
| `processing` | Worker is actively converting the file |
| `completed` | Conversion finished — result is available |
| `failed` | Conversion failed (see `error_message` in the DB) |

**Error responses**

| Scenario | Status |
|----------|--------|
| `{id}` is not a valid UUID | `400 Bad Request` |
| Job not found | `404 Not Found` |

---

### `GET /api/jobs/{id}/result` — Download the converted file

Available only when `status` is `completed`. Returns the converted file as a binary download.

**Example**

```bash
curl -OJ http://127.0.0.1:8000/api/jobs/019600ab-1234-7abc-89de-0123456789ab/result
```

The file is saved locally as `job-019600ab-1234-7abc-89de-0123456789ab.xml` (or `.json`).

**Error responses**

| Scenario | Status |
|----------|--------|
| Job not found | `404 Not Found` |
| Job exists but is not yet `completed` | `409 Conflict` |

---

## Full end-to-end example

```bash
# 1. Submit the job
JOB_ID=$(curl -s -X POST http://127.0.0.1:8000/api/jobs \
  -F "file=@/path/to/data.csv" \
  -F "output_format=json" | jq -r '.job_id')

echo "Job created: $JOB_ID"

# 2. Poll until completed (the worker's sleep simulates a long-running task)
while true; do
  STATUS=$(curl -s http://127.0.0.1:8000/api/jobs/$JOB_ID | jq -r '.status')
  echo "Status: $STATUS"
  [ "$STATUS" = "completed" ] && break
  [ "$STATUS" = "failed" ] && echo "Job failed" && break
  sleep 2
done

# 3. Download the result
curl -OJ http://127.0.0.1:8000/api/jobs/$JOB_ID/result
```

---

## Architecture notes

- **Async processing** — jobs are dispatched to a Symfony Messenger `async` transport backed by a Doctrine/PostgreSQL queue. The worker transitions the job through `pending → processing → completed/failed`.
- **Strategy pattern** — `ConverterFactory` resolves the right `ConverterInterface` implementation via a tagged service iterator. Adding a new converter requires only creating a new class — zero configuration changes.
- **MIME validation** — uploaded files are validated by their actual MIME type (via PHP `finfo`), not just by file extension.
