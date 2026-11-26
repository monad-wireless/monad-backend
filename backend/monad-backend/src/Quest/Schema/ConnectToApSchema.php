<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * IP-140: the credential belongs to the lab bundle, never to a quest.
 *
 * Step config is served to every authenticated caller, so a password here is a published
 * password. `ap_id` selects which of the bundle's access points to join; the SSID and the key are
 * read from the bundle at run time, the same rule `ble_advertise` follows for the advertise
 * namespace.
 *
 * Disabled in the palette: this fleet has no access point (AP mode is impossible on the AX210
 * under the Intel LAR firmware limit; illumination is monitor-mode injection), so a
 * connect_to_ap step blocks the run on an association that cannot happen.
 */
final class ConnectToApSchema extends AbstractStepSchema
{
    public const DISABLED_REASON = 'No access point on this fleet; a connect_to_ap step blocks a run.';

    public function type(): QuestStepType
    {
        return QuestStepType::CONNECT_TO_AP;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('ap_id', FieldSpec::KIND_STRING, true, help: 'The id of an access point declared in the lab bundle. SSID and key come from the bundle, never from here.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requireString($config, 'ap_id', $out);

        if (array_key_exists('password', $config)) {
            $this->invalid('password', 'must not be authored into a quest — step config is '
                . 'served to every authenticated caller, so the credential comes from the lab '
                . 'bundle via ap_id', $out);
        }

        if (array_key_exists('ssid', $config)) {
            $this->invalid('ssid', 'is read from the lab bundle, not from the quest — '
                . 'two sources for one SSID means the quest can name a network the handset '
                . 'cannot be given a key for', $out);
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Connect to an access point',
            'summary' => 'Join the bundle access point named by ap_id so the phone can generate traffic for the CSI receivers.',
            'disabled_reason' => self::DISABLED_REASON,
        ];
    }
}
