<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

/**
 * Which blocks or layouts of others a theme offers editors (decision
 * 0162): "only" lists those of its parents to keep, "hide" those to leave
 * out, also of modules. Hidden ones on pages are still shown, they just
 * cannot be added again.
 *
 *     blocks:
 *       only: [frootbox/theme-base/Text, frootbox/theme-base/Image]
 *       hide: [acme/news/Teaser]
 */
final readonly class Offer
{
    private const array KEYS = ['only', 'hide'];

    /**
     * @param list<string>|null $only Full names, null for all of the parents
     * @param list<string>      $hide Full names
     */
    public function __construct(
        public ?array $only = null,
        public array $hide = [],
    ) {}

    /**
     * @throws ThemeException
     */
    public static function from(mixed $value, string $key, string $where): self
    {
        if (null === $value) {
            return new self();
        }

        $example = \sprintf('e.g. "%s: { hide: [frootbox/theme-base/Hero] }"', $key);

        if (!\is_array($value) || [] !== array_diff(array_map(strval(...), array_keys($value)), self::KEYS)) {
            throw new ThemeException(\sprintf('%s: "%s" takes "only" and "hide" with lists of full names, %s.', $where, $key, $example));
        }

        $only = \array_key_exists('only', $value) ? self::names($value['only'], $key . '.only', $where, $example) : null;

        return new self($only, self::names($value['hide'] ?? [], $key . '.hide', $where, $example));
    }

    public function isEmpty(): bool
    {
        return null === $this->only && [] === $this->hide;
    }

    /**
     * @return list<string>
     *
     * @throws ThemeException
     */
    private static function names(mixed $names, string $key, string $where, string $example): array
    {
        if (!\is_array($names) || !array_is_list($names)) {
            throw new ThemeException(\sprintf('%s: "%s" must be a list of full names, %s.', $where, $key, $example));
        }

        $list = [];

        foreach ($names as $name) {
            if (!\is_string($name) || 1 !== preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+/[A-Z][A-Za-z0-9]*$#', $name)) {
                throw new ThemeException(\sprintf('%s: "%s" contains "%s", which is no full name such as frootbox/theme-base/Hero.', $where, $key, \is_scalar($name) ? (string) $name : get_debug_type($name)));
            }

            $list[] = $name;
        }

        return array_values(array_unique($list));
    }
}
