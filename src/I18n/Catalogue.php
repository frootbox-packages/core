<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

/**
 * The texts of one language, by package. A later package replaces the
 * text of an earlier one for everything but its own labels.
 */
final readonly class Catalogue
{
    /** @var array<string, string|array{one: string, other: string}> */
    private array $all;

    /**
     * @param array<string, array<string, string|array{one: string, other: string}>> $packages By package name
     */
    public function __construct(private array $packages)
    {
        $this->all = array_merge(...array_values($packages));
    }

    /**
     * @return string|array{one: string, other: string}|null
     */
    public function find(string $key, ?string $package = null): string|array|null
    {
        return (null === $package ? null : $this->packages[$package][$key] ?? null) ?? $this->all[$key] ?? null;
    }

    /**
     * @return array<string, array<string, string|array{one: string, other: string}>>
     */
    public function toArray(): array
    {
        return $this->packages;
    }

    /**
     * @param array<mixed> $data As toArray() gave it, e.g. from a cache file
     */
    public static function fromArray(array $data, string $file): self
    {
        $packages = [];

        foreach ($data as $package => $messages) {
            $packages[(string) $package] = self::messagesOf(\is_array($messages) ? $messages : [], $file);
        }

        return new self($packages);
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
