<?php

namespace App\Tests\Service;

use App\Service\WalkFigureUrlSigner;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wire format monad-knowledge web verifies (`tests/web/test_internal_walk.py` on the other side).
 */
class WalkFigureUrlSignerTest extends TestCase
{
    public function testUnconfiguredYieldsNoUrlAndAReason(): void
    {
        $signer = new WalkFigureUrlSigner();
        self::assertFalse($signer->isConfigured());
        self::assertNull($signer->figureUrl('p', 's', 'trajectory'));
        self::assertNull($signer->infoUrl('p', 's'));
        self::assertStringContainsString('MONAD_WALK_FIGURE_KEY', $signer->unconfiguredReason());
    }

    public function testPayloadFormatIsStable(): void
    {
        self::assertSame('p-1/s-1/trajectory||1757001600', WalkFigureUrlSigner::payload('p-1', 's-1', 'trajectory', null, 1_757_001_600));
        self::assertSame('p-1/s-1/site|fiit-ground-0|1757001600', WalkFigureUrlSigner::payload('p-1', 's-1', 'site', 'fiit-ground-0', 1_757_001_600));
    }

    public function testSignedUrlCarriesExpiryAndHmac(): void
    {
        $signer = new WalkFigureUrlSigner('http://monad-web.monad.internal:8083/', 'k');
        $url = $signer->figureUrl('p-1', 's-1', 'trajectory', null, 1_757_000_000);
        self::assertNotNull($url);

        $expectedSig = hash_hmac('sha256', 'p-1/s-1/trajectory||1757003600', 'k');
        self::assertSame(
            'http://monad-web.monad.internal:8083/internal/walk/p-1/s-1/trajectory.png?exp=1757003600&sig=' . $expectedSig,
            $url,
        );
    }

    public function testFloorIsPartOfTheSignature(): void
    {
        $signer = new WalkFigureUrlSigner('http://web', 'k');
        $url = $signer->figureUrl('p', 's', 'site', 'fiit-ground-0', 1_757_000_000);
        self::assertNotNull($url);
        self::assertStringStartsWith('http://web/internal/walk/p/s/site.png?floor=fiit-ground-0&exp=1757003600&sig=', $url);
        self::assertStringEndsWith(hash_hmac('sha256', 'p/s/site|fiit-ground-0|1757003600', 'k'), $url);
    }

    public function testUnknownViewIsRefused(): void
    {
        $signer = new WalkFigureUrlSigner('http://web', 'k');
        self::assertNull($signer->figureUrl('p', 's', 'heatmap'));
    }

    public function testInfoUrlSignsAsTheInfoView(): void
    {
        $signer = new WalkFigureUrlSigner('http://web', 'k');
        $url = $signer->infoUrl('p', 's', 1_757_000_000);
        self::assertSame(
            'http://web/internal/walk/p/s/info.json?exp=1757003600&sig=' . hash_hmac('sha256', 'p/s/info||1757003600', 'k'),
            $url,
        );
    }
}
