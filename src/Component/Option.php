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
 *
 * A choice can come from a query of an extension instead, e.g. the forms
 * (decision 0222): source: frootbox/forms/form.choices. The query returns
 * a list of {value, label}; the value is kept as text.
 *
 * With `multiple: true` editors tick any number of choices and the value
 * is a list, by default empty (decision 0355). A choice with the value ""
 * (such as "All") stands for the empty list.
 */
final readonly class Option
{
    public const array TYPES = ['choice', 'boolean', 'number', 'text'];

    /** Most values of a multiple choice. */
    public const int MAX_CHOSEN = 100;

    /**
     * @param array<int|string, string> $choices Value => label
     * @param string|int|float|bool|list<int|string> $default a list for multiple choices
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string|int|float|bool|array $default,
        public string $description = '',
        public array $choices = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        /** The query with the choices, e.g. "frootbox/forms/form.choices" */
        public ?string $source = null,
        /** Any number of choices, the value is a list */
        public bool $multiple = false,
    ) {}

    /**
     * The same option with other choices, e.g. from its source.
     *
     * @param array<int|string, string> $choices
     */
    public function withChoices(array $choices): self
    {
        return new self($this->name, $this->type, $this->label, $this->default, $this->description, $choices, $this->min, $this->max, $this->source, $this->multiple);
    }

    /**
     * The value if it fits, otherwise the default.
     *
     * @return string|int|float|bool|list<int|string>
     */
    public function clean(mixed $value): string|int|float|bool|array
    {
        if ($this->multiple) {
            if (!$this->accepts($value) || !\is_array($value)) {
                return $this->default;
            }

            $list = [];

            foreach ($value as $item) {
                if (\is_string($item) || \is_int($item)) {
                    $list[] = $this->key($item) ?? $item;
                }
            }

            return $list;
        }

        if (!$this->accepts($value) || !(\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value))) {
            return $this->default;
        }

        return 'choice' === $this->type && null === $this->source ? $this->key($value) ?? $this->default : $value;
    }

    public function accepts(mixed $value): bool
    {
        if ($this->multiple) {
            if (!\is_array($value) || !array_is_list($value) || \count($value) > self::MAX_CHOSEN) {
                return false;
            }

            $seen = [];

            foreach ($value as $item) {
                if (!(\is_string($item) || \is_int($item)) || '' === $item || isset($seen[(string) $item]) || !$this->acceptsOne($item)) {
                    return false;
                }

                $seen[(string) $item] = true;
            }

            return true;
        }

        return $this->acceptsOne($value);
    }

    private function acceptsOne(mixed $value): bool
    {
        return match ($this->type) {
            'choice' => null !== $this->source
                ? \is_string($value) && \strlen($value) <= 100
                : (\is_string($value) || \is_int($value)) && \array_key_exists($value, $this->choices),
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
        if ($this->multiple) {
            return ['type' => 'array', 'maxItems' => self::MAX_CHOSEN, 'uniqueItems' => true, 'items' => null !== $this->source ? ['type' => 'string', 'minLength' => 1, 'maxLength' => 100] : ['enum' => array_values(array_filter(array_keys($this->choices), static fn(int|string $key): bool => '' !== $key))]];
        }

        return match ($this->type) {
            'choice' => null !== $this->source ? ['type' => 'string', 'maxLength' => 100] : ['enum' => array_keys($this->choices)],
            'boolean' => ['type' => 'boolean'],
            'number' => ['type' => 'number', ...(null === $this->min ? [] : ['minimum' => $this->min]), ...(null === $this->max ? [] : ['maximum' => $this->max])],
            default => ['type' => 'string', 'maxLength' => 500],
        };
    }

    /**
     * The key of a fixed choice, so 400 comes back as a number even if
     * stored as "400"; null for a value from a source or none at all.
     */
    private function key(string|int|float|bool $value): int|string|null
    {
        if ('choice' !== $this->type || null !== $this->source) {
            return null;
        }

        foreach (array_keys($this->choices) as $key) {
            if ((string) $key === (string) $value) {
                return $key;
            }
        }

        return null;
    }
}
