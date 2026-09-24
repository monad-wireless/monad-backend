<?php

namespace App\Twig;

use App\Service\MarkerService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What the placement board (IP-157) needs from Twig, and what the Overview needs from the board.
 *
 * `placement_mismatch_count()` is the one number the Overview shows: how many values a LIVE quest
 * names that fold to no mirrored card or node. It is computed on call from `MarkerService` —
 * a join result kept in a third place goes stale between the two it summarises — and it is a
 * function rather than a controller variable so the dashboard template can ask for it without
 * `DashboardController::index` growing a dependency.
 *
 * `ago` prints an instant's age in plain words ("3 h ago"), the form the sync line uses. The
 * exact instant stays beside it through the `clock` filter, so the words never replace the fact.
 */
final class PlacementExtension extends AbstractExtension
{
    public function __construct(
        private readonly MarkerService $markers,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('placement_mismatch_count', [$this, 'mismatchCount']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', [$this, 'ago']),
        ];
    }

    /** Values live quests name that no mirrored placement (any floor) folds to. */
    public function mismatchCount(): int
    {
        return count($this->markers->driftReport()['mismatch']);
    }

    /** `just now`, `12 min ago`, `3 h ago`, `2 d ago`; `never` for nothing. */
    public function ago(?\DateTimeInterface $instant, ?\DateTimeInterface $now = null): string
    {
        if ($instant === null) {
            return 'never';
        }
        $now ??= new \DateTimeImmutable();
        $seconds = max(0, $now->getTimestamp() - $instant->getTimestamp());

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return sprintf('%d min ago', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf('%d h ago', intdiv($seconds, 3600));
        }

        return sprintf('%d d ago', intdiv($seconds, 86400));
    }
}
