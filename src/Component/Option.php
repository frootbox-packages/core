<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A view option of a variant that editors choose per block, e.g. the
 * height of an image:
 *
 *     options:
 *       imageHeight:
 *         type: choice
 *         choices: { 400: Standard, 550: High }
 *         default: 400
 */
final readonly class Option
{
    public const array TYPES = ['choice', 'boolean', 'number', 'text'];

    /**
     * @param array<int|string, string> $choices Value => label
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string|int|float|bool $default,
        public string $description = '',
        public array $choices = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {}

    /**
     * The value if it fits, otherwise the default.
     */
    public function clean(mixed $value): string|int|float|bool
    {
        return $this->accepts($value) && (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value)) ? $this->normalize($value) : $this->default;
    }

    public function accepts(mixed $value): bool
    {
        return match ($this->type) {
            'choice' => (\is_string($value) || \is_int($value)) && \array_key_exists($value, $this->choices),
            'boolean' => \is_bool($value),
            'number' => (\is_int($value) || \is_float($value)) && (null === $this->min || $value >= $this->min) && (null === $this->max || $value <= $this->max),
            default => \is_string($value),
        };
    }

    /**
     * JSON schema of a chosen value.
     *
     * @return array<string, mixed>
     */
    public function valueSchema(): array
    {
        return match ($this->type) {
            'choice' => ['enum' => array_keys($this->choices)],
            'boolean' => ['type' => 'boolean'],
            'number' => ['type' => 'number', ...(null === $this->min ? [] : ['minimum' => $this->min]), ...(null === $this->max ? [] : ['maximum' => $this->max])],
            default => ['type' => 'string', 'maxLength' => 500],
        };
    }

    /**
     * Choice keys such as 400 come back as numbers even if stored as "400".
     */
    private function normalize(string|int|float|bool $value): string|int|float|bool
    {
        if ('choice' !== $this->type) {
            return $value;
        }

        foreach (array_keys($this->choices) as $key) {
            if ((string) $key === (string) $value) {
                return $key;
            }
        }

        return $this->default;
    }
}
