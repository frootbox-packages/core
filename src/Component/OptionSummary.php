<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A view option of a variant in block.list and layout.list, for the
 * settings of a block (decision 0123).
 */
final readonly class OptionSummary
{
    /**
     * @param list<array{value: int|string, label: string}> $choices
     */
    public function __construct(
        public string $name,
        /** choice, boolean, number or text */
        public string $type,
        public string $label,
        public string $description,
        public string|int|float|bool $default,
        public array $choices,
        public int|float|null $min,
        public int|float|null $max,
    ) {}

    public static function of(Option $option): self
    {
        $choices = [];

        foreach ($option->choices as $value => $label) {
            $choices[] = ['value' => $value, 'label' => $label];
        }

        return new self($option->name, $option->type, $option->label, $option->description, $option->default, $choices, $option->min, $option->max);
    }
}
