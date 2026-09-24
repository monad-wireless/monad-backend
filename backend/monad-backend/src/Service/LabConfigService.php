<?php

namespace App\Service;

use App\Constants\ErrorCode;
use App\Exception\SystemException;

/**
 * Loads the lab bundle from a JSON file on disk.
 *
 * A file rather than a database table on purpose. The bundle describes physical reality — which
 * access point is up, where the anchors are surveyed, which collector is listening — and that is
 * edited by whoever is standing next to the hardware, often minutes before a session. A schema
 * would add a migration to every anchor added and would put the edit behind an admin UI that does
 * not exist.
 *
 * A malformed bundle fails here rather than reaching a phone: a file that is not readable or not
 * JSON raises, instead of being served as a partial bundle a handset would act on.
 *
 * Field-level validation is deliberately NOT done here. The client models every field with a
 * default and disables the role behind it when the value is empty — an absent collector host
 * silently gates off the illuminator, an absent beacon UUID gates off witnessing — so the useful
 * check is "does this describe the rig", which is a human's job and lives in config/lab/README.md.
 * A validator here would have to encode the physical deployment, which is the thing this file
 * exists so as not to compile in.
 */
class LabConfigService
{
    public function __construct(
        private string $configPath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function bundle(): array
    {
        if (!is_file($this->configPath)) {
            // An empty-but-valid bundle is better than a 500: the app falls back to its cached
            // copy or to an operator override typed into the lab console.
            return $this->emptyBundle();
        }

        $raw = file_get_contents($this->configPath);
        if ($raw === false) {
            throw new SystemException(ErrorCode::SYSTEM_INTERNAL_ERROR);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new SystemException(ErrorCode::SYSTEM_INTERNAL_ERROR);
        }

        return $decoded + $this->emptyBundle();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBundle(): array
    {
        return [
            'version' => 0,
            'site' => '',
            // IP-149 — the committed floor bundle the admin registers walks against
            // (`monad_knowledge/web/static/floors/<floor>.json`). Empty means the admin
            // omits the `site` figure and says why; it never guesses a floor from `site`.
            'floor' => '',
            'collector' => ['host' => '', 'udp_port' => 9999, 'http_base' => ''],
            'access_points' => [],
            'beacons' => ['uuid' => '', 'majors' => [], 'zones' => []],
            // The phone-side identity broadcast (ble_advertise steps / the broadcaster role).
            // namespace_uuid's last four bytes are replaced on the phone by the participant and
            // session keys, so the frame identifies a session, not a person. An empty namespace
            // means broadcasting is not configured on this deployment.
            'advertise' => [
                'namespace_uuid' => '',
                'interval_ms' => 250,
                'tx_power' => 'medium',
            ],
            'traffic_profiles' => [],
            // IP-133 — where the handset ships its own health while a session runs (OTLP/HTTP
            // straight to Alloy, no application in between). Present in the default shape so the
            // response always carries every field the client models: a bundle file written before
            // this block existed is served with the block at its defaults rather than without the
            // key, and a client that saw no key at all would have to guess which it was.
            //
            // An empty endpoint means the deployment has no public collector and the shipper stays
            // silent. That is the correct default: the credential is real, so defaulting it to
            // anything reachable would have a bench build authenticating against production.
            'telemetry' => [
                'endpoint' => '',
                'username' => '',
                'password' => '',
                'flush_seconds' => 15,
            ],
            'clock_sync' => [
                'burst_size' => 20,
                'burst_spacing_ms' => 50,
                'resync_seconds' => 600,
                'timeout_ms' => 1000,
            ],
        ];
    }
}
