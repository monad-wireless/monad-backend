<?php

namespace App\Join;

use App\Enum\BetaAvailability;
use App\Enum\BetaPlatform;
use Symfony\Component\HttpFoundation\Request;

/**
 * One POST /join, parsed and checked (IP-157).
 *
 * Manual rather than a Symfony Form: six fields, no CSRF (the form is public and stateless, its
 * protection is the honeypot and the rate limiter), and the template renders errors beside the
 * fields with no form theme. `errors` is keyed by field name so the template can place each one.
 */
final class JoinSubmission
{
    public const NAME_MAX = 80;
    public const EMAIL_MAX = 180;
    public const SOURCE_MAX = 32;

    /** @param array<string, string> $errors */
    private function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly ?BetaPlatform $platform,
        public readonly ?BetaAvailability $availability,
        public readonly bool $consent,
        public readonly bool $updates,
        public readonly ?string $source,
        public readonly bool $honeypotFilled,
        public readonly array $errors,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $p = $request->request;
        $email = trim($p->getString('email'));
        $name = trim($p->getString('name'));
        $platform = BetaPlatform::tryFrom($p->getString('platform'));
        $availability = BetaAvailability::tryFrom($p->getString('availability'));
        $consent = $p->getBoolean('consent');
        $updates = $p->getBoolean('updates');
        $honeypot = trim($p->getString('website')) !== '';
        // The hidden field carries what the GET saw in `?src=`; the query string is the fallback
        // for a form posted from somewhere that dropped the field.
        $source = self::cleanSource($p->getString('src') !== '' ? $p->getString('src') : $request->query->getString('src'));

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'An email address is needed: it is where the invitation goes.';
        } elseif (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'That does not look like an email address.';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('At most %d characters.', self::NAME_MAX);
        }
        if ($platform === null) {
            $errors['platform'] = 'Pick one.';
        }
        if ($availability === null) {
            $errors['availability'] = 'Pick one.';
        }
        if (!$consent) {
            $errors['consent'] = 'The signup cannot be stored without this.';
        }

        return new self($email, $name === '' ? null : $name, $platform, $availability, $consent, $updates, $source, $honeypot, $errors);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** `?src=` is a tag the researcher puts on a link or a poster; anything else is dropped. */
    public static function cleanSource(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > self::SOURCE_MAX || preg_match('/^[A-Za-z0-9-]+$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /** What the template re-renders after a failed POST. @return array<string, mixed> */
    public function values(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name ?? '',
            'platform' => $this->platform?->value,
            'availability' => $this->availability?->value,
            'consent' => $this->consent,
            'updates' => $this->updates,
            'src' => $this->source,
        ];
    }
}
