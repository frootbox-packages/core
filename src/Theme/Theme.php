<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Extension\ExtensionPackage;

/**
 * An extension with "theme:" in its frootbox.yaml:
 *
 *     theme:
 *       parent: frootbox/base
 *       tokens:
 *         color:
 *           primary: '#0a6e4f'
 *         radius: 4px
 *
 * Tokens become CSS variables such as --color-primary.
 */
final readonly class Theme
{
    private const array KEYS = ['parent', 'tokens'];

    private const string TOKEN_NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /**
     * @param array<string, string> $tokens Flat: "color-primary" => "#0a6e4f"
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $parent = null,
        public array $tokens = [],
    ) {}

    public static function fromPackage(ExtensionPackage $package): ?self
    {
        if (null === $package->theme) {
            return null;
        }

        $where = \sprintf('"theme" in %s of %s', ExtensionPackage::MANIFEST, $package->name);
        $unknown = array_diff(array_map(strval(...), array_keys($package->theme)), self::KEYS);

        if ([] !== $unknown) {
            throw new ThemeException(\sprintf('%s contains unknown keys: %s. Allowed are %s.', $where, implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        $parent = $package->theme['parent'] ?? null;

        if (null !== $parent && (!\is_string($parent) || '' === $parent)) {
            throw new ThemeException(\sprintf('%s: "parent" must be the package name of a theme, e.g. frootbox/base.', $where));
        }

        $tokens = $package->theme['tokens'] ?? [];

        if (!\is_array($tokens)) {
            throw new ThemeException(\sprintf('%s: "tokens" must contain key-value pairs.', $where));
        }

        return new self($package->name, $package->path, $parent, self::flatten($tokens, '', $where));
    }

    /**
     * @param array<mixed> $tokens
     *
     * @return array<string, string>
     */
    private static function flatten(array $tokens, string $prefix, string $where): array
    {
        $flat = [];

        foreach ($tokens as $key => $value) {
            $key = (string) $key;

            if (1 !== preg_match(self::TOKEN_NAME, $key)) {
                throw new ThemeException(\sprintf('%s: the token "%s%s" must be lower case with dashes, e.g. "font-size".', $where, $prefix, $key));
            }

            $name = $prefix . $key;

            if (\is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $name . '-', $where)];

                continue;
            }

            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                throw new ThemeException(\sprintf('%s: the token "%s" must be text or a number.', $where, $name));
            }

            $value = trim((string) $value);

            // The value ends up in a style sheet and must not break out of it.
            if ('' === $value || 1 === preg_match('/[;{}<>\\\\\x00-\x1F]/', $value)) {
                throw new ThemeException(\sprintf('%s: the token "%s" must not be empty or contain ; { } < > or a backslash.', $where, $name));
            }

            $flat[$name] = $value;
        }

        return $flat;
    }
}
