<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Support\Message;
use Generator;

/**
 * Video files of the media library (decision 0177): MP4 and WebM, kept
 * as uploaded, as normal webspace has no ffmpeg. Width, height and codec
 * come from the file itself: in MP4 from the track header and the sample
 * description, in WebM from the video track. The codec is the string a
 * browser expects in <source type>, e.g. "avc1.64001F" or "vp9"; what
 * cannot be found stays 0 or "".
 */
final class Videos
{
    /** Media types that can be uploaded, with the extension of their files. */
    public const array TYPES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    /** Order of the sources, the most efficient codec first and H.264 as fallback last. */
    private const array PREFERENCE = ['av01', 'hvc1', 'hev1', 'vp09', 'vp9', 'vp8', 'avc1', 'avc3'];

    private const int WEBM_SEGMENT = 0x18538067;

    private const int WEBM_TRACKS = 0x1654AE6B;

    private const int WEBM_TRACK = 0xAE;

    private const int WEBM_VIDEO = 0xE0;

    private const int WEBM_CODEC = 0x86;

    private const int WEBM_PRIVATE = 0x63A2;

    private const int WEBM_WIDTH = 0xB0;

    private const int WEBM_HEIGHT = 0xBA;

    /**
     * Whether a file is a video of a type that can be uploaded.
     */
    public static function isVideo(string $path): bool
    {
        return isset(self::TYPES[self::type($path)]);
    }

    public static function isVideoType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function notAVideo(): Message
    {
        return new Message('The file is no video of type MP4 or WebM. Save it as MP4 with H.264, all browsers play that.');
    }

    /**
     * The value of <source type>, with the codec if known.
     */
    public static function sourceType(string $type, string $codec): string
    {
        return '' === $codec ? $type : \sprintf('%s; codecs="%s"', $type, $codec);
    }

    /**
     * Where a source goes among the others: lower comes first.
     */
    public static function rank(string $codec): int
    {
        $family = strtolower(explode('.', $codec)[0]);
        $rank = array_search($family, self::PREFERENCE, true);

        return false === $rank ? \count(self::PREFERENCE) : $rank;
    }

    /**
     * Copies a video as original.
     *
     * @return array{type: string, codec: string, width: int, height: int, size: int}|Message The facts or why not
     */
    public function prepare(string $source, string $target): array|Message
    {
        $type = self::type($source);

        if (!isset(self::TYPES[$type])) {
            return self::notAVideo();
        }

        $folder = \dirname($target);

        if ((!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) || !@copy($source, $target)) {
            return new Message('The video could not be saved.');
        }

        return ['type' => $type, ...self::facts($target, $type), 'size' => (int) filesize($target)];
    }

    /**
     * Codec, width and height of a video. It reads the file, so the same
     * path may give another answer later.
     *
     * @phpstan-impure
     *
     * @return array{codec: string, width: int, height: int}
     */
    public static function facts(string $path, string $type): array
    {
        $handle = @fopen($path, 'rb');
        $facts = null;

        if (false !== $handle) {
            try {
                $size = (int) filesize($path);
                $facts = 'video/webm' === $type ? self::webm($handle, $size) : self::mp4($handle, $size);
            } finally {
                fclose($handle);
            }
        }

        return $facts ?? ['codec' => '', 'width' => 0, 'height' => 0];
    }

    private static function type(string $path): string
    {
        return (string) new \finfo(\FILEINFO_MIME_TYPE)->file($path);
    }

    /**
     * The first track with a picture.
     *
     * @param resource $handle
     *
     * @return array{codec: string, width: int, height: int}|null
     */
    private static function mp4($handle, int $size): ?array
    {
        foreach (self::boxes($handle, 0, $size) as [$kind, $body, $end]) {
            if ('moov' !== $kind) {
                continue;
            }

            foreach (self::boxes($handle, $body, $end) as [$inner, $start, $stop]) {
                $track = 'trak' === $inner ? self::track($handle, $start, $stop) : null;

                if (null !== $track) {
                    return $track;
                }
            }
        }

        return null;
    }

    /**
     * @param resource $handle
     *
     * @return array{codec: string, width: int, height: int}|null
     */
    private static function track($handle, int $start, int $end): ?array
    {
        $width = 0;
        $height = 0;
        $codec = '';
        $path = ['mdia', 'minf', 'stbl'];
        $levels = [[$start, $end]];

        while ([] !== $levels) {
            [$from, $to] = array_shift($levels);

            foreach (self::boxes($handle, $from, $to) as [$kind, $body, $stop]) {
                if ('tkhd' === $kind) {
                    [$width, $height] = self::trackSize(self::read($handle, $body, min(120, $stop - $body)));
                } elseif ('stsd' === $kind) {
                    $codec = self::sampleCodec(self::read($handle, $body, min(4096, $stop - $body)));
                } elseif (\in_array($kind, $path, true)) {
                    $levels[] = [$body, $stop];
                }
            }
        }

        return $width > 0 && $height > 0 ? ['codec' => $codec, 'width' => $width, 'height' => $height] : null;
    }

    /**
     * The boxes between $start and $end: kind, start of the body, end.
     *
     * @param resource $handle
     *
     * @return Generator<int, array{string, int, int}>
     */
    private static function boxes($handle, int $start, int $end): Generator
    {
        $position = $start;

        while ($position + 8 <= $end) {
            $header = self::read($handle, $position, 16);

            if (\strlen($header) < 8) {
                return;
            }

            $length = self::uint32(substr($header, 0, 4));
            $body = $position + 8;

            if (1 === $length && \strlen($header) >= 16) {
                $length = self::uint32(substr($header, 8, 4)) * 4_294_967_296 + self::uint32(substr($header, 12, 4));
                $body += 8;
            } elseif (0 === $length) {
                $length = $end - $position;
            }

            if ($length < $body - $position) {
                return;
            }

            yield [substr($header, 4, 4), $body, min($end, $position + $length)];
            $position += $length;
        }
    }

    /**
     * Width and height at the end of a track header, as 16.16 numbers.
     *
     * @return array{int, int}
     */
    private static function trackSize(string $data): array
    {
        // Version 1 has 64-bit times and is 12 bytes longer.
        $offset = 1 === \ord($data[0] ?? "\0") ? 88 : 76;

        if (\strlen($data) < $offset + 8) {
            return [0, 0];
        }

        return [self::uint32(substr($data, $offset, 4)) >> 16, self::uint32(substr($data, $offset + 4, 4)) >> 16];
    }

    /**
     * The codec of the first entry of a sample description: its kind and
     * the details from its configuration box.
     */
    private static function sampleCodec(string $data): string
    {
        // Version, flags and count, then the entry: size and kind.
        if (\strlen($data) < 16) {
            return '';
        }

        $entryEnd = min(\strlen($data), 8 + self::uint32(substr($data, 8, 4)));
        $kind = substr($data, 12, 4);

        if (1 !== preg_match('/^[a-z0-9]{4}$/', $kind)) {
            return '';
        }

        // A visual sample entry has 78 bytes of fields before its boxes.
        $position = 8 + 8 + 78;

        while ($position + 8 <= $entryEnd) {
            $length = self::uint32(substr($data, $position, 4));
            $box = substr($data, $position + 4, 4);
            $config = substr($data, $position + 8, max(0, $length - 8));

            $codec = match ($box) {
                'avcC' => self::avc($kind, $config),
                'hvcC' => self::hevc($kind, $config),
                'av1C' => self::av1($config),
                'vpcC' => self::vp9($config),
                default => null,
            };

            if (null !== $codec) {
                return $codec;
            }

            if ($length < 8) {
                break;
            }

            $position += $length;
        }

        return $kind;
    }

    /**
     * "avc1.64001F": profile, constraints and level in hex.
     */
    private static function avc(string $kind, string $config): ?string
    {
        return \strlen($config) < 4 ? null : \sprintf('%s.%02X%02X%02X', $kind, \ord($config[1]), \ord($config[2]), \ord($config[3]));
    }

    /**
     * "hvc1.1.6.L93.B0" (ISO/IEC 14496-15, annex E).
     */
    private static function hevc(string $kind, string $config): ?string
    {
        if (\strlen($config) < 13) {
            return null;
        }

        $first = \ord($config[1]);
        $space = ['', 'A', 'B', 'C'][$first >> 6];
        $compatibility = self::uint32(substr($config, 2, 4));
        $reversed = 0;

        for ($bit = 0; $bit < 32; ++$bit) {
            $reversed |= (($compatibility >> $bit) & 1) << (31 - $bit);
        }

        $constraints = array_map(static fn(string $byte): string => \sprintf('%02X', \ord($byte)), str_split(substr($config, 6, 6)));

        while ([] !== $constraints && '00' === $constraints[\count($constraints) - 1]) {
            array_pop($constraints);
        }

        return implode('.', [$kind, $space . ($first & 0x1F), \sprintf('%X', $reversed), (1 === (($first >> 5) & 1) ? 'H' : 'L') . \ord($config[12]), ...$constraints]);
    }

    /**
     * "av01.0.04M.08": profile, level with tier and bit depth.
     */
    private static function av1(string $config): ?string
    {
        if (\strlen($config) < 3) {
            return null;
        }

        $second = \ord($config[1]);
        $third = \ord($config[2]);
        $depth = 0 !== ($third & 0x20) ? 12 : (0 !== ($third & 0x40) ? 10 : 8);

        return \sprintf('av01.%d.%02d%s.%02d', $second >> 5, $second & 0x1F, 0 !== ($third & 0x80) ? 'H' : 'M', $depth);
    }

    /**
     * "vp09.00.10.08": profile, level and bit depth.
     */
    private static function vp9(string $config): ?string
    {
        // A full box: version and flags first.
        if (\strlen($config) < 7) {
            return null;
        }

        return \sprintf('vp09.%02d.%02d.%02d', \ord($config[4]), \ord($config[5]), \ord($config[6]) >> 4);
    }

    /**
     * The first video track of a WebM file.
     *
     * @param resource $handle
     *
     * @return array{codec: string, width: int, height: int}|null
     */
    private static function webm($handle, int $size): ?array
    {
        // The tracks come before the clusters; a few MB are plenty.
        $limit = min($size, 8_000_000);
        $stack = [[0, $size]];
        $track = null;
        fseek($handle, 0);

        while ([] !== $stack) {
            $position = (int) ftell($handle);
            [$parent, $parentEnd] = $stack[\count($stack) - 1];

            if ($position >= $parentEnd || $position >= $limit) {
                array_pop($stack);

                if (self::WEBM_TRACK === $parent && null !== $track && $track['width'] > 0 && $track['height'] > 0) {
                    return ['codec' => self::webmCodec($track['id'], $track['private']), 'width' => $track['width'], 'height' => $track['height']];
                }

                if ($position >= $limit) {
                    return null;
                }

                continue;
            }

            $id = self::vint($handle, keepMarker: true);
            $length = self::vint($handle, keepMarker: false);

            if (null === $id || null === $length) {
                return null;
            }

            $body = (int) ftell($handle);
            // A length of all ones is unknown: up to the end of the parent.
            $end = -1 === $length ? $parentEnd : min($parentEnd, $body + $length);

            if (\in_array($id, [self::WEBM_SEGMENT, self::WEBM_TRACKS, self::WEBM_TRACK, self::WEBM_VIDEO], true)) {
                if (self::WEBM_TRACK === $id) {
                    $track = ['id' => '', 'private' => '', 'width' => 0, 'height' => 0];
                }

                $stack[] = [$id, $end];

                continue;
            }

            if (null !== $track && \in_array($id, [self::WEBM_CODEC, self::WEBM_PRIVATE, self::WEBM_WIDTH, self::WEBM_HEIGHT], true)) {
                $data = self::read($handle, $body, min(64, $end - $body));

                match ($id) {
                    self::WEBM_CODEC => $track['id'] = rtrim($data, "\0"),
                    self::WEBM_PRIVATE => $track['private'] = $data,
                    self::WEBM_WIDTH => $track['width'] = self::number($data),
                    default => $track['height'] = self::number($data),
                };
            }

            fseek($handle, $end);
        }

        return null;
    }

    private static function webmCodec(string $id, string $private): string
    {
        return match ($id) {
            'V_VP9' => 'vp9',
            'V_VP8' => 'vp8',
            'V_AV1' => self::av1($private) ?? 'av01',
            default => '',
        };
    }

    /**
     * A variable length number of EBML; -1 for "unknown" (all ones).
     *
     * @param resource $handle
     */
    private static function vint($handle, bool $keepMarker): ?int
    {
        $first = fread($handle, 1);

        if (!\is_string($first) || '' === $first || 0 === \ord($first)) {
            return null;
        }

        $byte = \ord($first);
        $length = 1;

        while (0 === ($byte & (0x80 >> ($length - 1)))) {
            ++$length;
        }

        $value = $keepMarker ? $byte : $byte & (0xFF >> $length);
        $ones = $value === (0xFF >> $length);
        $rest = $length > 1 ? fread($handle, $length - 1) : '';

        if (!\is_string($rest) || \strlen($rest) !== $length - 1) {
            return null;
        }

        foreach (str_split($rest) as $part) {
            $value = ($value << 8) | \ord($part);
            $ones = $ones && 0xFF === \ord($part);
        }

        return !$keepMarker && $ones ? -1 : $value;
    }

    /**
     * @param resource $handle
     */
    private static function read($handle, int $position, int $length): string
    {
        if ($length < 1) {
            return '';
        }

        fseek($handle, $position);
        $data = fread($handle, $length);

        return \is_string($data) ? $data : '';
    }

    private static function number(string $bytes): int
    {
        $number = 0;

        foreach (str_split($bytes) as $byte) {
            $number = ($number << 8) | \ord($byte);
        }

        return $number;
    }

    private static function uint32(string $bytes): int
    {
        $number = unpack('N', str_pad($bytes, 4, "\0"));

        return \is_array($number) && \is_int($number[1] ?? null) ? $number[1] : 0;
    }
}
