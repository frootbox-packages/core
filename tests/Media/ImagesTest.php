<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Media\Images;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every format the driver claims really comes out: some builds of GD
 * claim AVIF without an encoder and wrote empty files (decision 0173).
 */
#[CoversClass(Images::class)]
final class ImagesTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'fb');
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 31, 47, (int) imagecolorallocate($image, 200, 120, 40));
        imagejpeg($image, $this->file, 90);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testWritesEveryFormatItSupports(): void
    {
        $images = new Images();

        foreach (['avif', 'webp', 'jpg', 'png'] as $extension) {
            if ($images->supports($extension)) {
                self::assertNotSame('', $images->render($this->file, 32, 0, 500, 500, $extension), $extension);
            }
        }

        self::assertTrue($images->supports('jpg'));
        self::assertFalse($images->supports('gif'));
    }

    public function testShrinksAnOriginalToALongerSide(): void
    {
        // Decision 0328: at most the longer side asked for, never larger.
        $images = new Images();
        $target = $this->file . '.out';

        try {
            self::assertSame([32, 24], $this->dimensions($images->prepare($this->file, $target, 32)));
            self::assertSame(32, Images::longerSide($this->file, 32));
            self::assertSame([64, 48], $this->dimensions($images->prepare($this->file, $target, 100)));
            self::assertSame(64, Images::longerSide($this->file, 100));
            self::assertSame([64, 48], $this->dimensions($images->prepare($this->file, $target)));
            self::assertSame(64, Images::longerSide($this->file));
            self::assertNull(Images::longerSide(__FILE__, 32));
        } finally {
            @unlink($target);
        }
    }

    public function testNamesItsDriver(): void
    {
        // Decision 0334: the screen of the shop sync shows it.
        self::assertMatchesRegularExpression(\extension_loaded('imagick') ? '/^Imagick/' : '/^GD/', Images::describe());
    }

    /**
     * @param array<string, mixed>|object $facts
     *
     * @return list<mixed>
     */
    private function dimensions(array|object $facts): array
    {
        self::assertIsArray($facts);

        return [$facts['width'], $facts['height']];
    }
}
