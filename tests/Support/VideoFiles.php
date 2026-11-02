<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

/**
 * Tiny video files for tests, built by hand: only the boxes and elements
 * that tell the type, the size and the codec. No browser plays them.
 */
final class VideoFiles
{
    /** avcC of H.264 High at level 3.1. */
    public const string AVC = "\x01\x64\x00\x1F\xFF\xE1";

    /** hvcC of HEVC Main at level 3.1 (93). */
    public const string HEVC = "\x01\x01\x60\x00\x00\x00\xB0\x00\x00\x00\x00\x00\x5D";

    /** av1C of AV1 Main at level 4.0, 8 bit. */
    public const string AV1 = "\x81\x08\x0C\x00";

    /**
     * An MP4 file with one video track.
     *
     * @param string $kind   The sample entry, e.g. "avc1"
     * @param string $config The box in the entry, e.g. "avcC"
     */
    public static function mp4(string $path, int $width, int $height, string $kind = 'avc1', string $config = 'avcC', string $data = self::AVC, string $padding = ''): string
    {
        // Version, flags, times, track, duration, layers, matrix: 76 bytes, then width and height.
        $tkhd = self::box('tkhd', str_repeat("\0", 76) . pack('NN', $width << 16, $height << 16));
        $entry = self::box($kind, str_repeat("\0", 78) . self::box($config, $data));
        $stsd = self::box('stsd', pack('NN', 0, 1) . $entry);
        $trak = self::box('trak', $tkhd . self::box('mdia', self::box('minf', self::box('stbl', $stsd))));
        $file = self::box('ftyp', 'isom' . pack('N', 512) . 'isomiso2avc1mp41') . self::box('moov', $trak) . self::box('mdat', $padding);
        file_put_contents($path, $file);

        return $path;
    }

    /**
     * A WebM file with one video track.
     */
    public static function webm(string $path, int $width, int $height, string $codec = 'V_VP9', string $padding = ''): string
    {
        $header = self::element(0x1A45DFA3, self::element(0x4282, 'webm') . self::element(0x4287, "\x04") . self::element(0x4285, "\x02"));
        $video = self::element(0xE0, self::element(0xB0, pack('n', $width)) . self::element(0xBA, pack('n', $height)));
        $track = self::element(0xAE, self::element(0xD7, "\x01") . self::element(0x83, "\x01") . self::element(0x86, $codec) . $video);
        $segment = self::element(0x18538067, self::element(0x1654AE6B, $track) . self::element(0x1F43B675, self::element(0xE7, "\x00") . $padding));
        file_put_contents($path, $header . $segment);

        return $path;
    }

    private static function box(string $kind, string $body): string
    {
        return pack('N', 8 + \strlen($body)) . $kind . $body;
    }

    private static function element(int $id, string $body): string
    {
        $bytes = '';

        for ($rest = $id; $rest > 0; $rest >>= 8) {
            $bytes = \chr($rest & 0xFF) . $bytes;
        }

        // Short lengths in one byte, as file(1) expects for the type; else in eight.
        $length = \strlen($body) < 127 ? \chr(0x80 | \strlen($body)) : "\x01" . substr(pack('J', \strlen($body)), 1);

        return $bytes . $length . $body;
    }
}
