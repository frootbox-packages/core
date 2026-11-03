<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * Videos and maps of well-known providers (decision 0225): turns the
 * address of a video at YouTube or Vimeo and a place on a map into the
 * address to embed and a link to open it at the provider. The core
 * registers these services; their content loads only after consent.
 */
final class Embeds
{
    public const string YOUTUBE = 'youtube';
    public const string VIMEO = 'vimeo';
    public const string OPENSTREETMAP = 'openstreetmap';
    public const string GOOGLE_MAPS = 'google-maps';

    public const int ZOOM_MIN = 3;
    public const int ZOOM_MAX = 19;
    public const int ZOOM = 16;

    /**
     * @return list<ExternalService>
     */
    public static function services(): array
    {
        return [
            new ExternalService(self::YOUTUBE, 'YouTube', 'Google Ireland Limited', 'https://policies.google.com/privacy'),
            new ExternalService(self::VIMEO, 'Vimeo', 'Vimeo.com, Inc.', 'https://vimeo.com/privacy'),
            new ExternalService(self::OPENSTREETMAP, 'OpenStreetMap', 'OpenStreetMap Foundation', 'https://osmfoundation.org/wiki/Privacy_Policy'),
            new ExternalService(self::GOOGLE_MAPS, 'Google Maps', 'Google Ireland Limited', 'https://policies.google.com/privacy'),
        ];
    }

    /**
     * A video at YouTube or Vimeo, null for any other address. YouTube
     * plays from youtube-nocookie.com, Vimeo without tracking (dnt).
     */
    public static function video(string $url): ?Embed
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (!\is_array($parts) || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = (string) preg_replace('/^(www\.|m\.)/', '', strtolower($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (\in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = match (true) {
                'youtu.be' === $host => explode('/', trim($path, '/'))[0],
                '/watch' === $path => \is_string($query['v'] ?? null) ? $query['v'] : '',
                1 === preg_match('#^/(?:embed|shorts|live)/([^/]+)#', $path, $match) => $match[1],
                default => '',
            };

            if (1 !== preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                return null;
            }

            $start = self::seconds(\is_string($query['t'] ?? null) ? $query['t'] : (\is_string($query['start'] ?? null) ? $query['start'] : ''));

            return new Embed(
                self::YOUTUBE,
                'https://www.youtube-nocookie.com/embed/' . $id . (null === $start ? '' : '?start=' . $start),
                'https://www.youtube.com/watch?v=' . $id . (null === $start ? '' : '&t=' . $start . 's'),
            );
        }

        if (\in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (1 !== preg_match('#^(?:/video)?/(\d{1,12})(?:/([0-9a-f]{6,20}))?/?$#', $path, $match)) {
                return null;
            }

            $hash = $match[2] ?? (\is_string($query['h'] ?? null) && 1 === preg_match('/^[0-9a-f]{6,20}$/', $query['h']) ? $query['h'] : '');

            return new Embed(
                self::VIMEO,
                'https://player.vimeo.com/video/' . $match[1] . '?dnt=1' . ('' === $hash ? '' : '&h=' . $hash),
                'https://vimeo.com/' . $match[1] . ('' === $hash ? '' : '/' . $hash),
            );
        }

        return null;
    }

    /**
     * A place on a map. OpenStreetMap needs coordinates; Google Maps takes
     * an address or coordinates. Null if what it needs is missing.
     */
    public static function map(string $provider, ?float $latitude, ?float $longitude, string $address = '', int $zoom = self::ZOOM): ?Embed
    {
        $zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom));
        $located = null !== $latitude && null !== $longitude && abs($latitude) <= 90 && abs($longitude) <= 180;
        $address = trim($address);

        if (self::GOOGLE_MAPS === $provider) {
            $place = $located ? self::number($latitude) . ',' . self::number($longitude) : $address;

            if ('' === $place) {
                return null;
            }

            return new Embed(
                self::GOOGLE_MAPS,
                'https://maps.google.com/maps?' . http_build_query(['q' => $place, 'z' => $zoom, 'output' => 'embed'], '', '&', \PHP_QUERY_RFC3986),
                'https://www.google.com/maps/search/?' . http_build_query(['api' => 1, 'query' => '' !== $address ? $address : $place], '', '&', \PHP_QUERY_RFC3986),
            );
        }

        if (self::OPENSTREETMAP !== $provider || !$located) {
            return null;
        }

        // About 600 pixels across at this zoom; the map keeps the marker in the middle.
        $width = 360 * 600 / (256 * 2 ** $zoom);
        $height = $width * cos(deg2rad($latitude)) * 0.75;
        $box = [
            max(-180, $longitude - $width / 2),
            max(-90, $latitude - $height / 2),
            min(180, $longitude + $width / 2),
            min(90, $latitude + $height / 2),
        ];

        return new Embed(
            self::OPENSTREETMAP,
            'https://www.openstreetmap.org/export/embed.html?bbox=' . implode('%2C', array_map(self::number(...), $box)) . '&layer=mapnik&marker=' . self::number($latitude) . '%2C' . self::number($longitude),
            'https://www.openstreetmap.org/?mlat=' . self::number($latitude) . '&mlon=' . self::number($longitude) . '#map=' . $zoom . '/' . self::number($latitude) . '/' . self::number($longitude),
        );
    }

    /**
     * Seconds from "90", "90s" or "1m30s"; null for none.
     */
    private static function seconds(string $time): ?int
    {
        if (1 !== preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $time, $match) || '' === $time) {
            return null;
        }

        $seconds = (int) ($match[1] ?? 0) * 3600 + (int) ($match[2] ?? 0) * 60 + (int) ($match[3] ?? 0);

        return $seconds > 0 ? $seconds : null;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
