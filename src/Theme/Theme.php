<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Language\Script;

/**
 * An extension with "theme:" in its frootbox.yaml:
 *
 *     theme:
 *       parent: frootbox/base
 *       tokens:
 *         color:
 *           primary: { value: '#0a6e4f', type: color, label: Main colour }
 *         radius: 4px
 *       fonts:
 *         lora: { label: Lora, family: '"Lora", serif' }
 *       scripts:
 *         arab:
 *           font: { body: '"Noto Naskh Arabic", Tahoma, sans-serif' }
 *           leading: 1.8
 *       fields:
 *         address: { type: text, label: Address, optional: true }
 *       navigations:
 *         main: { title: Main navigation, depth: 2 }
 *         footer: Footer
 *       blocks:
 *         hide: [frootbox/theme-base/Pricing]
 *       layouts:
 *         only: [frootbox/theme-base/Page]
 *
 * Tokens become CSS variables such as --color-primary. Those with a type
 * may be changed by editors, the short form stays fixed; fonts are what a
 * font token can choose besides the system fonts (decision 0127).
 * Scripts give content in languages of a script, by its code of ISO
 * 15924, other values for tokens, e.g. fonts with its letters (decision
 * 0159).
 * Fields hold content that is the same on every page, read by
 * Site\SiteFields (decision 0128). Navigations are the menus the theme
 * shows (decision 0106). Blocks and layouts say which of others the
 * theme offers editors (decision 0162).
 */
final readonly class Theme
{
    private const array KEYS = ['parent', 'tokens', 'fonts', 'scripts', 'fields', 'navigations', 'blocks', 'layouts'];

    private const array DESCRIPTION = ['value', 'type', 'label', 'contrast', 'min', 'max', 'step', 'unit', 'options'];

    /** A value ends up in a style sheet and must not break out of it. */
    private const string UNSAFE = '/[;{}<>\\\\\x00-\x1F]/';

    private const string TOKEN_NAME = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /**
     * @param array<string, string>          $tokens      Flat: "color-primary" => "#0a6e4f"
     * @param array<string, array<mixed>>    $navigations By key, as in the manifest; read by Navigation\Definitions
     * @param array<string, TokenDefinition> $definitions The tokens with a type, by name
     * @param array<string, Font>            $fonts       By key
     * @param array<string, array<mixed>>    $fields      By name, as in the manifest; read by Site\SiteFields
     * @param array<string, array<string, string>> $scripts Flat tokens by script, e.g. "arab" => ["font-body" => …]
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $parent = null,
        public array $tokens = [],
        public array $navigations = [],
        public array $definitions = [],
        public array $fonts = [],
        public array $fields = [],
        public array $scripts = [],
        public Offer $blocks = new Offer(),
        public Offer $layouts = new Offer(),
    ) {}

    public static function fromPackage(ExtensionPackage $package): ?self
    {
        if (null === $package->theme) {
            return null;
        }

        $where = \sprintf('"theme" in %s of %s', ExtensionPackage::MANIFEST, $package->name);
        $unknown = array_diff(array_map(strval(...), array_keys($package->theme)), self::KEYS);

        if ([] !== $unknown) {
            throw new ThemeException(\sprintf('%s contains unknown keys: %s. Allowed are %s.', $where, implode(', ', $unknown), implode(', ', self::KEYS)));
        }

        $parent = $package->theme['parent'] ?? null;

        if (null !== $parent && (!\is_string($parent) || '' === $parent)) {
            throw new ThemeException(\sprintf('%s: "parent" must be the package name of a theme, e.g. frootbox/base.', $where));
        }

        $tokens = $package->theme['tokens'] ?? [];

        if (!\is_array($tokens)) {
            throw new ThemeException(\sprintf('%s: "tokens" must contain key-value pairs.', $where));
        }

        $navigations = [];

        foreach (\is_array($package->theme['navigations'] ?? null) ? $package->theme['navigations'] : [] as $key => $navigation) {
            $navigations[(string) $key] = \is_string($navigation) ? ['title' => $navigation] : (\is_array($navigation) ? $navigation : []);
        }

        if (isset($package->theme['navigations']) && !\is_array($package->theme['navigations'])) {
            throw new ThemeException(\sprintf('%s: "navigations" must list the navigations by key, e.g. "main: Main navigation".', $where));
        }

        $fields = [];

        foreach (\is_array($package->theme['fields'] ?? null) ? $package->theme['fields'] : [] as $name => $field) {
            if (!\is_array($field)) {
                throw new ThemeException(\sprintf('%s: the field "%s" must be described like a field of a block, e.g. "address: { type: text, label: Address }".', $where, $name));
            }

            $fields[(string) $name] = $field;
        }

        if (isset($package->theme['fields']) && !\is_array($package->theme['fields'])) {
            throw new ThemeException(\sprintf('%s: "fields" must list the fields by name, e.g. "address: { type: text, label: Address }".', $where));
        }

        $definitions = [];
        $flat = self::flatten($tokens, '', $where, $definitions);

        return new self($package->name, $package->path, $parent, $flat, $navigations, $definitions, self::fonts($package->theme['fonts'] ?? [], $where), $fields, self::scripts($package->theme['scripts'] ?? [], $where), Offer::from($package->theme['blocks'] ?? null, 'blocks', $where), Offer::from($package->theme['layouts'] ?? null, 'layouts', $where));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function scripts(mixed $scripts, string $where): array
    {
        if (!\is_array($scripts)) {
            throw new ThemeException(\sprintf('%s: "scripts" must list tokens by script, e.g. "arab: { font: { body: \'"Noto Naskh Arabic", sans-serif\' } }".', $where));
        }

        $list = [];

        foreach ($scripts as $script => $tokens) {
            $script = (string) $script;

            if (1 !== preg_match(Script::PATTERN, $script) || !\is_array($tokens)) {
                throw new ThemeException(\sprintf('%s: the script "%s" needs a code of ISO 15924 in lower case, e.g. "arab", and tokens below it.', $where, $script));
            }

            $definitions = [];
            $list[$script] = self::flatten($tokens, '', $where, $definitions);

            if ([] !== $definitions) {
                throw new ThemeException(\sprintf('%s: the tokens of the script "%s" are values only, editors change them under "tokens".', $where, $script));
            }
        }

        return $list;
    }

    /**
     * @return array<string, Font>
     */
    private static function fonts(mixed $fonts, string $where): array
    {
        if (!\is_array($fonts)) {
            throw new ThemeException(\sprintf('%s: "fonts" must list the fonts by key, e.g. "lora: { label: Lora, family: \'"Lora", serif\' }".', $where));
        }

        $list = [];

        foreach ($fonts as $key => $font) {
            $key = (string) $key;
            $family = \is_array($font) ? ($font['family'] ?? null) : null;
            $label = \is_array($font) ? ($font['label'] ?? $key) : null;

            if (1 !== preg_match(self::TOKEN_NAME, $key) || !\is_string($family) || '' === trim($family) || 1 === preg_match(self::UNSAFE, $family) || !\is_string($label)) {
                throw new ThemeException(\sprintf('%s: the font "%s" needs a lower case key, a "label" and a "family" without ; { } < > or a backslash.', $where, $key));
            }

            $list[$key] = new Font($key, $label, trim($family));
        }

        return $list;
    }

    /**
     * @param array<mixed> $token
     */
    private static function definition(string $name, array $token, string $where): TokenDefinition
    {
        $unknown = array_diff(array_map(strval(...), array_keys($token)), self::DESCRIPTION);
        $type = TokenType::tryFrom(\is_string($token['type']) ? $token['type'] : '');
        $label = $token['label'] ?? $name;
        $contrast = $token['contrast'] ?? null;
        $unit = $token['unit'] ?? (TokenType::Length === $type ? 'px' : null);

        if ([] !== $unknown) {
            throw new ThemeException(\sprintf('%s: the token "%s" contains unknown keys: %s. Allowed are %s.', $where, $name, implode(', ', $unknown), implode(', ', self::DESCRIPTION)));
        }

        if (null === $type) {
            throw new ThemeException(\sprintf('%s: the token "%s" needs a type: %s.', $where, $name, implode(', ', array_column(TokenType::cases(), 'value'))));
        }

        if (!\is_string($label) || (null !== $contrast && !\is_string($contrast)) || (null !== $unit && !\in_array($unit, ['px', 'rem'], true))) {
            throw new ThemeException(\sprintf('%s: the token "%s" needs a text as label and contrast and px or rem as unit.', $where, $name));
        }

        $numbers = [];

        foreach (['min', 'max', 'step'] as $key) {
            $number = $token[$key] ?? null;

            if (null !== $number && !\is_int($number) && !\is_float($number)) {
                throw new ThemeException(\sprintf('%s: "%s" of the token "%s" must be a number.', $where, $key, $name));
            }

            $numbers[$key] = null === $number ? null : (float) $number;
        }

        $options = [];

        foreach (\is_array($token['options'] ?? null) ? $token['options'] : [] as $key => $option) {
            $value = \is_array($option) ? ($option['value'] ?? null) : $option;
            $text = \is_array($option) ? ($option['label'] ?? (string) $key) : (string) $key;

            if (!\is_string($value) || '' === trim($value) || 1 === preg_match(self::UNSAFE, $value) || !\is_string($text)) {
                throw new ThemeException(\sprintf('%s: the option "%s" of the token "%s" needs a "label" and a CSS "value" without ; { } < > or a backslash.', $where, $key, $name));
            }

            $options[(string) $key] = ['label' => $text, 'value' => trim($value)];
        }

        if (TokenType::Choice === $type && [] === $options) {
            throw new ThemeException(\sprintf('%s: the token "%s" is a choice and needs "options".', $where, $name));
        }

        return new TokenDefinition($name, $type, $label, $contrast, $numbers['min'], $numbers['max'], $numbers['step'], $unit, $options);
    }

    /**
     * @param array<mixed>                   $tokens
     * @param array<string, TokenDefinition> $definitions Filled with the tokens that have a type
     *
     * @return array<string, string>
     */
    private static function flatten(array $tokens, string $prefix, string $where, array &$definitions): array
    {
        $flat = [];

        foreach ($tokens as $key => $value) {
            $key = (string) $key;

            if (1 !== preg_match(self::TOKEN_NAME, $key)) {
                throw new ThemeException(\sprintf('%s: the token "%s%s" must be lower case with dashes, e.g. "font-size".', $where, $prefix, $key));
            }

            $name = $prefix . $key;

            if (\is_array($value) && \array_key_exists('type', $value)) {
                $definitions[$name] = self::definition($name, $value, $where);
                $value = $value['value'] ?? null;
            } elseif (\is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $name . '-', $where, $definitions)];

                continue;
            }

            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                throw new ThemeException(\sprintf('%s: the token "%s" must be text or a number.', $where, $name));
            }

            $value = trim((string) $value);

            if ('' === $value || 1 === preg_match(self::UNSAFE, $value)) {
                throw new ThemeException(\sprintf('%s: the token "%s" must not be empty or contain ; { } < > or a backslash.', $where, $name));
            }

            $flat[$name] = $value;
        }

        return $flat;
    }
}
