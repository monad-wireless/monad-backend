# Monad

Mobile app backend for indoor wireless tracking.

## Stack

- Backend: Symfony 7.3 (PHP 8.3)
- Database: PostgreSQL 16
- Infrastructure: Hetzner CCX33 (docker compose on the shared `monad` network) + Hetzner Object Storage
- CI/CD: GitHub Actions

## Deployment

Push to `main` builds and publishes the image to GHCR (`.github/workflows/docker.yml`).
Deployment is an Ansible play run against the project host — see `deploy/README.md` and
`infra/ansible/roles/monad_api` in the monad-knowledge repository. Database migrations run from the
container entrypoint on boot.

## Domain

- API: https://api.monad.dubec.dev
- Terms & Conditions: https://api.monad.dubec.dev/terms
- Privacy Policy: https://api.monad.dubec.dev/privacy-policy

## Management interface (`/admin`, IP-157)

EasyAdmin 4.29 is the shell (login, read-only measurement tables, the reading pages); four bespoke
pages sit beside it, each a Twig page with one vanilla-JS island and no build step. Tailnet-only,
`ROLE_SUPERADMIN`. The menu, as of 2026-09-16:

| Section | Entries | Route or CRUD |
|---|---|---|
| Overview | activity, recent runs, fleet strip | `admin` |
| Lab | Quests (builder), Placement board, Arming matrix, Fleet vitals, Devices (fleet), Lab bundle | `admin_lab_quests`, `admin_lab_placements`, `admin_lab_arming`, `admin_lab_fleet`, Device CRUD, `admin_lab_bundle` |
| Runs | Recording sessions, Enrollments, Handsets, Quest analytics, Ground truth, Ground-truth scans, Scan conflicts (E3), Step completions, Skip records | LabSession / QuestEnrollment / Handset CRUDs, `admin_quests_analytics`, `admin_lab_sessions`, read-only CRUDs |
| People | Participants, Onboarding desk, Notifications | User CRUD, `admin_people_onboarding`, `admin_people_notifications` |
| System | API docs, Sign out | `/api/doc`, logout |

The four bespoke pages (`Admin\QuestBuilderController`, `Admin\PlacementBoardController`,
`Admin\OnboardingController`, `Admin\NotificationsController`) use `#[AdminRoute]` on plain
controllers so EasyAdmin builds their AdminContext and the layout renders; a plain `#[Route]`
under `/admin` does not get one. Step `config` is validated against `App\Quest\Schema` on the
`QuestStep` entity, so the admin form, `lab_quest_write` and `POST /api/admin/quests` refuse the
same malformed config. Look: `public/admin.css` on the public site's tokens; per-page rules in
`public/admin-{quests,placements,notifications,onboarding}.{css,js}`.

**The quest builder (`/admin/lab/quests`).** Quests are authored here, not in a CRUD form: the
index lists every quest with its status against the clock (live, scheduled, hidden), audience,
window, points, step and completion counts, and offers Edit, Duplicate, Hide now, Export JSON and
Import JSON in the exact `lab_quest_write` argument shape. The editor is a Symfony header form
beside a vanilla-JS step island (`public/admin-quests.js`, no build step) that posts the step list
as one hidden JSON field; the server re-validates every step through the `#[ValidStepConfig]`
constraint on `QuestStep::$config`, and violations come back per step index. A preflight panel
repeats every warning `lab_quest_write` emits plus five of its own (a target absent from
`lab_placements` for the floor, a mirror row older than the quest, a `ble_advertise` step inside a
session that already broadcasts, an empty or inverted window, a payload over 20 kB); warnings never
block a save, violations do. Probe targets and `walk_to` locations are picked from the placement
mirror, which is empty until `lab placements-export` has run — the panel says so rather than
staying silent. A quest with any step completion locks its step rows (the FK on
`quest_step_completions.step_id` has no `ON DELETE` clause): the island turns read-only, the header
stays editable, and Duplicate offers a revision named with the month. `QuestStepCrudController` is
gone and `QuestCrudController` is a read-only detail page whose index redirects here.

**The placement board (`/admin/lab/placements`).** `lab_placements` is a MIRROR of the PostGIS
placement layers, never the position of record: this API's role is refused access to `monad_gis`
on purpose, so the board learns where a card or node stands only through the MCP tool
`lab_placements_write`, which replaces one floor per call inside one transaction and stamps
`synced_at` and `sync_id`. Produce its argument with
`uv run monad-knowledge lab placements-export --floor fiit-ground-0`; hand-editing the payload is
possible and pointless, because the next export overwrites it. The board shows every mirrored row
with the verdict of the join against the LIVE quest set — `matched` (a live quest names it), `spare`
(present, named by nobody: normal, the fingerprint pool outlives any one arm), `mismatch` (a live
quest names a card the mirror does not have, the one that costs a participant a corridor) — plus a
collapsed `historical` list for values only hidden or scheduled quests name. The verdicts are
computed on call and stored nowhere. The print sheet moved here from the retired
`/admin/lab/markers`: `/admin/lab/placements/print` renders one QR per mirrored card through
`/admin/lab/placements/marker/{value}.svg` (the admin firewall, so the operator's session cookie
authenticates the `<img>`; the `/api/lab/markers/*.svg` route is the stateless JWT firewall and
401'd every figure on the old page). Nothing on either page can move a card. The Overview strip
shows the mismatch count.

**Notifications (`/admin/people/notifications`).** The API carries a per-user inbox
(`GET /api/me/notifications`, a bare array, `?after=` for the delta since the newest cached row), a
read mark, push-token registration and two opt-ins under `/api/me/notification-preferences`.
Operators compose here: type (`general` | `quest_callout`), audience (`all` = active accounts,
`beta` = the cohort, `operators` = ROLE_SUPERADMIN), an optional quest, send-now or schedule, and
an expiry. **Nothing is editable after send** — a scheduled, unsent one can be cancelled; a sent
one has a detail page only. Sending writes one `notification_deliveries` row per recipient and
hands the FCM calls to Messenger, so the click returns at once. That needs the **`worker`
container** (`messenger:consume scheduler_default async`, in `docker-compose.deploy.yml`): without
it a send still fills every inbox but every push stays `queued`. Push goes out only when
`MONAD_FCM_CREDENTIALS` names a readable Firebase service account — empty or absent selects the
null adapter, which logs one line per skipped send and marks deliveries `skipped`, so the inbox
works with no Firebase project at all. `notify_callouts` defaults **off** (quest callouts are
promotional under App Store Review Guideline 4.5.4); `notify_general` defaults on. Neither gates the
inbox, only the push. Scheduled sends need no host cron: `src/Schedule.php` runs
`app:notifications:dispatch-due` every minute and `app:beta:purge --apply` daily in the same worker.

## Beta onboarding (`/join` and the onboarding desk)

`GET/POST /join` is the public signup page, rendered by this API in the site's tokens and reached
as `monad.dubec.dev/join` through one nginx `location` on the site vhost (`roles/monad_web`). It is
the only public POST this application serves and it holds no session: its protection is a honeypot
field (`website`) plus a sliding-window rate limit of five submissions per client IP per hour
(`config/packages/rate_limiter.yaml`). A honeypot hit and an already-known email both render the
same confirmation page as a real signup, so the page never discloses which addresses it knows. The
consent sentence lives in `App\Join\JoinConsent` beside its version, and the version the applicant
ticked is stored on the row; changing the wording is changing `VERSION` in the same edit. The
retention number in that sentence is rendered from `MONAD_BETA_RETENTION_DAYS`, so the text and the
purge cannot disagree about it.

`POST /api/auth/register` keeps its request and response shape and gains one side effect: a
`beta_signups` row with the same email (case-insensitive, matching the unique index on
`lower(email)`) in status `new` or `invited` becomes `registered` and the account gets
`cohort = 'beta'`, in the same flush as the account.

The desk at `/admin/people/onboarding` works the pipeline by hand. There is no mail transport
anywhere in this project by decision, so **Invite** is a `mailto:` with the text from
`templates/join/invitation.txt.twig` prefilled and **Mark invited** beside it is the status
change — a sent mail cannot be observed from here. **Withdraw** scrubs email, name and notes in
place (`withdrawn-<8 hex>@invalid`) and keeps every date, the posture of `User::softDelete()`, so
the funnel still counts the row. **Export CSV** exports the current filter.

```bash
docker exec -it monad_api php bin/console app:beta:purge            # dry run, lists, writes nothing
docker exec -it monad_api php bin/console app:beta:purge --apply    # scrub the rows listed
```

The window is measured from the invitation where one was sent and from the signup otherwise,
which is the sentence the consent text makes. `registered` rows are never candidates; `new`,
`invited` and `declined` rows past the window are.

## Removed 2026-09-16 (IP-157 Phase 1)

- `News` and `QrCode`: entities, repositories, CRUD controllers, menu items, fixture branches;
  migration `Version20260916100000` drops `news` and `qr_codes` (`down()` recreates them).
- `PassportController` and `Quest\PassportService` (`GET /api/me/passport`, never requested).
- API Platform (`api-platform/symfony`, `api-platform/doctrine-orm`, the bundle, its two config
  files, `src/ApiResource/`): it served zero resources. `api-platform/openapi` stays as a model
  library so the hand-written `App\OpenApi\*Decorator` classes compile; `App\OpenApi\DecoratorDescriber`
  feeds their document to Nelmio, which renders `/api/doc`.
- The dangling `api_login_check` route and Lexik's `api_platform` block.
- `translations/` and `config/packages/translation.yaml` (one `.gitignore`, no strings).
- `fiit_dod_2025_quest.json` at the app root.
- `QuestStepCrudController` and the Scan markers page (`templates/admin/markers.html.twig`,
  `DashboardController::labMarkers`); the quest builder and the placement board replace them.

Kept deliberately: `symfony/ux-twig-component` and its bundle, because EasyAdmin 4.29 renders its
layout, menu and flash messages as Twig components; `DeviceController` (the app calls it).
