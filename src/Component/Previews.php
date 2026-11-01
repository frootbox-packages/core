<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * Preview images of blocks and layouts (decisions 0148, 0149). They stay
 * in the folder of the block, next to its views, and follow overrides of
 * the theme chain. They are never published to public/: PreviewHandler
 * delivers them to those who may arrange blocks.
 *
 *     blocks/Hero/preview.svg          the block, and its variant Default
 *     blocks/Hero/preview.Split.webp   the variant Split
 */
final readonly class Previews
{
    public const string ROUTE = '/previews/{package:[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*}/{kind:blocks|layouts}/{name:[A-Z][A-Za-z0-9]*}/{file:preview(?:\.[A-Z][A-Za-z0-9]*)?\.(?:svg|webp|png|jpg)}';

    /** Formats by preference, if a folder has more than one for a variant. */
    public const array TYPES = [
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
    ];

    /** "preview.svg" or "preview.Split.webp"; the variant is empty for Default. */
    public const string FILE = '/^preview(?:\.(?<variant>[A-Z][A-Za-z0-9]*))?\.(?<extension>svg|webp|png|jpg)$/';

    /** Width to height of every preview. */
    public const float RATIO = 1.6;

    /**
     * The URL of the preview of a variant, with the time of its last
     * change so browsers keep it until it changes. Null without a file.
     */
    public function url(Component $component, string $variant = Variant::DEFAULT): ?string
    {
        $file = $component->previews[$variant] ?? null;

        if (null === $file) {
            return null;
        }

        $slash = strrpos($component->name, '/');
        $package = substr($component->name, 0, (int) $slash);
        $name = substr($component->name, (int) $slash + 1);

        return \sprintf('/previews/%s/%s/%s/%s?v=%d', $package, $component->kind->folder(), $name, basename($file), is_file($file) ? (int) filemtime($file) : 0);
    }

    /**
     * Finds the preview files of a folder: variant => file, "Default" for
     * preview.svg. With several formats for a variant the first of TYPES wins.
     *
     * @return array<string, string>
     */
    public static function in(string $folder): array
    {
        $found = [];
        $files = glob($folder . '/preview.*');

        foreach (false === $files ? [] : $files as $file) {
            if (1 === preg_match(self::FILE, basename($file), $match) && is_file($file)) {
                $found['' === $match['variant'] ? Variant::DEFAULT : $match['variant']][] = $file;
            }
        }

        $order = array_flip(array_keys(self::TYPES));
        $previews = [];

        foreach ($found as $variant => $candidates) {
            usort($candidates, static fn(string $a, string $b): int => $order[pathinfo($a, \PATHINFO_EXTENSION)] <=> $order[pathinfo($b, \PATHINFO_EXTENSION)]);
            $previews[$variant] = $candidates[0];
        }

        return $previews;
    }
}
