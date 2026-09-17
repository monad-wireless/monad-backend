<?php

declare(strict_types=1);

namespace App\Quest\Schema;

use App\Enum\QuestStepType;

/**
 * IP-140: a probe names the surveyed points it will accept, and how long to stand there.
 *
 * `targets` is validated structurally rather than against a table here: the authority for which
 * codes exist is PostGIS on the monad-knowledge side, mirrored into `lab_placements` (IP-157).
 * Whether a named target is actually placed is the placement board's drift verdict and
 * `lab quest-check`, not this schema.
 *
 * `kind` is closed. A dwell at a node sticker sits at zero distance from one end of every link
 * that node terminates, which is the degenerate corner of the geometry; a dwell at a marker card
 * samples open floor. Pooling the two produces an uninterpretable statistic, so the tag has to be
 * present and has to be one of two values.
 */
final class ProbeSchema extends AbstractStepSchema
{
    public const KINDS = ['card', 'node'];

    public function type(): QuestStepType
    {
        return QuestStepType::PROBE;
    }

    public function fields(): array
    {
        return [
            $this->descriptionField(),
            new FieldSpec('dwell_seconds', FieldSpec::KIND_INT, true, min: 1, help: 'How long the participant holds still after the matching scan.'),
            new FieldSpec('targets', FieldSpec::KIND_TARGETS, true, min: 1, help: 'The surveyed points this probe accepts, each {value, label, room, kind}; kind is card or node. Pick them from the placement board.'),
        ];
    }

    public function validate(array $config): array
    {
        $out = [];
        $this->optionalDescription($config, $out);
        $this->requirePositiveInteger($config, 'dwell_seconds', $out);

        if (!isset($config['targets'])) {
            $this->missing('targets', $out);

            return $out;
        }

        if (!is_array($config['targets']) || $config['targets'] === []) {
            $this->invalid('targets', 'must be a non-empty list of targets', $out);

            return $out;
        }

        foreach (array_values($config['targets']) as $index => $target) {
            if (!is_array($target)) {
                $this->invalid(sprintf('targets[%d]', $index), 'must be an object', $out);
                continue;
            }

            foreach (['value', 'label', 'room'] as $field) {
                $this->requireString($target, $field, $out);
            }

            if (!isset($target['kind']) || !in_array($target['kind'], self::KINDS, true)) {
                $this->invalid(sprintf('targets[%d].kind', $index), 'must be one of: card, node', $out);
            }
        }

        return $out;
    }

    public function palette(): array
    {
        return [
            'title' => 'Probe',
            'summary' => 'Scan one of the listed surveyed points, then hold still for the dwell. One target is a treasure-hunt leg; many make a fingerprint probe. Needs features.broadcast on the start step to record anything on air.',
            'disabled_reason' => null,
        ];
    }
}
