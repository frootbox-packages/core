<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

/**
 * What an update changed in a row, the shortened form of a change that
 * is kept after it can no longer be undone (decision 0174): only the
 * columns that changed, and of a JSON column only the paths that changed,
 * each with its old and new value.
 *
 * A path names keys from the top, separated by "/", e.g.
 * "translations/de/content/areas/main/#nfgt0d8b/fields/title". Entries of
 * a list that all carry an id, like blocks, are named "#<id>" instead of
 * their position, so a block that moves or is removed does not change the
 * paths of the others; their order is the path ".../#" with the ids. "/"
 * and "~" in keys are written "~1" and "~0" as in a JSON pointer. A path
 * missing on one side was not there.
 */
final class RowDiff
{
    /** The path of the order of the entries of a list with ids. */
    public const string ORDER = '#';

    /**
     * @param array<string, mixed> $before the row as it was
     * @param array<string, mixed> $after  the row as it is
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the changed columns before and after, a JSON column as paths with their values
     */
    public static function of(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $column) {
            $was = $before[$column] ?? null;
            $is = $after[$column] ?? null;

            if ($was === $is) {
                continue;
            }

            $wasJson = self::json($was);
            $isJson = self::json($is);

            if (null !== $wasJson && null !== $isJson) {
                $paths = [[], []];
                self::walk($wasJson, $isJson, '', $paths);
                [$old[$column], $new[$column]] = $paths;

                continue;
            }

            $old[$column] = $was;
            $new[$column] = $is;
        }

        return [$old, $new];
    }

    /**
     * The value at a path of a JSON column, e.g. to show it in a history.
     */
    public static function at(mixed $value, string $path): mixed
    {
        $json = \is_array($value) ? $value : self::json($value);

        if ('' === $path) {
            return $json;
        }

        $current = $json;

        foreach (explode('/', $path) as $segment) {
            if (!\is_array($current)) {
                return null;
            }

            $key = self::unescape($segment);

            if (str_starts_with($key, '#') && array_is_list($current)) {
                $current = array_find($current, static fn(mixed $item): bool => \is_array($item) && ($item['id'] ?? null) === substr($key, 1));

                continue;
            }

            $current = $current[$key] ?? null;
        }

        return $current;
    }

    /**
     * @param array{0: array<string, mixed>, 1: array<string, mixed>} $paths
     */
    private static function walk(mixed $was, mixed $is, string $path, array &$paths): void
    {
        if (\is_array($was) && \is_array($is)) {
            $wasKeyed = self::keyed($was);
            $isKeyed = self::keyed($is);

            if (null !== $wasKeyed && null !== $isKeyed) {
                if (array_keys($wasKeyed) !== array_keys($isKeyed)) {
                    $paths[0][self::join($path, self::ORDER)] = self::ids($wasKeyed);
                    $paths[1][self::join($path, self::ORDER)] = self::ids($isKeyed);
                }

                $was = $wasKeyed;
                $is = $isKeyed;
            }

            foreach (array_unique([...array_keys($was), ...array_keys($is)]) as $key) {
                $sub = null !== $wasKeyed && null !== $isKeyed ? self::join($path, (string) $key, escape: false) : self::join($path, (string) $key);

                if (!\array_key_exists($key, $is)) {
                    $paths[0][$sub] = $was[$key];
                } elseif (!\array_key_exists($key, $was)) {
                    $paths[1][$sub] = $is[$key];
                } elseif ($was[$key] !== $is[$key]) {
                    self::walk($was[$key], $is[$key], $sub, $paths);
                }
            }

            return;
        }

        $paths[0][$path] = $was;
        $paths[1][$path] = $is;
    }

    /**
     * A list whose entries all carry an id, by "#<id>", else null.
     *
     * @param array<mixed> $list
     *
     * @return array<string, mixed>|null
     */
    private static function keyed(array $list): ?array
    {
        if ([] === $list || !array_is_list($list)) {
            return null;
        }

        $keyed = [];

        foreach ($list as $item) {
            $id = \is_array($item) ? $item['id'] ?? null : null;

            if (!\is_string($id) || '' === $id || \array_key_exists('#' . $id, $keyed)) {
                return null;
            }

            $keyed['#' . self::escape($id)] = $item;
        }

        return $keyed;
    }

    /**
     * @param array<string, mixed> $keyed
     *
     * @return list<string>
     */
    private static function ids(array $keyed): array
    {
        return array_map(static fn(string $key): string => self::unescape(substr($key, 1)), array_keys($keyed));
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(mixed $value): ?array
    {
        if (!\is_string($value) || !\in_array($value[0] ?? '', ['{', '['], true)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private static function join(string $path, string $key, bool $escape = true): string
    {
        $key = $escape ? self::escape($key) : $key;

        return '' === $path ? $key : $path . '/' . $key;
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    private static function unescape(string $key): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $key);
    }
}
