<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;

/**
 * The languages of the website from "languages:" in config/app.yaml. The
 * first one is the default language:
 *
 *     languages:
 *       de: { title: Deutsch }
 *       en: { title: English }
 *
 * Without the key the website has one language, "en".
 */
final readonly class Languages
{
    public const string CODE_PATTERN = '^[a-z]{2,3}(-[a-z0-9]{2,8})*$';

    /** @var non-empty-array<string, Language> */
    private array $languages;

    /**
     * @param non-empty-list<Language> $languages
     */
    public function __construct(array $languages)
    {
        $byCode = [];

        foreach ($languages as $language) {
            if (1 !== preg_match('/' . self::CODE_PATTERN . '/', $language->code)) {
                throw new ConfigException(\sprintf('"%s" in "languages" is not a language code. Use lower case codes such as "de", "en" or "de-ch".', $language->code));
            }

            $byCode[$language->code] = $language;
        }

        $this->languages = $byCode;
    }

    public static function fromConfig(Config $config): self
    {
        $configured = $config->get('languages');

        if (null === $configured) {
            return new self([new Language('en', 'English')]);
        }

        if (!\is_array($configured) || [] === $configured || array_is_list($configured)) {
            throw new ConfigException('"languages" in config/app.yaml must list the languages by code, e.g. "de: { title: Deutsch }".');
        }

        $languages = [];

        foreach ($configured as $code => $settings) {
            $title = \is_array($settings) ? ($settings['title'] ?? null) : null;

            if (!\is_string($title) || '' === $title) {
                throw new ConfigException(\sprintf('The language "%s" in config/app.yaml needs a title, e.g. "%s: { title: Deutsch }".', $code, $code));
            }

            $languages[] = new Language((string) $code, $title);
        }

        return new self($languages);
    }

    public function default(): Language
    {
        return $this->languages[array_key_first($this->languages)];
    }

    public function has(string $code): bool
    {
        return isset($this->languages[$code]);
    }

    public function get(string $code): Language
    {
        return $this->languages[$code] ?? throw new ConfigException(\sprintf('There is no language "%s". Languages: %s.', $code, implode(', ', $this->codes())));
    }

    /**
     * @return non-empty-list<string>
     */
    public function codes(): array
    {
        return array_keys($this->languages);
    }

    /**
     * @return non-empty-list<Language>
     */
    public function all(): array
    {
        return array_values($this->languages);
    }
}
