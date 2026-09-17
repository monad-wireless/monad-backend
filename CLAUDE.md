# Monad backend

API for the MonadCount mobile instrument. Symfony 7.3 (PHP 8.3) + PostgreSQL.

- **Deployment**: `api.monad.dubec.dev` — project host (Hetzner CCX33), behind the host nginx that
  already terminates TLS for `monad.dubec.dev`. Container binds loopback only; see
  `docker-compose.deploy.yml`.
- **Database**: the deployment ships **no database of its own**. The host runs exactly one
  PostgreSQL cluster — the main stack's PostGIS/pgRouting service (PG 18) — and the API is a
  tenant in it: its own `monad_db`, its own `monad_user` role, no PostGIS extension, no access to
  `monad_gis`. The role and database are created by `roles/monad_api` (`tasks/database.yml`)
  before the container starts, since the entrypoint migrates on boot. The development compose in
  this repo is unaffected and still runs a throwaway `postgres:16-alpine` on 55432 — local work
  needs nothing else running.
- **Storage**: Hetzner Object Storage via `async-aws/s3` (no AWS SDK, no AWS anything), the project's bucket — the same tenancy as
  the `csid` fleet CSI captures and the simulation artefacts. Configured by `HETZNER_S3_ENDPOINT` +
  `HETZNER_S3_USE_PATH_STYLE`; Hetzner has no wildcard certificate, so path-style addressing is required.
- **Session key layout**: `datasets/monad-app-sessions/{participantId}/{sessionId}/{filename}`,
  mirroring the fleet convention so a phone session and a radio capture are siblings in one bucket
  and joinable by session rather than by upload date.

## Surfaces

| Route | Purpose |
|---|---|
| `POST /api/storage/session-upload` | Stream one lab-session artefact to object storage. Streams first, `metadata.json` last — its presence marks the session complete. |
| `GET /api/lab/config` | The lab bundle: collector endpoint, access points, beacon plan, traffic profiles, clock policy, telemetry sink. Authenticated — it carries the handset **telemetry** credential (IP-133). It used to be AP passwords too; there are no access points any more, so the telemetry block is now the only secret in it and the only reason this route is behind a token. |
| `GET /api/lab/time` | Coarse four-timestamp fallback. The real clock discipline runs over the collector's UDP socket, on the same path the data takes. |
| `POST /api/lab/ground-truth` | Ground-truth check-in/out scans from participant devices, single or batched. Idempotent on `scan_nonce`. |
| `GET /api/lab/ground-truth/{labSessionId}` | Live room-wide people tally for one session, per zone and overall. Cheap to poll. |
| `/api/auth/*`, `/api/quest*` | Accounts and the quest schedule engine. `POST /api/quest/{id}/start` takes an optional `{"handset": {…}}` body (IP-149): the phone's description of itself, validated against a **closed key set** (`App\Quest\HandsetDescriptor`, 400 `VALIDATION_108`/`_109` on anything present and wrong) and frozen VERBATIM on the enrollment (`handset_snapshot`) beside the `handsets` row it finds-or-creates by the app's own installation UUID. An empty body is an app build that predates the descriptor and stays valid. |
| `GET /api/lab/fleet` | The fleet's public vital signs for `monad.dubec.dev` — per-node readings and fleet-wide scalars, from a closed PromQL allow-list (`App\Fleet\FleetMetricsReader`). Exists so the website, a host process, never needs a route into the observability stack: Mimir publishes no host port, this container is on the same `monad` network and reaches `mimir:9009` by container DNS. Unauthenticated (the site holds no JWT) and **404'd on the public vhost** like `/admin` — the site calls it over loopback. `reachable: false` is a first-class answer and must not be rendered as zeros. |

`MONAD_METRICS_URL` (`http://mimir:9009/prometheus`) is read by two things, because it is one
dependency: the fleet endpoint above, and the IP-128 quest-arming check ("is this node
capturing?"). The arming check **fails open** and was inert while the variable was unset — setting
it turns it on, so a measurement quest at a resting node stops being offered. That is the designed
behaviour and it is a change to the participant path; set it to `""` to keep it off. An UNSET
variable is the empty string too (`services.yaml` declares the default): until 2026-09-04 the
`default::` processor handed the readers `null`, and the container crashed at `cache:clear` on
any host without the variable — the dev compose, a laptop, the test suite.

## Two kinds of provenance on an enrollment (IP-128, IP-149)

`quest_enrollments.device_id` says which fleet node listened; `quest_enrollments.handset_id` says
which phone walked. A **`Device`** is a fleet node — the box a sticker is stuck to. A **`Handset`** is
one app installation on one phone, keyed by a UUID the app mints once and keeps; a reinstall is a
new row by decision, and no platform device identifier (`identifierForVendor`, `ANDROID_ID`) is
accepted or stored. `handsets.last_descriptor` is a read model for the inventory page; the evidence
for a given run is the per-run `handset_snapshot`, never normalised. Both columns are written once
at start and rendered read-only, for the reason ground-truth scans are.

## The recording-session register (IP-149)

`lab_sessions` is one row per session the app uploaded — the register behind
`datasets/monad-app-sessions/`. **Written by the upload path, never by a form**: `S3Controller`
upserts a row on every accepted artefact (`INSERT … ON CONFLICT DO UPDATE SET artefacts =
artefacts || excluded.artefacts`, because ten handsets flush concurrently) and completes it when
`metadata.json` arrives, resolving the sidecar's `identity.enrollment_id`, `identity.quest_id` and
`environment.handset.handset_id` to rows **only when they exist**. `completed_at` is set once; a
replayed sidecar does not move it. The whole sidecar is kept as `jsonb`. **NOT the ground-truth
session**: `ground_truth_scans.lab_session_id` is the event the phones stamp on scans, and a scan
joins a recording session only through `recording_session_id`. Sessions uploaded before 2026-09-04
enter the register through `app:lab-sessions:backfill` (idempotent, one-shot, doubles as the
reconciler):

```bash
docker exec -it monad_api php bin/console app:lab-sessions:backfill --dry-run   # list, write nothing
docker exec -it monad_api php bin/console app:lab-sessions:backfill             # every session on S3
```

## Management interface (`/admin`)

EasyAdmin, session-authenticated, `ROLE_SUPERADMIN` only — and **not published to the internet**:
the public vhost 404s `/admin`, and the surface is reachable over the tailnet at
`http://monad-api.monad.internal:8084/admin` (`intranet_services` in the monad-knowledge inventory).
Being on the VPN is the first gate, signing in is the second.

Accounts come from the console, never from an endpoint — `/api/auth/register` can only mint
`ROLE_USER`, because an endpoint that could grant `ROLE_SUPERADMIN` would be a privilege-escalation
surface open to the world:

```bash
docker exec -it monad_api php bin/console app:user:create you@stuba.sk --admin
```

What is editable is a deliberate line, not an oversight:

| Section | Write access |
|---|---|
| Ground-truth scans, conflicts, step completions, skip records | **none** — device-reported measurement. A scan is never overwritten and a conflict is never reconciled (E3); an admin screen that could "fix" a row would make both unenforceable, invisibly, months before anyone reads the data. |
| Lab bundle | **read-only** — Ansible renders it and bind-mounts it read-only, so an edit here would be reverted by the next run while appearing to have worked. |
| Users | edit + anonymise. No hard delete: `softDelete()` scrubs identity in place and leaves the pseudonymous scans countable. Passwords are write-only and blank means "keep". |
| Quests and steps | the quest builder (`/admin/lab/quests`, IP-157): typed per-step forms from `App\Quest\Schema`, validated on the `QuestStep` entity so the admin, `lab_quest_write` and `POST /api/admin/quests` refuse the same config. A quest with step completions locks its steps; Duplicate makes a revision. Raw JSON only behind the Advanced toggle. |
| Enrollments | edit (status, device), no NEW. |
| Placement mirror (`lab_placements`) | **none from the admin** — written only by the MCP tool `lab_placements_write` from `monad-knowledge lab placements-export`; PostGIS is the position of record and this database cannot see it. |
| Notifications | compose and schedule; **nothing editable after send**; a scheduled one can be cancelled. |
| Beta signups | status changes (invited, declined, withdraw = scrub in place), notes, CSV export. Rows are created by `POST /join` only. |
| Recording sessions | **none** — written by the upload path. The list is a CRUD index (filters, search); the detail is a reading page. |
| Handsets | `label` only — an operator note next to a machine string. Everything else is what the phone reported. No NEW, no DELETE (`ON DELETE RESTRICT` from enrollments). |

**The reading pages (IP-149).** Beyond the CRUD, `DashboardController` renders the pages an
operator reads a run on, all tables, none writing: the **Overview** (activity for 24 h / 7 d / all,
recent recording sessions and enrollments, the fleet strip), **Recording session** (`/admin/runs/
sessions/{id}`: sidecar block by block, artefacts with 15-minute presigned links, the walk figures,
the ground truth naming it), **Enrollment** (`/admin/runs/enrollments/{id}`: handset snapshot, steps
in realised order with `mono_ns` and skips, its recording sessions and their figures), **Handset**
(`/admin/runs/handsets/{id}`), **Participant** (`/admin/people/{id}`), **Arming matrix** (quests ×
nodes, unfiltered, through `App\Quest\ArmingMatrixBuilder` — the same builder the public JSON
reads), **Fleet vitals** (`FleetMetricsReader`, `reachable: false` as a sentence) and **Quest
analytics** (funnel, duration quantiles, skip reasons, failing steps, per node; inline SVG bars, no
chart library). Every clock is the stored instant converted to `MONAD_ADMIN_TIMEZONE` (default
`Europe/Bratislava`) through the `clock` Twig filter, zone abbreviation beside it; never `strftime`
on the UTC host. Look: `public/admin.css` — tables over cards, sharp corners.

**The walk figures come from monad-knowledge web, not from here.** The reduction that draws a walk
lives in `monad_knowledge.walk`; re-implementing it in PHP would be a second reduction. The admin
embeds `<img>` tags pointing at `/internal/walk/{participant}/{session}/{view}.png` on
`monad-web.monad.internal:8083`, signed by `App\Service\WalkFigureUrlSigner`
(`HMAC-SHA256(key, "{participant}/{session}/{view}|{floor}|{exp}")`, one-hour expiry). The operator's
BROWSER fetches them over the tailnet; this container never does. Two env vars, both rendered by
`roles/monad_api`: `MONAD_WEB_INTERNAL_URL` (the tailnet name) and `MONAD_WALK_FIGURE_KEY`
(`vault_walk_figure_signing_key`, the same variable monad-web's `.env` gets). Both empty = figures
off and every table unaffected; the page prints one sentence. The `site` figure (mesh registered to
the floor) also needs `floor` in the lab bundle.

## Ground truth (the people channel)

Every other stream counts *phones*. This one counts *people* — it only advances when a human points
a camera at a code taped to a doorframe — and phone-vs-person bias is itself the quantity a later
experiment sets out to measure, so the two must never be derived from each other.

The wire fields are the pre-registered `ground_truth.tsv` columns verbatim, in snake_case
(`mono_ns, wall_ms, lab_session_id, participant_token, zone_id, direction, site, scan_nonce,
recording_session_id`) — deliberately unlike the camelCase quest DTOs, so one spelling runs from the
printed code through SQLite, TSV, this API and the analysis join.

- **Idempotency** is a `UNIQUE` index on `scan_nonce`, not application-level checking. Ten to twelve
  handsets each re-upload their complete set on every flush, concurrently. Duplicates keep the
  earliest `mono_ns`.
- **Contradictions** — same nonce, different `(participant_token, zone_id, direction)` — are
  pre-registration exclusion **E3**. The stored row is never overwritten; the refused claim is
  persisted to `ground_truth_conflicts` and surfaced in the aggregate, because the only moment a
  human can still find out what happened is while the session is running. Logged, never reconciled
  by judgement.
- **Privacy posture is count-without-identify.** `participant_token` is an opaque pseudonym. There
  is no foreign key to `users`, so a join from a scan to an account is not expressible in the
  schema. No names, e-mails, MACs or device identifiers are accepted, stored or returned.

Occupancy is reported two ways because they disagree informatively: `checked_in` (per participant,
latest scan wins — idempotent under a double tap) and `net_sum` (the literal cumulative sum the
pre-registration defines). At room level, `overall.checked_in` counts each participant once by their
latest scan anywhere, while `overall.zone_sum` adds the zones; the gap is exactly the set of people
who entered a new zone without scanning out of the old one.

## Tests

```bash
docker compose up -d postgres
cd backend/monad-backend
APP_ENV=test php bin/console doctrine:database:create --if-not-exists
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction
php vendor/bin/phpunit
```

Integration tests run against a real PostgreSQL on purpose: the idempotency guarantee *is* a unique
index, and a double that returns whatever it was told cannot fail the way the database can.

## Lab bundle

`config/lab/lab.json` (gitignored; `lab.example.json` is the shape). Operator-authored, not a
Doctrine entity — it describes physical reality and is edited next to the hardware. See
`config/lab/README.md` for the fields that are easy to get wrong (`collector.host` must be a
literal IPv4; iOS monitors at most 20 beacon regions).

**In production this file is generated.** Ansible renders it from
`monad-knowledge/infra/ansible/inventory/group_vars/control.yml` → `monad_api_lab_bundle` (plus the
`telemetry` merge below) and bind-mounts it read-only, so a hand edit on the server is reverted by
the next `monad-api.yml` run while appearing to have worked. The copy in this repo is the local
development one.

**Three blocks are deliberately empty, as of bundle version 3 (2026-08-22).** `access_points`,
`collector.host` and `traffic_profiles` all described the 2.4 GHz `hostapd` soft AP that was switched
off fleet-wide on 2026-08-11 (0.62 Hz delivered against monitor-mode injection's 24.79 Hz — forty
times worse). On 5 GHz an AP is impossible outright: Intel LAR blocks beaconing on every iwlmvm
client card. So today there is nothing for a phone to associate to, therefore no reachable collector
and no commandable pace — the app gates the illuminator role off on exactly those fields, and
**a `connect_to_ap` quest step would block a run on an association that cannot happen**.
`beacons.zones` is empty for a different reason again: the anchors are not yet reflashed to iBeacon
or surveyed. `README.md` in that directory carries the full reasoning.

**IP-133 — the bundle carries a `telemetry` block, and this API does not own it.** It names the
public OTLP endpoint handsets ship instrument health to, plus the basic-auth credential for it.
The phone posts **straight to Alloy**; nothing about that data path passes through here. This
endpoint only *delivers the credential*, because the bundle is already authenticated and an app
binary is readable — so a compiled-in secret would be a published one. (The original wording here
said the bundle "already carries AP passwords". It does not any more: `access_points` is empty,
which makes this block the only secret in the bundle rather than one more.)

Ansible renders the block from `vault_handset_telemetry_password`, the same variable that
writes Alloy's htpasswd, so the two cannot disagree about the value. Rotating means running
both `control_plane.yml --tags telemetry` and `monad-api.yml`. See
`monad-knowledge/docs/HANDSET-TELEMETRY.md`.

## Legal pages

- Terms & Conditions: `/terms`
- Privacy Policy: `/privacy-policy`
