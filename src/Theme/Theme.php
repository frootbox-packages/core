<?php

/*
 * SPDX-License-Identifier: MIT
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
 *         noto-sans:
 *           label: Noto Sans
 *           family: '"Noto Sans", sans-serif'
 *           files:
 *             - { file: assets/fonts/noto-sans-latin.woff2, weight: 100 900, range: latin, preload: true }
 *           fallback: { local: Arial, size-adjust: 105.37%, ascent: 101.45%, descent: 27.81% }
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
    private const array KEYS = ['parent', 'tokens', 'fonts', 'scripts', 'fields', 'navigations', 'blocks', 'layouts', 'design'];

    private const array FONT = ['label', 'family', 'files', 'fallback'];

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
     * @param array<string, Frame>|null      $places      Frames of the design page, null if a parent names them (decision 0208)
     * @param array<string, Frame>|null      $surfaces    As $places
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
        public ?array $places = null,
        public ?array $surfaces = null,
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

        return new self($package->name, $package->path, $parent, $flat, $navigations, $definitions, self::fonts($package->theme['fonts'] ?? [], $where, $package->path), $fields, self::scripts($package->theme['scripts'] ?? [], $where), Offer::from($package->theme['blocks'] ?? null, 'blocks', $where), Offer::from($package->theme['layouts'] ?? null, 'layouts', $where), Frame::list($package->theme['design'] ?? null, Frame::PLACES, $package->name, $where), Frame::list($package->theme['design'] ?? null, Frame::SURFACES, $package->name, $where));
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
    private static function fonts(mixed $fonts, string $where, string $root): array
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

            $unknown = array_diff(array_map(strval(...), array_keys($font)), self::FONT);

            if ([] !== $unknown) {
                throw new ThemeException(\sprintf('%s: the font "%s" contains unknown keys: %s. Allowed are %s.', $where, $key, implode(', ', $unknown), implode(', ', self::FONT)));
            }

            $family = trim($family);
            $files = self::fontFiles($font['files'] ?? [], $where . ': the font "' . $key . '"', $root);
            $fallback = self::fallback($font['fallback'] ?? null, $where . ': the font "' . $key . '"');
            $face = '';
            $rest = '';

            if ([] !== $files) {
                // The first name in the family is the one the files give, e.g. "Noto Sans".
                $face = 1 === preg_match('/^([^,]+)(?:,|$)/', $family, $match) ? trim($match[1], " \"'") : '';
                $rest = trim(substr($family, \strlen($match[0] ?? '')));

                if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]*$/', $face)) {
                    throw new ThemeException(\sprintf('%s: the font "%s" with files needs its name first in "family", e.g. \'"Noto Sans", sans-serif\'.', $where, $key));
                }
            } elseif (null !== $fallback) {
                throw new ThemeException(\sprintf('%s: the font "%s" has a "fallback" but no "files" to fall back from.', $where, $key));
            }

            $font = new Font($key, $label, $family, $face, $files, $fallback);

            if (null !== $fallback) {
                // The adjusted fallback right after the font, before the others.
                $font = new Font($key, $label, \sprintf('"%s", "%s"%s', $face, $font->fallbackFace(), '' === $rest ? '' : ', ' . $rest), $face, $files, $fallback);
            }

            $list[$key] = $font;
        }

        return $list;
    }

    /**
     * @return list<FontFile>
     */
    private static function fontFiles(mixed $files, string $where, string $root): array
    {
        if (!\is_array($files) || !array_is_list($files)) {
            throw new ThemeException(\sprintf('%s: "files" must be a list, e.g. "- { file: assets/fonts/lora.woff2, weight: 400 700, range: latin }".', $where));
        }

        $list = [];

        foreach ($files as $index => $file) {
            $at = \sprintf('%s, file %d', $where, $index + 1);
            $path = \is_array($file) ? ($file['file'] ?? null) : null;

            if (!\is_array($file) || !\is_string($path) || 1 !== preg_match('#^assets/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\.(?:woff2|woff|ttf|otf)$#', $path) || str_contains($path, '..')) {
                throw new ThemeException(\sprintf('%s: "file" must be a .woff2, .woff, .ttf or .otf below assets/, e.g. assets/fonts/lora.woff2.', $at));
            }

            if (!is_file($root . '/' . $path)) {
                throw new ThemeException(\sprintf('%s: %s does not exist.', $at, $path));
            }

            $unknown = array_diff(array_map(strval(...), array_keys($file)), ['file', 'weight', 'style', 'range', 'preload']);
            $weight = $file['weight'] ?? '400';
            $weight = \is_int($weight) ? (string) $weight : $weight;
            $style = $file['style'] ?? 'normal';
            $range = $file['range'] ?? null;
            $resolved = \is_string($range) ? UnicodeRange::of($range) : null;
            $preload = $file['preload'] ?? false;

            if ([] !== $unknown) {
                throw new ThemeException(\sprintf('%s contains unknown keys: %s. Allowed are file, weight, style, range, preload.', $at, implode(', ', $unknown)));
            }

            if (!\is_string($weight) || 1 !== preg_match('/^\d{1,4}(?: \d{1,4})?$/', $weight)) {
                throw new ThemeException(\sprintf('%s: "weight" must be a weight such as 400 or a range such as "100 900".', $at));
            }

            if (!\in_array($style, ['normal', 'italic'], true) || !\is_bool($preload)) {
                throw new ThemeException(\sprintf('%s: "style" is normal or italic, "preload" true or false.', $at));
            }

            if (null !== $range && null === $resolved) {
                throw new ThemeException(\sprintf('%s: "range" must be one of %s or a unicode-range such as "U+0000-00FF".', $at, implode(', ', array_keys(UnicodeRange::NAMED))));
            }

            $list[] = new FontFile($root . '/' . $path, $weight, $style, $resolved, $preload);
        }

        return $list;
    }

    private static function fallback(mixed $fallback, string $where): ?FontFallback
    {
        if (null === $fallback) {
            return null;
        }

        $keys = ['local', 'size-adjust', 'ascent', 'descent', 'line-gap'];
        $local = \is_array($fallback) ? ($fallback['local'] ?? null) : null;
        $local = \is_string($local) ? [$local] : $local;
        $example = 'e.g. "{ local: Arial, size-adjust: 105%, ascent: 101%, descent: 28% }"';

        if (!\is_array($fallback) || [] !== array_diff(array_map(strval(...), array_keys($fallback)), $keys) || !\is_array($local) || [] === $local || !array_is_list($local)) {
            throw new ThemeException(\sprintf('%s: "fallback" takes %s with at least "local", %s.', $where, implode(', ', $keys), $example));
        }

        $names = [];

        foreach ($local as $name) {
            if (!\is_string($name) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]*$/', $name)) {
                throw new ThemeException(\sprintf('%s: "fallback.local" names fonts on the device, %s.', $where, $example));
            }

            $names[] = $name;
        }

        $percent = [];

        foreach (['size-adjust', 'ascent', 'descent', 'line-gap'] as $key) {
            $value = $fallback[$key] ?? null;

            if (null !== $value && (!\is_string($value) || 1 !== preg_match('/^\d{1,3}(?:\.\d+)?%$/', $value))) {
                throw new ThemeException(\sprintf('%s: "fallback.%s" must be a percentage such as "105.37%%".', $where, $key));
            }

            $percent[$key] = $value;
        }

        return new FontFallback($names, $percent['size-adjust'], $percent['ascent'], $percent['descent'], $percent['line-gap']);
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
