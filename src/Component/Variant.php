<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * One view of a block or layout. All variants share the fields and areas.
 */
final readonly class Variant
{
    public const string DEFAULT = 'Default';

    /**
     * @param array<string, Option> $options
     * @param string                $template Absolute path of the Twig file
     * @param string                $from     Package that provides the file, the origin or a theme overriding it
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $options,
        public string $template,
        public string $from,
    ) {}

    /**
     * The chosen options with defaults for the rest.
     *
     * @param array<mixed> $chosen
     *
     * @return array<string, string|int|float|bool>
     */
    public function options(array $chosen): array
    {
        $options = [];

        foreach ($this->options as $name => $option) {
            $options[$name] = $option->clean($chosen[$name] ?? null);
        }

        return $options;
    }
}
