<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * A block of a theme that holds other blocks on the design page, e.g. a
 * column of a section or a section on the main colour (decision 0208):
 *
 *     theme:
 *       design:
 *         places:
 *           column: { label: In one of two columns, block: Section, variant: Two, area: first }
 *         surfaces:
 *           primary: { label: On the main colour, block: Section, options: { background: primary }, area: first }
 */
final readonly class Frame
{
    public const string PLACES = 'places';

    public const string SURFACES = 'surfaces';

    private const array KEYS = ['label', 'block', 'variant', 'area', 'options'];

    private const string NAME = '/^[a-z][a-z0-9-]*$/';

    /**
     * @param string               $block   Full name, e.g. "frootbox/theme-base/Section"
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $block,
        public string $area,
        public ?string $variant = null,
        public array $options = [],
        public string $theme = '',
    ) {}

    /**
     * The frames of one list of "theme.design", null if the theme does not
     * name the list, so a parent's list applies.
     *
     * @return array<string, self>|null
     *
     * @throws ThemeException
     */
    public static function list(mixed $design, string $list, string $package, string $where): ?array
    {
        if (null === $design) {
            return null;
        }

        if (!\is_array($design) || [] !== array_diff(array_map(strval(...), array_keys($design)), [self::PLACES, self::SURFACES])) {
            throw new ThemeException(\sprintf('%s: "design" may contain "places" and "surfaces".', $where));
        }

        if (!\array_key_exists($list, $design)) {
            return null;
        }

        if (!\is_array($design[$list]) || array_is_list($design[$list]) && [] !== $design[$list]) {
            throw new ThemeException(\sprintf('%s: "design.%s" must list frames by name, e.g. "column: { label: In a column, block: Section, area: first }".', $where, $list));
        }

        $frames = [];

        foreach ($design[$list] as $name => $frame) {
            $frames[(string) $name] = self::frame((string) $name, $frame, $package, \sprintf('%s: "design.%s.%s"', $where, $list, $name));
        }

        return $frames;
    }

    /**
     * @throws ThemeException
     */
    private static function frame(string $name, mixed $frame, string $package, string $where): self
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new ThemeException(\sprintf('%s: the name must be small letters, digits and "-".', $where));
        }

        if (!\is_array($frame)) {
            throw new ThemeException(\sprintf('%s must be a map with label, block and area.', $where));
        }

        $unknown = array_diff(array_map(strval(...), array_keys($frame)), self::KEYS);

        if ([] !== $unknown) {
            throw new ThemeException(\sprintf('%s contains unknown keys: %s. Allowed are %s.', $where, implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        foreach (['label', 'block', 'area'] as $key) {
            if (!\is_string($frame[$key] ?? null) || '' === $frame[$key]) {
                throw new ThemeException(\sprintf('%s needs "%s" as text.', $where, $key));
            }
        }

        $variant = $frame['variant'] ?? null;
        $options = $frame['options'] ?? [];

        if (null !== $variant && !\is_string($variant)) {
            throw new ThemeException(\sprintf('%s: "variant" must be the name of a variant.', $where));
        }

        if (!\is_array($options) || array_is_list($options) && [] !== $options) {
            throw new ThemeException(\sprintf('%s: "options" must be a map by name.', $where));
        }

        /** @var array{label: string, block: string, area: string} $frame */
        $block = str_contains($frame['block'], '/') ? $frame['block'] : $package . '/' . $frame['block'];

        /** @var array<string, mixed> $options */
        return new self($name, $frame['label'], $block, $frame['area'], $variant, $options, $package);
    }
}
