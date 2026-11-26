# Lab bundle

`lab.json` is the operator-authored description of the physical rig, served to the mobile
instrument at `GET /api/lab/config`.

## In production this file is generated, not edited

**Read this before editing anything on the server.** The deployment renders the bundle from
Ansible, in the `monad-knowledge` repo:

| Piece | Where |
|---|---|
| the body | `infra/ansible/inventory/group_vars/control.yml` → `monad_api_lab_bundle` |
| the `telemetry` block | `infra/ansible/roles/monad_api/templates/lab.json.j2` |
| applied by | `ansible-playbook playbooks/monad-api.yml` |

So a hand edit to `lab.json` on the server survives until the next `monad-api.yml` run and is then
overwritten without warning. Change `monad_api_lab_bundle` and re-run the playbook.

The `telemetry` block is merged by the template rather than written into the var because it carries
the **same credential** the `handset_telemetry` role installs in Alloy's htpasswd (IP-133). Copied
by hand it would drift, and the failure is silent in the worst direction: every handset would
authenticate against the old password, get a 401 it is designed to swallow, and the phones would go
dark exactly as they did on 2026-08-19.

`lab.json` here is therefore two things and neither is production: a **local development copy** for
running the API on a bench, and the file the service reads if you are running outside Ansible. It is
gitignored. `lab.example.json` is the committed shape.

## Why a file and not a table

It describes physical reality — which access point is up, where the anchors are surveyed, which
collector is listening — and that is edited by whoever is standing next to the hardware, often
minutes before a session. A schema would add a migration for every anchor added.

## The current deployment, and why most of it is empty

Three blocks are deliberately empty. Each was populated once and became false; an empty block
disables its role cleanly, and a populated block that describes hardware which is not there is the
failure this file exists to avoid.

### `access_points: []` — there is no access point

Two reasons, one per band, and they are different in kind:

- **5 GHz — impossible.** Intel firmware blocks TX-master (beaconing) on 5 GHz via LAR on every
  iwlmvm client card, and forcing it past the kernel crashes the firmware. There will never be a
  5 GHz soft AP on this hardware.
- **2.4 GHz — possible, and retired.** A `hostapd` soft AP (`monad-illum-24`, ch11, open) did run,
  and worked. It was switched off fleet-wide on **2026-08-11** (`illuminator_enabled: false`) after
  measuring **0.62 Hz delivered against monitor-mode injection's 24.79 Hz** — forty times worse. The
  role still exists and an arm can opt back in with `-e illuminator_enabled=true`.

So the list is empty because nothing is deployed, not because nothing could be. Illumination is now
`csid` monitor-mode injection from one node, which needs no association at all.

Consequences worth knowing:

- The app's `LabConfig.isIlluminationReady` requires a non-empty `access_points`, so the
  phone-as-paced-illuminator role is gated off. That is correct: there is nothing to associate to.
- **Never author a `connect_to_ap` quest step.** It blocks the run on an association that cannot
  happen. Every live quest already tells participants "the phone does not illuminate the room — the
  fleet does".
- `monad_api_lab_bundle` holds no secret, which is why it can live in plain inventory. The **served**
  bundle does carry one — the `telemetry` credential — but the template merges that in from the
  vault, so the var and the response have different secrecy. If a passworded AP is ever added, move
  `monad_api_lab_bundle` into the encrypted vault too.

### `collector.host: ""` — there is no reachable collector

`collectord` **is** deployed, on `monad01`, bound to `0.0.0.0:9998` — the port below is right. What
does not exist is an address a phone can reach it on. The old value `10.99.0.1` was `monad01`'s
address on the retired soft AP's own subnet, and that subnet went with the AP. A phone cannot join
the tailnet either, so today there is no path.

`udp_port` stays 9998 and **not** 9999: the illuminator broadcast its paced frames to
`10.99.0.255:9999`, and sharing the port fed 25 Hz of illuminator traffic into the collector as
malformed datagrams.

An empty host means the app never opens the socket, which is what you want — the iOS socket
*requires* a literal IPv4 and throws on anything else, so a hostname here would fail at connect
time rather than at config time.

A walk's clock is no longer disciplined over this path anyway: the app uses `GET /api/lab/time`, so
`mono_ns` maps onto the epoch the fleet's chrony-disciplined `csid` nodes share.

### `traffic_profiles: []` — nothing can command a pace

The four `ladder-25` … `ladder-200` profiles were the EXP-P3.5 rate ladder, and every one named
`ap_id: illum-24`. With no AP and no collector the phone cannot emit a paced stream, so advertising
four profiles the instrument cannot run is a promise the bundle cannot keep. The ladder design is
recorded in the `EXP-P3` experiment card; it is not lost by being absent here.

### `beacons` — the UUID is real, `zones` is honestly empty

`uuid` was generated once and is **fixed forever**: it is what every anchor advertises and every
phone filters on, and changing it makes already-flashed anchors invisible. `zones` stays empty until
the ESP32-C6 anchors are reflashed to iBeacon and surveyed into PostGIS (EXP-P3.0). An empty list
disables witnessing cleanly rather than half-enabling it; the floor bundle currently carries **no
anchor placements at all**, only nine receivers, one transmitter and 28 printed marker cards.

Earlier versions of this file invented zones (`fiit-floor2-lab-a`, `fiit-library-polygonized-9`)
with surveyed-looking coordinates. Those cell ids do not exist, and a fabricated anchor position is
worse than none: it feeds a phone a place to believe it is.

## Fields worth understanding rather than copying

- `site` is a **PostGIS site slug**, and it lands in every session sidecar as `identity.site`. It is
  what ties a phone session's local frame to surveyed coordinates, so a wrong slug points the whole
  trajectory at the wrong floor. The current value is `fiit-ground-0`, the faculty's own surveyed
  ground floor carrying the `fiit-ground-fleet` layout. Two wrong values have been in this file
  before: `fiit-office-pair` (the bench rig EXP-P2 ran on) and `fiit/floor2/lab` (never a slug at
  all). `fiit-library-lidar-floor-0` is the plausible-but-wrong candidate — its layout is from
  2026-07-26 and covers furniture and floor mounts only.
- `collector.host` must be a **literal IPv4 address** when it is set at all. The phone pins its
  socket to the Wi-Fi interface, and the iOS implementation rejects anything that is not four
  octets.
- `beacons.majors` groups anchors into CoreLocation regions. iOS monitors at most **20 regions per
  app**, so use `major` for floors or zone clusters and let ranging resolve the individual anchor.
- `advertise.namespace_uuid` is the base of the phone's identity broadcast (a 128-bit service
  UUID). The phone replaces the **last four bytes** with its 16-bit participant key and 16-bit
  session key, so keep those bytes zero. The fleet's passive scan matches on the first twelve.
  **One truth, two consumers**: this string must equal the fleet's `csid_ble_lab_namespace_uuid`
  (`infra/ansible/inventory/group_vars/csi_nodes.yml`). If they differ, the only symptom is
  `lab_frames = 0` in every capture sidecar.
- `traffic_profiles[].rate_hz` is the *commanded* pace. The delivered pace is measured separately
  and reported per session — a source that does not report its realised rate is not usable as a
  sampling axis.
- `telemetry` is where the handset ships its own health while a session runs (OTLP/HTTP straight to
  Alloy, IP-133). **On a bench, leave `endpoint` empty**: the shipper then stays silent, and nothing
  is more confusing than a phone retrying a host that was never meant to exist. In production the
  Ansible template fills all four fields, and `password` is never committed here.
- Bump `version` whenever you change anything. The app caches the last good bundle and uses the
  version to decide it has something newer, so an edit that leaves `version` alone is an edit every
  already-installed handset keeps ignoring.

## Checking what is actually served

```bash
curl -s https://api.monad.dubec.dev/api/lab/config | jq
```

`LabConfigService` fills any missing top-level key from its own default shape, so the response
always carries every field the app models — a key absent from the file is served as its default,
not omitted.
