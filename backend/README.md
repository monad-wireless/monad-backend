# Monad Backend

Gamified BLE sensor data collection platform for indoor positioning and channel state modeling research. Users complete quests on a mobile app (Kotlin Multiplatform) while the app continuously collects RSSI/BLE advertisement data from MONAD-prefixed beacons. Data is uploaded to S3 for analysis.

## Tech Stack

- **Backend:** Symfony 5+ (PHP 8.3)
- **Database:** PostgreSQL 16
- **Auth:** JWT (lexik/jwt-authentication-bundle)
- **Storage:** Hetzner Object Storage, S3-compatible via `async-aws/s3` (presigned URLs + direct stream upload)
- **Infra:** project host (Hetzner CCX33), Docker Compose behind the host nginx; image from GHCR
- **Containerization:** Docker + Docker Compose

## Local Development Setup

### Prerequisites

- Docker & Docker Compose

### 1. Configure environment

```bash
cp .env.example .env
# Edit .env with your values
```

Required variables:

| Variable | Description |
|----------|-------------|
| `POSTGRES_DB` | Database name (e.g. `monad_db`) |
| `POSTGRES_USER` | Database user |
| `POSTGRES_PASSWORD` | Database password |
| `POSTGRES_PORT` | Exposed port (default `5432`) |
| `SYMFONY_PORT` | API port (default `8000`) |
| `APP_ENV` | `dev` or `prod` |
| `APP_SECRET` | Symfony app secret |
| `JWT_PASSPHRASE` | JWT key passphrase |
| `CORS_ALLOW_ORIGIN` | CORS regex pattern |
| `HETZNER_S3_BUCKET` | S3 bucket name |
| `HETZNER_S3_ACCESS_KEY` | Hetzner Object Storage access key |
| `HETZNER_S3_SECRET_KEY` | Hetzner Object Storage secret key |
| `HETZNER_S3_REGION` | Storage region (e.g. `fsn1`) |
| `HETZNER_S3_ENDPOINT` | **Mandatory** — no default. An empty endpoint used to mean "talk to Amazon". |
| `HETZNER_S3_USE_PATH_STYLE` | `1` — Hetzner has no wildcard certificate, so path-style addressing is required |

### 2. Start services

```bash
docker compose up -d
```

This starts:
- **monad_postgres** - PostgreSQL 16 on port `$POSTGRES_PORT`
- **monad_symfony** - Symfony API on port `$SYMFONY_PORT` (auto-generates JWT keys, runs `composer install`)

### 3. Run database migrations

```bash
docker exec monad_symfony php bin/console doctrine:migrations:migrate
```

### 4. Seed the database

```bash
docker exec monad_symfony php bin/console doctrine:fixtures:load
```

This creates:
- Admin user: `admin@monad.sk` / `password123` (ROLE_ADMIN)
- Superadmin: created by UserFixtures (ROLE_SUPERADMIN)
- 5 regular users: `user1@monad.sk` - `user5@monad.sk` / `password123`
- 10 QR codes with Bratislava GPS coordinates
- 5 news items
- 2 sample quests (FIIT Treasure Hunt variants)

### 5. Generate OpenAPI spec

```bash
docker exec monad_symfony php bin/console api:openapi:export --output=/var/www/html/openapi.json
```

## Infrastructure

Deployed to `api.monad.dubec.dev` — the project host (Hetzner CCX33), behind the host nginx that
already terminates TLS for `monad.dubec.dev`. The container binds loopback only; nginx proxies to
it. See `docker-compose.deploy.yml` and `deploy/README.md`.

The image is built by CI and published to GHCR. There is no cloud provisioning step and no
infrastructure-as-code for this service — it is one container on a host that already exists.

```bash
# on the host, next to docker-compose.deploy.yml and its .env
docker compose -f docker-compose.deploy.yml pull
docker compose -f docker-compose.deploy.yml up -d
```

## API Reference

Base URL: `http://localhost:8000`

### Authentication

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/register` | No | Register new user |
| POST | `/api/auth/login` | No | Login, returns JWT |
| GET | `/api/auth/me` | JWT | Get current user info |

### Quests

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/quests` | No | List quests (filter: `?status=active\|expired`) |
| GET | `/api/quest/{id}` | No | Quest detail with steps |
| POST | `/api/quest/{id}/start` | JWT | Start quest, creates enrollment |
| POST | `/api/quest/{quest_id}/complete` | JWT | Submit all step data after completion |

### Storage

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/storage/upload-url` | JWT | Get presigned S3 upload URL |
| POST | `/api/storage/upload` | JWT | Direct stream upload to S3 |
| POST | `/api/storage/experiment-upload` | JWT | Stream experiment data to S3 |
| GET | `/api/storage/config` | JWT | Get upload limits and allowed types |
| GET | `/api/storage/test` | JWT | Test S3 connection |

### Admin

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/admin/quests` | JWT (ROLE_SUPERADMIN) | Create a new quest |

### Other

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/health` | No | Health check (DB connectivity) |
| GET | `/terms` | No | Terms of service HTML |
| GET | `/privacy-policy` | No | Privacy policy HTML |

## Admin: Creating Quests

Requires a user with `ROLE_SUPERADMIN`. Authenticate first, then:

```bash
curl -X POST http://localhost:8000/api/admin/quests \
  -H "Authorization: Bearer <JWT_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Quest",
    "description": "Quest description",
    "available_from": "2026-01-01T00:00:00Z",
    "available_to": "2026-12-31T23:59:59Z",
    "points": 100.0,
    "estimated_duration": 30,
    "steps": [
      {
        "name": "Start",
        "type": "start",
        "order": 0,
        "config": {
          "description": "Welcome! Tap Start to begin."
        }
      },
      {
        "name": "Find Beacon",
        "type": "find_ble_device",
        "order": 1,
        "config": {
          "device_name": "MONAD1",
          "description": "Find the MONAD1 beacon near the entrance."
        }
      },
      {
        "name": "Wait for data collection",
        "type": "wait",
        "order": 2,
        "config": {
          "timeout_seconds": 30,
          "description": "Stay still for 30 seconds while we collect data."
        }
      },
      {
        "name": "Scan QR",
        "type": "scan_qr",
        "order": 3,
        "config": {
          "expected_value": "QRM-1",
          "location": "Room 101, on the wall"
        }
      },
      {
        "name": "Done!",
        "type": "finish",
        "order": 4,
        "config": {
          "description": "Congratulations! Quest complete."
        }
      }
    ]
  }'
```

### Step types

| Type | Description | Key config fields |
|------|-------------|-------------------|
| `start` | Welcome/intro step | `description` |
| `find_ble_device` | Detect a BLE beacon | `device_name`, `description` |
| `wait` | Timed data collection | `timeout_seconds`, `description` |
| `scan_qr` | Scan a QR code | `expected_value`, `location` |
| `connect_to_ap` | Connect to WiFi AP | (Android only) |
| `walk_to` | Navigate to location | |
| `finish` | Completion step | `description` |

## User Roles

| Role | Description |
|------|-------------|
| `ROLE_USER` | Default role, can browse/start/complete quests |
| `ROLE_SUPERADMIN` | Can create quests via admin endpoints |

## Data Flow

```
Mobile App                        Backend                Hetzner Object Storage
    |                                |                              |
    |-- POST /quest/{id}/start ----->|                              |
    |<-- enrollment_id, steps, path--|                              |
    |                                |                              |
    | [BLE scanning in background]   |                              |
    | [User completes steps]         |                              |
    |                                |                              |
    |-- POST /storage/session-upload ------------------------------>|
    |   (streams first, metadata.json LAST — its presence          |
    |    marks the session complete)                               |
    |<-- objectKey, url -------------|                              |
    |                                |                              |
    |-- POST /quest/{id}/complete -->|                              |
    |   (all step completions)       |                              |
    |<-- points_earned --------------|                              |
```

### S3 path structure

```
datasets/monad-app-sessions/{participantId}/{sessionId}/{filename}
```

Mirrors the `csid` fleet convention, so a phone session and a radio capture are siblings in one
bucket and joinable by session rather than by upload date.

### Experiment data format (TSV)

```
timestamp       device_address      device_name  rssi  manufacturer_id  manufacturer_data  service_uuids
1701234567890   AA:BB:CC:DD:EE:FF   MONAD1       -65   004C             0102030405         uuid1,uuid2
```

## Error Codes

See `backend/db_model/api-error-codes-reference.md` for the full catalog. Pattern:

- `AUTH_0XX` - Authentication errors
- `VALIDATION_1XX` - Input validation errors
- `RESOURCE_2XX` - Resource errors (not found, conflict, forbidden)
- `STORAGE_3XX` - S3/upload errors
- `SYSTEM_9XX` - Internal server errors

## Docker Configuration

PHP settings (configured in Dockerfile):
- `upload_max_filesize`: 50MB
- `post_max_size`: 50MB
- `memory_limit`: 512MB
- `max_execution_time`: 300s

S3 allowed content types: `application/octet-stream`, `application/json`, `text/csv`, `text/plain`, `text/tab-separated-values`

Max file size: 50MB
