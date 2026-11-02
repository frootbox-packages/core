<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Support\Message;

/**
 * A token editors may change, described by the theme (decision 0127):
 *
 *     primary: { value: '#1d4ed8', type: color, label: Main colour, contrast: primary-contrast }
 *     radius: { value: 8px, type: length, label: Rounding, min: 0, max: 32, unit: px }
 *
 * The value itself comes from the chain, so a child theme can change it
 * with the short form and keep the description.
 */
final readonly class TokenDefinition
{
    public const string COLOR = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

    /**
     * @param string|null                                         $contrast The colour this one is read against; ThemeChain makes it the full name
     * @param array<string, array{label: string, value: string}> $options  For choices, by key
     */
    public function __construct(
        public string $name,
        public TokenType $type,
        public string $label,
        public ?string $contrast = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public ?string $unit = null,
        public array $options = [],
    ) {}

    public function withContrast(?string $contrast): self
    {
        return clone($this, ['contrast' => $contrast]);
    }

    /**
     * Why the value does not fit, null if it does.
     *
     * @param array<string, Font> $fonts
     */
    public function problem(string $value, array $fonts): ?Message
    {
        return match ($this->type) {
            TokenType::Color => 1 === preg_match(self::COLOR, $value) ? null : new Message('must be a colour like #1d4ed8.'),
            TokenType::Font => isset($fonts[$value]) ? null : new Message('must be one of the fonts {fonts}.', ['fonts' => implode(', ', array_keys($fonts))]),
            TokenType::Choice => isset($this->options[$value]) ? null : new Message('must be one of {options}.', ['options' => implode(', ', array_keys($this->options))]),
            TokenType::Length => $this->lengthProblem($value),
        };
    }

    /**
     * The value as it goes into the style sheet.
     *
     * @param array<string, Font> $fonts
     */
    public function css(string $value, array $fonts): string
    {
        return match ($this->type) {
            TokenType::Color => strtolower(self::longColor($value)),
            TokenType::Font => $fonts[$value]->family ?? $value,
            TokenType::Choice => $this->options[$value]['value'] ?? $value,
            TokenType::Length => $value,
        };
    }

    /**
     * The value as it is stored: colours with six lower case digits.
     */
    public function normalize(string $value): string
    {
        $value = trim($value);

        return TokenType::Color === $this->type && 1 === preg_match(self::COLOR, $value) ? strtolower(self::longColor($value)) : $value;
    }

    private static function longColor(string $value): string
    {
        return 4 === \strlen($value) ? '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] : $value;
    }

    private function lengthProblem(string $value): ?Message
    {
        $unit = $this->unit ?? 'px';

        if (1 !== preg_match('/^(-?\d+(?:\.\d+)?)' . preg_quote($unit, '/') . '$/', $value, $match)) {
            return new Message('must be a number in {unit}, e.g. 12{unit}.', ['unit' => $unit]);
        }

        $number = (float) $match[1];

        if ((null !== $this->min && $number < $this->min) || (null !== $this->max && $number > $this->max)) {
            return new Message('must be between {min} and {max} {unit}.', ['min' => $this->min ?? '…', 'max' => $this->max ?? '…', 'unit' => $unit]);
        }

        return null;
    }
}
