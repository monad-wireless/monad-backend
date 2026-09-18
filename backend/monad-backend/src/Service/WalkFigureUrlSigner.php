<?php

namespace App\Service;

/**
 * Signed URLs to the walk figures monad-knowledge web renders (IP-149 Part D).
 *
 * The admin embeds `<img src>` tags that the operator's browser fetches from
 * `monad-web.monad.internal` over the tailnet. An `<img>` cannot carry a header,
 * so the second gate after the tailnet is the URL itself: an HMAC over the path
 * and an expiry, keyed by one Ansible variable (`vault_walk_figure_signing_key`)
 * rendered into BOTH services — the same one-value-two-consumers pattern the
 * handset telemetry credential uses, so the two sides cannot disagree.
 *
 * `sig = hex(hmac_sha256(key, "{participant}/{session}/{view}|{floor}|{exp}"))`.
 * monad-web's `/internal/walk/...` route recomputes it in constant time and
 * refuses anything else with a bodiless 403. The floor slug sits between the two
 * bars when present and is the empty string when not, so `trajectory.png` and
 * `site.png?floor=fiit-ground-0` sign differently.
 *
 * UNCONFIGURED IS A STATE, NOT AN ERROR. Without a key the admin renders every
 * table from Postgres alone and prints one sentence where the figures would be.
 */
final class WalkFigureUrlSigner
{
    public const VIEWS = ['overview', 'trajectory', 'quality', 'speed', 'site'];

    /** Seconds a signed URL stays valid. One hour: long enough to read a page, short enough to stop a shared link living on. */
    private const TTL_SECONDS = 3600;

    public function __construct(
        /** `http://monad-web.monad.internal:8083`, no trailing slash. Empty disables the figures. */
        private readonly string $baseUrl = '',
        private readonly string $signingKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->signingKey !== '';
    }

    /** Why the figures are absent, for the page to print. */
    public function unconfiguredReason(): string
    {
        if ($this->baseUrl === '' && $this->signingKey === '') {
            return 'Walk figures are not configured on this deployment (MONAD_WEB_INTERNAL_URL and MONAD_WALK_FIGURE_KEY are unset).';
        }
        if ($this->baseUrl === '') {
            return 'Walk figures are not configured: MONAD_WEB_INTERNAL_URL is unset.';
        }

        return 'Walk figures are not configured: MONAD_WALK_FIGURE_KEY is unset.';
    }

    /** A signed PNG URL for one view of one session. `null` when not configured. */
    public function figureUrl(string $participantId, string $sessionId, string $view, ?string $floor = null, ?int $now = null): ?string
    {
        if (!$this->isConfigured() || !in_array($view, self::VIEWS, true)) {
            return null;
        }

        return $this->signed("walk/{$participantId}/{$sessionId}/{$view}.png", $participantId, $sessionId, $view, $floor, $now);
    }

    /** A signed URL for the reduction numbers (`info.json`). `null` when not configured. */
    public function infoUrl(string $participantId, string $sessionId, ?int $now = null): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return $this->signed("walk/{$participantId}/{$sessionId}/info.json", $participantId, $sessionId, 'info', null, $now);
    }

    /** The exact string both sides sign. Public so the test can pin the wire format. */
    public static function payload(string $participantId, string $sessionId, string $view, ?string $floor, int $exp): string
    {
        return sprintf('%s/%s/%s|%s|%d', $participantId, $sessionId, $view, $floor ?? '', $exp);
    }

    public function signature(string $participantId, string $sessionId, string $view, ?string $floor, int $exp): string
    {
        return hash_hmac('sha256', self::payload($participantId, $sessionId, $view, $floor, $exp), $this->signingKey);
    }

    private function signed(string $path, string $participantId, string $sessionId, string $view, ?string $floor, ?int $now): string
    {
        $exp = ($now ?? time()) + self::TTL_SECONDS;
        $query = ['exp' => $exp, 'sig' => $this->signature($participantId, $sessionId, $view, $floor, $exp)];
        if ($floor !== null && $floor !== '') {
            $query = ['floor' => $floor] + $query;
        }

        return sprintf('%s/internal/%s?%s', rtrim($this->baseUrl, '/'), $path, http_build_query($query));
    }
}
