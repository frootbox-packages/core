<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Language;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Page\Slug;

/**
 * The languages of the website from "languages:" in config/app.yaml. The
 * first one is the default language (decisions 0099 and 0105):
 *
 *     languages:
 *       de: { title: Deutsch }                    # no prefix
 *       en: { title: English }                    # prefix "en", falls back to de
 *       fr: { title: Français, fallback: false }  # no page without translation
 *       # en: { title: English, domain: example.com, prefix: '' }
 *
 * Without the key the website has one language, "en".
 */
final readonly class Languages
{
    public const string CODE_PATTERN = '^[a-z]{2,3}(-[a-z0-9]{2,8})*$';

    private const string DOMAIN_PATTERN = '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$';

    /** @var non-empty-array<string, Language> */
    private array $languages;

    /**
     * @param non-empty-list<Language> $languages
     */
    public function __construct(array $languages)
    {
        $byCode = [];
        $places = [];

        foreach ($languages as $language) {
            if (1 !== preg_match('/' . self::CODE_PATTERN . '/', $language->code)) {
                throw new ConfigException(\sprintf('"%s" in "languages" is not a language code. Use lower case codes such as "de", "en" or "de-ch".', $language->code));
            }

            if ('' !== $language->prefix && (!Slug::isValid($language->prefix) || \in_array($language->prefix, Slug::RESERVED, true))) {
                throw new ConfigException(\sprintf('The prefix "%s" of the language "%s" must be lower case a-z, 0-9 and hyphens and not one of %s.', $language->prefix, $language->code, implode(', ', Slug::RESERVED)));
            }

            if (null !== $language->domain && 1 !== preg_match('/' . self::DOMAIN_PATTERN . '/', $language->domain)) {
                throw new ConfigException(\sprintf('The domain "%s" of the language "%s" must be a lower case host without scheme or path, e.g. "example.com".', $language->domain, $language->code));
            }

            $place = ($language->domain ?? '') . '/' . $language->prefix;

            if (isset($places[$place])) {
                throw new ConfigException(\sprintf('The languages "%s" and "%s" have the same %s. Give one of them a prefix or a domain of its own.', $places[$place], $language->code, null === $language->domain ? 'prefix' : 'domain and prefix'));
            }

            $places[$place] = $language->code;
            $byCode[$language->code] = $language;
        }

        foreach ($byCode as $language) {
            $this->checkFallback($language, $byCode);
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
        $default = null;

        foreach ($configured as $code => $settings) {
            $code = (string) $code;
            $default ??= $code;
            $settings = \is_array($settings) ? $settings : [];
            $title = $settings['title'] ?? null;

            if (!\is_string($title) || '' === $title) {
                throw new ConfigException(\sprintf('The language "%s" in config/app.yaml needs a title, e.g. "%s: { title: Deutsch }".', $code, $code));
            }

            $prefix = $settings['prefix'] ?? ($code === $default || isset($settings['domain']) ? '' : $code);
            $domain = $settings['domain'] ?? null;
            $fallback = \array_key_exists('fallback', $settings) ? $settings['fallback'] : ($code === $default ? null : $default);

            if (!\is_string($prefix) || (null !== $domain && !\is_string($domain)) || (false !== $fallback && null !== $fallback && !\is_string($fallback))) {
                throw new ConfigException(\sprintf('The language "%s" in config/app.yaml: "prefix" and "domain" must be text, "fallback" a language code or false.', $code));
            }

            $languages[] = new Language($code, $title, trim($prefix, '/'), null === $domain ? null : strtolower($domain), false === $fallback ? null : $fallback);
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

    /**
     * The language and its fallbacks in order, e.g. ["fr", "en", "de"].
     *
     * @return non-empty-list<string>
     */
    public function contentLanguages(string $code): array
    {
        $codes = [$code];

        for ($next = $this->get($code)->fallback; null !== $next; $next = $this->languages[$next]->fallback ?? null) {
            $codes[] = $next;
        }

        return $codes;
    }

    /**
     * Prefixes in use, reserved as slugs directly below the start page.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn(Language $language): string => $language->prefix, $this->languages), static fn(string $prefix): bool => '' !== $prefix)));
    }

    /**
     * The languages reachable on a host, e.g. "example.com" or
     * "localhost:8000": those with this domain, otherwise those without a
     * domain, otherwise the default language.
     *
     * @return non-empty-list<Language>
     */
    public function forHost(string $host): array
    {
        $host = strtolower($host);
        $own = array_values(array_filter($this->languages, static fn(Language $language): bool => null !== $language->domain && ($language->domain === $host || $language->domain === explode(':', $host)[0])));

        if ([] !== $own) {
            return $own;
        }

        $shared = array_values(array_filter($this->languages, static fn(Language $language): bool => null === $language->domain));

        return [] === $shared ? [$this->default()] : $shared;
    }

    /**
     * The language of a request and the page path in it, e.g.
     * ["en", "about-us/team"] for "/en/about-us/team". Null if no
     * language on this host fits, e.g. "/" when all have a prefix.
     *
     * @return array{Language, string}|null
     */
    public function match(string $host, string $path): ?array
    {
        $path = trim($path, '/');
        [$first] = explode('/', $path, 2);
        $candidates = $this->forHost($host);

        foreach ($candidates as $language) {
            if ('' !== $language->prefix && $language->prefix === $first) {
                return [$language, ltrim(substr($path, \strlen($first)), '/')];
            }
        }

        foreach ($candidates as $language) {
            if ('' === $language->prefix) {
                return [$language, $path];
            }
        }

        return null;
    }

    /**
     * The best language on a host for an Accept-Language header such as
     * "de-CH,de;q=0.9,en;q=0.8", otherwise the first one.
     */
    public function preferred(string $host, string $acceptLanguage): Language
    {
        $candidates = $this->forHost($host);
        $wishes = [];

        foreach (explode(',', strtolower($acceptLanguage)) as $index => $part) {
            $pieces = explode(';', trim($part));
            $quality = 1.0;

            foreach (\array_slice($pieces, 1) as $parameter) {
                if (1 === preg_match('/^\s*q=([0-9.]+)\s*$/', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }

            if ('' !== $pieces[0] && $quality > 0) {
                $wishes[] = [$quality, -$index, $pieces[0]];
            }
        }

        rsort($wishes);

        foreach ($wishes as [, , $wish]) {
            $match = array_find($candidates, static fn(Language $language): bool => $language->code === $wish)
                ?? array_find($candidates, static fn(Language $language): bool => explode('-', $language->code)[0] === explode('-', $wish)[0]);

            if (null !== $match) {
                return $match;
            }
        }

        return $candidates[0];
    }

    /**
     * @param array<string, Language> $languages
     */
    private function checkFallback(Language $language, array $languages): void
    {
        $seen = [$language->code => true];

        for ($next = $language->fallback; null !== $next; $next = $languages[$next]->fallback) {
            if (!isset($languages[$next])) {
                throw new ConfigException(\sprintf('The language "%s" falls back to "%s", which is not a language of the website.', $language->code, $next));
            }

            if (isset($seen[$next])) {
                throw new ConfigException(\sprintf('The fallbacks of the language "%s" go in a circle.', $language->code));
            }

            $seen[$next] = true;
        }
    }
}
