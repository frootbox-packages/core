<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

/**
 * The texts of one language, by package. A later package replaces the
 * text of an earlier one for everything but its own labels. Texts of
 * themes answer only when a theme asks (decision 0172), so a theme
 * cannot change a text of the backend.
 */
final readonly class Catalogue
{
    /** @var array<string, string|array{one: string, other: string}> Of all packages */
    private array $all;

    /** @var array<string, string|array{one: string, other: string}> Of all packages but themes */
    private array $shared;

    /**
     * @param array<string, array<string, string|array{one: string, other: string}>> $packages By package name
     * @param list<string>                                                          $themes   Names of the packages that are themes
     */
    public function __construct(
        private array $packages,
        private array $themes = [],
    ) {
        $this->all = array_merge(...array_values($packages));
        $this->shared = array_merge(...array_values(array_diff_key($packages, array_flip($themes))));
    }

    /**
     * The message of the first of the packages that has one, else of any;
     * of any theme too only if one of the packages is a theme.
     *
     * @param string|list<string>|null $packages
     *
     * @return string|array{one: string, other: string}|null
     */
    public function find(string $key, string|array|null $packages = null): string|array|null
    {
        $packages = \is_string($packages) ? [$packages] : $packages ?? [];

        foreach ($packages as $package) {
            $message = $this->packages[$package][$key] ?? null;

            if (null !== $message) {
                return $message;
            }
        }

        return ([] === array_intersect($packages, $this->themes) ? $this->shared : $this->all)[$key] ?? null;
    }

    /**
     * @return array<string, array<string, string|array{one: string, other: string}>>
     */
    public function toArray(): array
    {
        return $this->packages;
    }

    /**
     * @param array<mixed> $data   As toArray() gave it, e.g. from a cache file
     * @param list<string> $themes Names of the packages that are themes
     */
    public static function fromArray(array $data, string $file, array $themes = []): self
    {
        $packages = [];

        foreach ($data as $package => $messages) {
            $packages[(string) $package] = self::messagesOf(\is_array($messages) ? $messages : [], $file);
        }

        return new self($packages, $themes);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string|array{one: string, other: string}>
     */
    public static function messagesOf(array $data, string $file): array
    {
        $messages = [];

        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $messages[(string) $key] = $value;
            } elseif (\is_array($value) && \is_string($value['one'] ?? null) && \is_string($value['other'] ?? null)) {
                $messages[(string) $key] = ['one' => $value['one'], 'other' => $value['other']];
            } else {
                throw new TranslationException(\sprintf('"%s" in %s must be a text or have "one" and "other".', $key, $file));
            }
        }

        return $messages;
    }
}
