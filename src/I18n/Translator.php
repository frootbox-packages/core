<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Cache\CacheFile;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\ProjectPaths;
use Locale;
use Stringable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The texts of the backend and the labels of extensions in the language
 * of the user (decisions 0110, 0114, 0161). The text as written is the
 * key, English in official packages; each package has its translations
 * in translations/<language>.yaml:
 *
 *     "Sign in": Anmelden
 *     "Hello {name}": "Hallo {name}"
 *     "{count} page": { one: "{count} Seite", other: "{count} Seiten" }
 *
 * The language is the one the signed-in user chose, else
 * "backend.language", else the first language of the website if there
 * are texts for it, else English. A missing text shows as written.
 */
final class Translator
{
    public const string FOLDER = 'translations';

    public const string ENGLISH = 'en';

    private const string CACHE = 'cache/translations-%s.cache';

    /** @var array<string, string> The language by actor, e.g. "user:3" => "de" */
    private array $languages = [];

    /** @var array<string, Catalogue> By language */
    private array $catalogues = [];

    public function __construct(
        private readonly Config $config,
        private readonly Languages $siteLanguages,
        private readonly Extensions $extensions,
        private readonly ProjectPaths $paths,
        private readonly CurrentContext $context,
        private readonly UserLanguages $userLanguages,
    ) {}

    /**
     * The language of the backend for the current user, e.g. "de" or "en".
     */
    public function language(): string
    {
        $actor = $this->context->get()->actor;
        $key = $actor->type->value . ':' . $actor->id;

        if (isset($this->languages[$key])) {
            return $this->languages[$key];
        }

        $chosen = ActorType::User === $actor->type && null !== $actor->id ? $this->userLanguages->of($actor->id) : null;

        return $this->languages[$key] = null !== $chosen && $this->offers($chosen) ? $chosen : $this->standard();
    }

    /**
     * The language without a choice of the user: "backend.language", else
     * the first language of the website if there are texts for it, else
     * English.
     */
    public function standard(): string
    {
        $configured = $this->config->get('backend.language');

        if (\is_string($configured) && '' !== $configured) {
            return $configured;
        }

        $site = $this->siteLanguages->default()->code;

        return [] !== $this->files($site) ? $site : self::ENGLISH;
    }

    /**
     * The languages a user may choose: English and every language some
     * package has texts for, by code with the name in that language.
     * Themes do not count, their texts are mostly for visitors (decision
     * 0168), e.g. an Arabic theme does not translate the backend.
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        $codes = [self::ENGLISH];

        foreach ($this->extensions as $package) {
            if (null !== $package->theme) {
                continue;
            }

            $files = glob($package->path . '/' . self::FOLDER . '/*.yaml');

            foreach (false === $files ? [] : $files as $file) {
                $codes[] = basename($file, '.yaml');
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);
        $names = [];

        foreach ($codes as $code) {
            $names[$code] = self::name($code);
        }

        return $names;
    }

    /**
     * Whether a user may choose this language.
     */
    public function offers(string $language): bool
    {
        return \array_key_exists($language, $this->available());
    }

    /**
     * The name of a language in that language, e.g. "Deutsch" for "de".
     */
    public static function name(string $code): string
    {
        $name = Locale::getDisplayName($code, $code);

        return \is_string($name) ? mb_convert_case(mb_substr($name, 0, 1), \MB_CASE_TITLE) . mb_substr($name, 1) : $code;
    }

    /**
     * A text in the language of the backend, with {placeholders} filled
     * in. For a text with a plural, pass the singular and plural and a
     * "count".
     *
     * @param string|array{string, string}                         $text
     * @param array<string, bool|float|int|string|Stringable|null> $parameters
     */
    public function get(string|array $text, array $parameters = []): string
    {
        return $this->translate($text, $parameters, null, $this->language());
    }

    /**
     * A text of a theme for visitors in the language of the page, e.g.
     * "Skip to content" (decision 0162): the translation of the first of
     * the packages that has one, then that of any package. Without one
     * it shows as written, English in official themes.
     *
     * @param string|array{string, string}                         $text
     * @param list<string>                                         $packages
     * @param array<string, bool|float|int|string|Stringable|null> $parameters
     */
    public function in(string $language, string|array $text, array $packages = [], array $parameters = []): string
    {
        return $this->translate($text, $parameters, $packages, $language);
    }

    /**
     * A label from the manifest or schema of a package, e.g. the title of
     * a block: the translation of that package first, then that of any
     * package (decision 0161); of a theme only if a theme asks (decision
     * 0172).
     *
     * @param string|list<string>|null $package One or several, the first that has it wins
     */
    public function label(string $text, string|array|null $package = null): string
    {
        return '' === $text ? '' : $this->translate($text, [], $package, $this->language());
    }

    /**
     * @param string|array{string, string}                         $text
     * @param array<string, bool|float|int|string|Stringable|null> $parameters
     * @param string|list<string>|null                             $packages
     */
    private function translate(string|array $text, array $parameters, string|array|null $packages, string $language): string
    {
        $count = $parameters['count'] ?? null;
        $one = !(\is_int($count) || \is_float($count)) || 1.0 === (float) $count;
        $key = \is_array($text) ? $text[0] : $text;
        $message = $this->catalogue($language)->find($key, $packages);

        if (\is_array($message)) {
            $message = $one ? $message['one'] : $message['other'];
        } elseif (null === $message) {
            $message = \is_array($text) ? $text[$one ? 0 : 1] : $text;
        }

        $replace = [];

        foreach ($parameters as $name => $value) {
            $replace['{' . $name . '}'] = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return strtr($message, $replace);
    }

    private function catalogue(string $language): Catalogue
    {
        if (isset($this->catalogues[$language])) {
            return $this->catalogues[$language];
        }

        $files = $this->files($language);
        $fingerprint = implode('|', array_map(static fn(array $file): string => $file['path'] . ':' . filemtime($file['path']), $files));
        $cache = $this->paths->var(\sprintf(self::CACHE, $language));
        $cached = $this->config->environment->isDevelopment() ? null : CacheFile::read($cache, $fingerprint, []);

        if (\is_array($cached)) {
            return $this->catalogues[$language] = Catalogue::fromArray($cached, $cache, $this->themes());
        }

        $packages = [];

        foreach ($files as $file) {
            $packages[$file['package']] = [...$packages[$file['package']] ?? [], ...self::read($file['path'])];
        }

        $catalogue = new Catalogue($packages, $this->themes());

        if (!$this->config->environment->isDevelopment()) {
            CacheFile::write($cache, $fingerprint, $catalogue->toArray());
        }

        return $this->catalogues[$language] = $catalogue;
    }

    /**
     * Names of the packages that are themes: their texts answer only when
     * a theme asks (decision 0172).
     *
     * @return list<string>
     */
    private function themes(): array
    {
        $themes = [];

        foreach ($this->extensions as $package) {
            if (null !== $package->theme) {
                $themes[] = $package->name;
            }
        }

        return $themes;
    }

    /**
     * The translation files of all packages for a language, e.g. "de"
     * and then "de-ch".
     *
     * @return list<array{package: string, path: string}>
     */
    private function files(string $language): array
    {
        $candidates = array_unique([explode('-', $language)[0], $language]);
        $files = [];

        foreach ($this->extensions as $package) {
            foreach ($candidates as $candidate) {
                $file = $package->path . '/' . self::FOLDER . '/' . $candidate . '.yaml';

                if (is_file($file)) {
                    $files[] = ['package' => $package->name, 'path' => $file];
                }
            }
        }

        return $files;
    }

    /**
     * @return array<string, string|array{one: string, other: string}>
     */
    private static function read(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $error) {
            throw new TranslationException(\sprintf('%s is no valid YAML: %s', $file, $error->getMessage()), 0, $error);
        }

        return Catalogue::messagesOf(\is_array($data) ? $data : [], $file);
    }
}
