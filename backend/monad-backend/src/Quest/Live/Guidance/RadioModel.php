<?php

declare(strict_types=1);

namespace App\Quest\Live\Guidance;

/**
 * The floor's radio model: where the nodes are and how the signal falls off.
 *
 * A port of `monad_knowledge/web/lab_atlas.py` (`trilaterate`, `weighted_centroid`), kept in step by
 * a fixture that the Python side computed (`tests/fixtures/live/radio-model.json`). The defaults are
 * the floor atlas fit of `fiit-ground-0`: P0 -62.11 dBm, exponent 1.215, from one walk.
 */
final class RadioModel
{
    public const DEFAULT_P0_DBM = -62.11;
    public const DEFAULT_EXPONENT = 1.215;

    /** @param array<string, array{0: float, 1: float}> $nodes lower-case node key to metres */
    public function __construct(
        public readonly array $nodes,
        public readonly float $p0 = self::DEFAULT_P0_DBM,
        public readonly float $exponent = self::DEFAULT_EXPONENT,
    ) {
    }

    /**
     * Node readings with a known position.
     *
     * @param list<array{node: string, rssi: float}> $medians
     * @return list<array{0: float, 1: float, 2: float}>
     */
    public function observations(array $medians): array
    {
        $out = [];
        foreach ($medians as $m) {
            $at = $this->nodes[strtolower($m['node'])] ?? null;
            if ($at !== null) {
                $out[] = [$at[0], $at[1], $m['rssi']];
            }
        }

        return $out;
    }

    /**
     * Robust least-squares position, or null below three nodes.
     *
     * @param list<array{0: float, 1: float, 2: float}> $observations
     * @return array{0: float, 1: float}|null
     */
    public function locate(array $observations): ?array
    {
        if (count($observations) < 3) {
            return null;
        }
        [$qx, $qy] = self::centroid($observations);
        $here = $this->cost($observations, $qx, $qy);
        $damping = 1e-3;
        for ($i = 0; $i < 60; ++$i) {
            $a11 = $a12 = $a22 = $b1 = $b2 = 0.0;
            foreach ($observations as [$ox, $oy, $rssi]) {
                $range = $this->range($rssi);
                $dx = $qx - $ox;
                $dy = $qy - $oy;
                $dist = hypot($dx, $dy);
                if ($dist < 1e-6) {
                    continue;
                }
                $residual = ($dist - $range) / $range;
                $weight = 1.0 / sqrt(1.0 + $residual * $residual);
                $gx = ($dx / $dist) / $range;
                $gy = ($dy / $dist) / $range;
                $a11 += $weight * $gx * $gx;
                $a12 += $weight * $gx * $gy;
                $a22 += $weight * $gy * $gy;
                $b1 += $weight * $gx * $residual;
                $b2 += $weight * $gy * $residual;
            }
            $m11 = $a11 * (1.0 + $damping);
            $m22 = $a22 * (1.0 + $damping);
            $det = $m11 * $m22 - $a12 * $a12;
            if (abs($det) < 1e-12) {
                break;
            }
            $stepX = ($b1 * $m22 - $b2 * $a12) / $det;
            $stepY = ($m11 * $b2 - $a12 * $b1) / $det;

            $scale = 1.0;
            $moved = false;
            for ($k = 0; $k < 8; ++$k) {
                $nx = $qx - $scale * $stepX;
                $ny = $qy - $scale * $stepY;
                $there = $this->cost($observations, $nx, $ny);
                if ($there < $here) {
                    [$qx, $qy, $here] = [$nx, $ny, $there];
                    $moved = true;
                    break;
                }
                $scale *= 0.4;
            }
            if (!$moved) {
                $damping *= 8.0;
                if ($damping > 1e6) {
                    break;
                }
                continue;
            }
            $damping = max($damping * 0.4, 1e-6);
            if (hypot($scale * $stepX, $scale * $stepY) < 1e-4) {
                break;
            }
        }

        return [$qx, $qy];
    }

    /**
     * How unlike a point the present readings are, in dB. The handset's own power cancels, because
     * the mean difference is removed.
     *
     * @param list<array{0: float, 1: float, 2: float}> $observations
     */
    public function mismatch(array $observations, float $x, float $y): ?float
    {
        $n = count($observations);
        if ($n < 3) {
            return null;
        }
        $diffs = [];
        foreach ($observations as [$ox, $oy, $rssi]) {
            $diffs[] = $rssi - ($this->p0 - 10 * $this->exponent * log10(max(hypot($x - $ox, $y - $oy), 0.5)));
        }
        $mean = array_sum($diffs) / $n;

        return sqrt(array_sum(array_map(static fn (float $d): float => ($d - $mean) ** 2, $diffs)) / $n);
    }

    /**
     * @param list<array{0: float, 1: float, 2: float}> $observations
     * @return array{0: float, 1: float}
     */
    private static function centroid(array $observations): array
    {
        $total = $x = $y = 0.0;
        foreach ($observations as [$ox, $oy, $rssi]) {
            $w = 10 ** ($rssi / 20);
            $total += $w;
            $x += $w * $ox;
            $y += $w * $oy;
        }

        return [$x / $total, $y / $total];
    }

    /** @param list<array{0: float, 1: float, 2: float}> $observations */
    private function cost(array $observations, float $x, float $y): float
    {
        $total = 0.0;
        foreach ($observations as [$ox, $oy, $rssi]) {
            $range = $this->range($rssi);
            $residual = (hypot($x - $ox, $y - $oy) - $range) / $range;
            $total += 2.0 * (sqrt(1.0 + $residual * $residual) - 1.0);
        }

        return $total;
    }

    private function range(float $rssi): float
    {
        return max(10 ** (($this->p0 - $rssi) / (10 * $this->exponent)), 0.25);
    }
}
