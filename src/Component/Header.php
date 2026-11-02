<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML header at the start of a view:
 *
 *     {# frootbox
 *     title: Teaser
 *     fields:
 *       title: { type: headline }
 *     #}
 */
final class Header
{
    private const string PATTERN = '/\A\s*\{#\s*frootbox\b(.*?)#\}/s';

    /**
     * @return array<mixed> Empty if there is no header
     *
     * @throws InvalidArgumentException If the header is not a YAML map
     */
    public static function parse(string $source): array
    {
        if (1 !== preg_match(self::PATTERN, $source, $match)) {
            return [];
        }

        try {
            // Lines in error messages match the file, as the header starts in line 1.
            $data = Yaml::parse($match[1]);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('the header is not valid YAML: ' . $exception->getMessage(), previous: $exception);
        }

        if (null === $data) {
            return [];
        }

        if (!\is_array($data) || (array_is_list($data) && [] !== $data)) {
            throw new InvalidArgumentException('the header must contain keys such as title and fields');
        }

        return $data;
    }
}
