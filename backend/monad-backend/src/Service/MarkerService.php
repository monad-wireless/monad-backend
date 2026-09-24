<?php

namespace App\Service;

use App\Entity\LabPlacement;
use App\Entity\Quest;
use App\Entity\QuestStep;
use App\Enum\QuestStepType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;

/**
 * Scan markers, rendered on demand and derived from the quests that reference them.
 *
 * There is no marker table and no marker list to maintain. A marker exists because some quest
 * step asks a participant to scan it: the app matches a scan by comparing the scanned text to the
 * step's target (QrCodeStep.kt / ProbeStep.kt, case-insensitive), so that string is the entire
 * contract. Anything that keeps a second list of markers can disagree with the quests, and the
 * failure mode is a code that scans fine and silently never advances the step.
 *
 * So markers are a projection of the quest set. Add a scan step, the marker appears; change the
 * target, the printable sheet changes with it.
 *
 * Two step types reach a card and both are projected (IP-140): `scan_qr` names one
 * `expected_value`, and `probe` names a list of `targets`. Projecting only the first would leave a
 * probe's cards answering "unknown marker" on `/m/<code>` while working perfectly in the app.
 *
 * IP-157 adds the other half of the join: `lab_placements` says where a card or node IS, and
 * `driftReport()` compares the two lists. The mirror is never the marker list; it is the
 * position record the verdicts are computed against.
 */
class MarkerService
{
    private const SIZE = 600;

    /**
     * The one host a printed card resolves to. The same constant the app (`ProbeConfig.codeKey`),
     * the portal (`marker_key`) and `monad-knowledge lab quest-check` (`check.py:code_key`) fold
     * against: a URL on any other host is not a lab code and folds to nothing.
     */
    public const PORTAL_HOST = 'monad.dubec.dev';

    public const VERDICT_MATCHED = 'matched';
    public const VERDICT_SPARE = 'spare';
    public const VERDICT_MISMATCH = 'mismatch';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every distinct marker the current quests ask for, with the quests that ask.
     *
     * Quests are described rather than named (IP-129 §2.4): the portal's `/m/<code>` page tells
     * whoever scanned a card what it is for, and "EXP-C1 Day 1" alone says nothing to a stranger.
     * The id travels with the name so that page can link to the quest rather than re-resolve it
     * by a name that is not a key.
     *
     * @return list<array{
     *     value: string,
     *     label: string,
     *     quests: list<array{id: string, name: string, description: ?string, points: float, estimated_duration: ?int}>
     * }>
     */
    public function markers(): array
    {
        $markers = [];
        foreach ($this->scanSteps() as $step) {
            $quest = $step->getQuest();
            $questId = (string) $quest?->getId();

            foreach (self::scannedValues($step) as $value => $label) {
                $markers[$value] ??= ['value' => $value, 'label' => $label, 'quests' => []];

                if ($quest === null || $questId === '') {
                    continue;
                }

                // Dedup on the id, not the name: one quest can scan the same card twice (in and
                // out of a leg), and two quests are allowed to share a name.
                if (isset($markers[$value]['quests'][$questId])) {
                    continue;
                }

                $markers[$value]['quests'][$questId] = [
                    'id' => $questId,
                    'name' => (string) $quest->getName(),
                    'description' => $quest->getDescription(),
                    'points' => $quest->getPoints(),
                    'estimated_duration' => $quest->getEstimatedDuration(),
                ];
            }
        }

        // The quest map is keyed by id only to dedup; callers get a list.
        return array_values(array_map(static function (array $marker): array {
            $marker['quests'] = array_values($marker['quests']);

            return $marker;
        }, $markers));
    }

    /**
     * Every code one step can be satisfied by, keyed by the value, valued by its printable label.
     *
     * Two step types reach a printed card and they name it differently. `scan_qr` carries one
     * `expected_value` and the step's own name is the only label there is. A `probe` (IP-140)
     * carries a list of targets, each already resolved to a label and a room by the generator that
     * read them out of PostGIS — so a probe's label is the target's, not the step's, because one
     * step legitimately accepts twenty cards.
     *
     * Both must appear here. The marker index is what `/m/<code>` reads to tell someone holding a
     * card what it is for, and a card named only by a probe would answer "unknown marker" while
     * working perfectly in the app.
     *
     * Public because it is the whole of the projection rule and it is a pure function of one step.
     * `markers()` itself needs a database; this does not, so it is the part that can be pinned by
     * a unit test rather than by an integration one.
     *
     * @return array<string, string>
     */
    public static function scannedValues(QuestStep $step): array
    {
        $config = $step->getConfig();
        $stepLabel = $step->getName() ?? '';

        if ($step->getType() === QuestStepType::PROBE) {
            $values = [];
            foreach ((array) ($config['targets'] ?? []) as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $value = trim((string) ($target['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $label = trim((string) ($target['label'] ?? ''));
                $values[$value] = $label !== '' ? $label : ($stepLabel !== '' ? $stepLabel : $value);
            }

            return $values;
        }

        if ($step->getType() !== QuestStepType::SCAN_QR) {
            // Exactly two step types put a participant in front of a printed code. Everything else
            // projects nothing, even if it happens to carry an `expected_value` key — otherwise a
            // stray config field grows a sheet entry no card corresponds to. `markers()` restricts
            // the query to the same two types, so this guard only matters to a direct caller; it
            // is here because the rule belongs to the rule, not to the query that happens to obey.
            return [];
        }

        $value = (string) ($config['expected_value'] ?? '');
        if ($value === '') {
            // A scan step with no expected value matches any code at all. That is a real
            // authoring mistake, but it is not a marker, so it cannot appear on a sheet.
            return [];
        }

        return [$value => $stepLabel !== '' ? $stepLabel : $value];
    }

    // ── IP-157: the mirror and the drift verdicts ────────────────────────────────────────────

    /**
     * One card's identity, whichever form it was written in.
     *
     * A port of `monad-knowledge lab/check.py:code_key`, kept in step with it and with the
     * handset's `ProbeConfig.codeKey` deliberately: lowercase, query and fragment stripped,
     * trailing slash removed, trailing path segment taken, and a URL is a code only when its host
     * is the portal. `https://monad.dubec.dev/m/MONAD-FP-07`, `/d/monad04` and the bare
     * `monad-fp-07` all fold to the mirror key; `https://example.org/m/MONAD-FP-07` folds to ''.
     * A checker that folded differently from the app would pass a quest the app cannot match.
     */
    public static function codeKey(string $raw): string
    {
        $s = trim($raw);
        $s = explode('?', $s, 2)[0];
        $s = explode('#', $s, 2)[0];
        $s = rtrim($s, '/');
        if ($s === '') {
            return '';
        }
        if (str_contains($s, '://')) {
            $s = explode('://', $s, 2)[1];
            $slash = strpos($s, '/');
            $host = $slash === false ? $s : substr($s, 0, $slash);
            $rest = $slash === false ? '' : substr($s, $slash + 1);
            if (strtolower($host) !== self::PORTAL_HOST) {
                return '';
            }
            $s = $rest;
        }
        $segments = explode('/', $s);

        return strtolower((string) end($segments));
    }

    /**
     * The payload a card carries when no quest has named it yet: the portal's `/m/<key>` grammar,
     * which is what the printed set uses (MarkerController). A matched card prints the string its
     * quest names instead, so the sheet and the step agree byte for byte.
     */
    public static function cardPayload(string $key): string
    {
        return 'https://' . self::PORTAL_HOST . '/m/' . $key;
    }

    /**
     * Where a quest stands relative to `$now`, as one word: `scheduled`, `hidden` or `live`.
     *
     * The single window rule. `lab_quest_list` reports it, and the drift verdicts count only
     * `live` quests, so it lives here rather than once per caller. Not-yet-open beats
     * already-closed on purpose: a window that opens after it closes says "nobody can run this",
     * which `scheduled` also says, while `live` would invite somebody to try.
     */
    public static function questStatus(Quest $quest, \DateTimeImmutable $now): string
    {
        $from = $quest->getAvailableFrom();
        $to = $quest->getAvailableTo();

        if ($from !== null && $from > $now) {
            return 'scheduled';
        }
        if ($to !== null && $to < $now) {
            return 'hidden';
        }

        return 'live';
    }

    /**
     * The mirror against the live quest set.
     *
     * The same rule as `monad-knowledge lab quest-check`, computed on demand and stored nowhere:
     * a join result kept in a third place goes stale between the two it summarises. One floor
     * when named, every floor when not (the Overview counter).
     *
     * @return array{
     *     matched: list<array{key: string, kind: string, room: ?string, floor: string, values: list<string>, quests: list<array{id: string, name: string, status: string}>}>,
     *     spare: list<array{key: string, kind: string, room: ?string, floor: string}>,
     *     mismatch: list<array{key: string, values: list<string>, quests: list<array{id: string, name: string, status: string}>}>,
     *     historical: list<array{key: string, values: list<string>, in_mirror: bool, quests: list<array{id: string, name: string, status: string}>}>,
     *     synced_at: ?\DateTimeImmutable
     * }
     */
    public function driftReport(?string $floor = null): array
    {
        $repository = $this->entityManager->getRepository(LabPlacement::class);
        $placements = $floor === null
            ? $repository->findBy([], ['floor' => 'ASC', 'kind' => 'ASC', 'key' => 'ASC'])
            : $repository->findByFloor($floor);

        return self::reconcile($placements, $this->scanSteps(), new \DateTimeImmutable());
    }

    /**
     * The drift rule as a pure function, so it can be pinned without a database.
     *
     * Three verdicts and one side list:
     *
     *  - `matched`: a mirror row some LIVE quest names (by folded key, case-insensitive).
     *  - `spare`: a mirror row no live quest names. Normal, not a fault: the fingerprint pool is
     *    meant to outlive any one arm.
     *  - `mismatch`: a value a live quest names that folds to no mirror row. The dangerous one —
     *    a participant will stand in a corridor scanning something that is not there.
     *  - `historical`: values named only by quests that are not live (hidden or scheduled). Kept
     *    out of `mismatch` so an old duplicate such as a bare `MONAD-SHOWCASE-IN` in a retired
     *    quest does not read as a fault tonight. `in_mirror` says whether the card still exists.
     *
     * A folded key is the identity: `https://monad.dubec.dev/m/MONAD-FP-07` and `monad-fp-07`
     * name the same card. The exact strings named travel in `values`, because the print sheet
     * renders what the quest names, not what the mirror calls it.
     *
     * @param list<LabPlacement> $placements
     * @param list<QuestStep> $steps scan_qr and probe steps, each attached to its quest
     * @return array{matched: list<array<string, mixed>>, spare: list<array<string, mixed>>, mismatch: list<array<string, mixed>>, historical: list<array<string, mixed>>, synced_at: ?\DateTimeImmutable}
     */
    public static function reconcile(array $placements, array $steps, \DateTimeImmutable $now): array
    {
        // Folded key -> {values: [exact strings], quests: {id: {...}}}, split by liveness.
        /** @var array{live: array<string, array{values: array<string, true>, quests: array<string, array{id: string, name: string, status: string}>}>, other: array<string, array{values: array<string, true>, quests: array<string, array{id: string, name: string, status: string}>}>} $named */
        $named = ['live' => [], 'other' => []];

        foreach ($steps as $step) {
            $quest = $step->getQuest();
            if ($quest === null) {
                continue;
            }
            $status = self::questStatus($quest, $now);
            $bucket = $status === 'live' ? 'live' : 'other';
            $questRow = ['id' => (string) $quest->getId(), 'name' => (string) $quest->getName(), 'status' => $status];

            foreach (array_keys(self::scannedValues($step)) as $value) {
                $value = (string) $value;
                $key = self::codeKey($value);
                if ($key === '') {
                    continue;
                }
                $named[$bucket][$key] ??= ['values' => [], 'quests' => []];
                $named[$bucket][$key]['values'][$value] = true;
                $named[$bucket][$key]['quests'][$questRow['id']] = $questRow;
            }
        }

        $live = $named['live'];
        $other = $named['other'];

        $matched = [];
        $spare = [];
        $syncedAt = null;
        $mirrorKeys = [];

        foreach ($placements as $placement) {
            $key = self::codeKey($placement->getKey());
            $mirrorKeys[$key] = true;
            if ($syncedAt === null || $placement->getSyncedAt() > $syncedAt) {
                $syncedAt = $placement->getSyncedAt();
            }

            $row = [
                'key' => $placement->getKey(),
                'kind' => $placement->getKind(),
                'room' => $placement->getRoom(),
                'floor' => $placement->getFloor(),
            ];
            if (isset($live[$key])) {
                $row['values'] = array_keys($live[$key]['values']);
                $row['quests'] = array_values($live[$key]['quests']);
                $matched[] = $row;
            } else {
                $spare[] = $row;
            }
        }

        $mismatch = [];
        foreach ($live as $key => $entry) {
            if (isset($mirrorKeys[$key])) {
                continue;
            }
            $mismatch[] = [
                'key' => $key,
                'values' => array_keys($entry['values']),
                'quests' => array_values($entry['quests']),
            ];
        }

        $historical = [];
        foreach ($other as $key => $entry) {
            $historical[] = [
                'key' => $key,
                'values' => array_keys($entry['values']),
                'in_mirror' => isset($mirrorKeys[$key]),
                'quests' => array_values($entry['quests']),
            ];
        }

        usort($mismatch, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        usort($historical, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return [
            'matched' => $matched,
            'spare' => $spare,
            'mismatch' => $mismatch,
            'historical' => $historical,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * Every scan_qr and probe step with its quest, the query both projections share.
     *
     * @return list<QuestStep>
     */
    private function scanSteps(): array
    {
        return $this->entityManager->createQuery(
            'SELECT s, q FROM App\Entity\QuestStep s JOIN s.quest q WHERE s.type IN (:types) ORDER BY q.name, s.order'
        )->setParameter('types', [QuestStepType::SCAN_QR, QuestStepType::PROBE])->getResult();
    }

    // ── rendering ────────────────────────────────────────────────────────────────────────────

    /**
     * Error correction H, deliberately: these are taped to a doorframe and scanned in a hurry, at
     * an angle, sometimes with a thumb across a corner. The redundancy costs nothing on a payload
     * this short.
     */
    public function svg(string $value): string
    {
        return $this->render(new SvgWriter(), $value);
    }

    public function png(string $value): string
    {
        return $this->render(new PngWriter(), $value);
    }

    /** endroid/qr-code 6 replaced the static fluent builder with a named-argument constructor. */
    private function render(WriterInterface $writer, string $value): string
    {
        return (new Builder(
            writer: $writer,
            data: $value,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: self::SIZE,
            margin: 16,
        ))->build()->getString();
    }

    /** True when some quest actually asks for this value — the guard on the public render route. */
    public function isKnown(string $value): bool
    {
        foreach ($this->markers() as $marker) {
            if (strcasecmp($marker['value'], $value) === 0) {
                return true;
            }
        }

        return false;
    }
}
