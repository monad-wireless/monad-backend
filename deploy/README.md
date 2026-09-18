# Deploying the API

Target: `api.monad.dubec.dev` on the project host (Hetzner CCX33), alongside the rest of the lab on
the shared external `monad` docker network. TLS is terminated by the host nginx that already serves
`monad.dubec.dev`; the container publishes on **`127.0.0.1:8084`** only — not 8080, which Headscale's
control server already occupies. DNS for `api.monad.dubec.dev` already resolves to the host.

## Once, on the host

```bash
docker network create monad          # already exists if the lab stack is up
cp deploy/nginx/api.monad.dubec.dev.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/api.monad.dubec.dev.conf /etc/nginx/sites-enabled/
certbot --nginx -d api.monad.dubec.dev
```

Create `.env` next to `docker-compose.deploy.yml` (gitignored) with `POSTGRES_*`, `APP_SECRET`,
`JWT_PASSPHRASE`, and the `HETZNER_S3_*` values for the project's bucket. Then author
`backend/monad-backend/config/lab/lab.json` from `lab.example.json` — the API serves an empty
bundle without it, and phones will have no collector to talk to.

## Each deploy

```bash
docker compose -f docker-compose.deploy.yml pull
docker compose -f docker-compose.deploy.yml up -d
docker compose -f docker-compose.deploy.yml logs -f api
curl -fsS https://api.monad.dubec.dev/api/health
```

The entrypoint waits for Postgres, runs migrations, warms the cache against the *runtime*
environment, and only then starts the server. The JWT keypair lives in a named volume so tokens
survive a redeploy — regenerating it would sign every phone in the field out mid-session.

## Object storage

Hetzner Object Storage, reached with `async-aws/s3`. `HETZNER_S3_ENDPOINT` is **required** — there
is no default, deliberately: an absent endpoint used to mean "talk to Amazon", which is the kind of
default that ships research data to the wrong provider unnoticed. Path-style addressing is
mandatory (Hetzner issues no wildcard certificate for virtual-hosted bucket names).

## Generating `JWT_PASSPHRASE`

It is an arbitrary high-entropy string that encrypts the LexikJWT **private key** — not a key
itself, and not derived from anything:

```bash
openssl rand -base64 48
```

Two properties matter more than the generation method:

- **Set it before first boot.** The entrypoint generates the keypair into a named volume on first
  start and encrypts it with whatever passphrase is present. There is no later opportunity.
- **Never change it afterwards.** The passphrase is the only thing that can decrypt the existing
  private key. Changing it does not rotate anything — it makes the key unreadable, the API fails to
  sign or verify, and every phone in the field is signed out mid-session. If you must rotate,
  delete the `jwt_keys` volume and the passphrase together, accepting that every token dies.

Store it in ansible-vault as `vault_monad_api_jwt_passphrase`, alongside `APP_SECRET`
(`openssl rand -hex 32`) and the Postgres password.

## Observability

The container ships traces and metrics to Alloy at `http://alloy:4318` over the `monad` network —
the same LGTM stack (IP-051) the rest of the lab uses. Nothing extra to configure: `OTEL_*` defaults
are baked into the image and overridable per environment.

Beyond the usual HTTP/Doctrine spans, `LabTelemetry` turns each uploaded session sidecar into
instrument metrics — see `src/Service/LabTelemetry.php`. `monad.lab.session.unpinned` is the one to
alert on: it counts sessions whose datagram socket was not pinned to the experiment AP, which is
the failure mode where the app reports success and the observer node receives nothing.

To disable exporting (local runs, CI): `OTEL_TRACES_EXPORTER=none OTEL_METRICS_EXPORTER=none`.
