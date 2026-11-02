<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Media\Videos;
use Frootbox\Core\Tests\Support\VideoFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Size and codec come from the file, the codec in the form browsers
 * expect in <source type> (decision 0177).
 */
#[CoversClass(Videos::class)]
final class VideosTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'fb');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsMp4WithTheCodecOfH264Hevc(): void
    {
        VideoFiles::mp4($this->file, 1920, 1080);
        self::assertTrue(Videos::isVideo($this->file));
        self::assertSame(['codec' => 'avc1.64001F', 'width' => 1920, 'height' => 1080], Videos::facts($this->file, 'video/mp4'));

        VideoFiles::mp4($this->file, 1280, 720, 'hvc1', 'hvcC', VideoFiles::HEVC);
        self::assertSame(['codec' => 'hvc1.1.6.L93.B0', 'width' => 1280, 'height' => 720], Videos::facts($this->file, 'video/mp4'));

        VideoFiles::mp4($this->file, 640, 360, 'av01', 'av1C', VideoFiles::AV1);
        self::assertSame(['codec' => 'av01.0.08M.08', 'width' => 640, 'height' => 360], Videos::facts($this->file, 'video/mp4'));

        // Without a known configuration box the kind of the entry is all there is.
        VideoFiles::mp4($this->file, 640, 360, 'mp4v', 'esds', "\0\0\0\0");
        self::assertSame('mp4v', Videos::facts($this->file, 'video/mp4')['codec']);
    }

    public function testReadsWebm(): void
    {
        VideoFiles::webm($this->file, 640, 360);
        self::assertTrue(Videos::isVideo($this->file));
        self::assertSame(['codec' => 'vp9', 'width' => 640, 'height' => 360], Videos::facts($this->file, 'video/webm'));

        VideoFiles::webm($this->file, 320, 240, 'V_VP8', str_repeat('x', 5000));
        self::assertSame(['codec' => 'vp8', 'width' => 320, 'height' => 240], Videos::facts($this->file, 'video/webm'));
    }

    public function testKnowsNothingOfOtherFiles(): void
    {
        file_put_contents($this->file, str_repeat('nonsense', 100));

        self::assertFalse(Videos::isVideo($this->file));
        self::assertSame(['codec' => '', 'width' => 0, 'height' => 0], Videos::facts($this->file, 'video/mp4'));
        self::assertSame(['codec' => '', 'width' => 0, 'height' => 0], Videos::facts($this->file, 'video/webm'));
    }

    public function testRanksTheMostEfficientCodecFirstAndH264Last(): void
    {
        $codecs = ['avc1.64001F', '', 'vp9', 'av01.0.08M.08', 'hvc1.1.6.L93.B0'];
        usort($codecs, static fn(string $one, string $other): int => Videos::rank($one) <=> Videos::rank($other));

        self::assertSame(['av01.0.08M.08', 'hvc1.1.6.L93.B0', 'vp9', 'avc1.64001F', ''], $codecs);
        self::assertSame('video/mp4; codecs="avc1.64001F"', Videos::sourceType('video/mp4', 'avc1.64001F'));
        self::assertSame('video/webm', Videos::sourceType('video/webm', ''));
    }
}
