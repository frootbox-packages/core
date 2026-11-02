<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\Core\Extension\Extensions;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * All help pages of the installation (decisions 0215, 0218): the files
 * help/<language>/<name>.md of the core and of every extension, read
 * anew on every request, so "type and F5" works, and the pages about
 * this installation. English is the source: a translation without an
 * English file is not shown.
 */
final class HelpLibrary
{
    public const string FOLDER = 'help';

    public const string SOURCE = 'en';

    private const string NAME = '[a-z0-9][a-z0-9-]*';

    /** @var array<string, HelpTopic>|null */
    private ?array $topics = null;

    /** The fingerprint seen last, to notice changed files. */
    private ?string $seen = null;

    public function __construct(
        private readonly Extensions $extensions,
        private readonly InstallationHelp $installation,
    ) {}

    /**
     * @return array<string, HelpTopic> by id, sorted by order and id
     */
    public function all(): array
    {
        if (null === $this->topics) {
            $topics = $this->read(null, \dirname(__DIR__, 2) . '/' . self::FOLDER);

            foreach ($this->extensions as $package) {
                $topics = [...$topics, ...$this->read($package->name, $package->path . '/' . self::FOLDER)];
            }

            foreach ($this->installation->topics() as $topic) {
                $topics[$topic->id] ??= $topic;
            }

            uasort($topics, static fn(HelpTopic $a, HelpTopic $b): int => [$a->order, $a->id] <=> [$b->order, $b->id]);
            $this->topics = $topics;
        }

        return $this->topics;
    }

    public function get(string $id): ?HelpTopic
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * When any help file last changed, to know when the search must read
     * them again.
     */
    public function fingerprint(): string
    {
        $files = [];

        foreach ($this->directories() as $directory) {
            foreach (self::glob($directory . '/*/*.md') as $file) {
                $files[] = $file . ':' . (int) filemtime($file);
            }
        }

        // The blocks of the theme are part of the help as well.
        $files[] = $this->extensions->fingerprint;
        $fingerprint = hash('xxh128', implode("\n", $files));

        // Changed files are read again, also within the same request.
        if (null !== $this->seen && $this->seen !== $fingerprint) {
            $this->topics = null;
        }

        return $this->seen = $fingerprint;
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        $directories = [\dirname(__DIR__, 2) . '/' . self::FOLDER];

        foreach ($this->extensions as $package) {
            $directories[] = $package->path . '/' . self::FOLDER;
        }

        return array_values(array_filter($directories, is_dir(...)));
    }

    /**
     * @return array<string, HelpTopic>
     */
    private function read(?string $package, string $directory): array
    {
        if (!is_dir($directory . '/' . self::SOURCE)) {
            return [];
        }

        $topics = [];

        foreach (self::glob($directory . '/' . self::SOURCE . '/*.md') as $file) {
            $name = basename($file, '.md');

            if (1 !== preg_match('/^' . self::NAME . '$/', $name)) {
                throw new HelpException(\sprintf('The help file %s needs a name of lower case a-z, 0-9 and hyphens.', $file));
            }

            [$head, $markdown] = self::parse($file, true);
            $languages = [self::SOURCE => ['title' => self::title($head, $file), 'markdown' => $markdown]];

            foreach (self::glob($directory . '/*/' . $name . '.md') as $translation) {
                $language = basename(\dirname($translation));

                if (self::SOURCE !== $language) {
                    [$translated, $text] = self::parse($translation, false);
                    $languages[$language] = ['title' => self::title($translated, $translation), 'markdown' => $text];
                }
            }

            $id = null === $package ? $name : $package . '/' . $name;
            $topics[$id] = new HelpTopic(
                id: $id,
                package: $package,
                name: $name,
                about: self::about($head, $file),
                permission: self::permission($head, $file),
                order: \is_int($head['order'] ?? null) ? $head['order'] : 100,
                languages: $languages,
                directory: $directory,
            );
        }

        return $topics;
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return false === $files ? [] : $files;
    }

    /**
     * The YAML head between "---" lines and the Markdown after it.
     *
     * @return array{0: array<mixed>, 1: string}
     */
    private static function parse(string $file, bool $source): array
    {
        $content = (string) file_get_contents($file);

        if (1 !== preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $match)) {
            throw new HelpException(\sprintf('The help file %s must start with a head between two lines "---" with at least "title:".', $file));
        }

        try {
            $head = Yaml::parse($match[1]);
        } catch (ParseException $error) {
            throw new HelpException(\sprintf('The head of the help file %s is not valid YAML: %s', $file, $error->getMessage()), previous: $error);
        }

        if (!\is_array($head)) {
            throw new HelpException(\sprintf('The head of the help file %s must contain key-value pairs.', $file));
        }

        $allowed = $source ? ['title', 'about', 'permission', 'order'] : ['title'];
        $unknown = array_diff(array_map(strval(...), array_keys($head)), $allowed);

        if ([] !== $unknown) {
            throw new HelpException(\sprintf('The head of the help file %s has unknown keys: %s. Allowed are %s%s.', $file, implode(', ', $unknown), implode(', ', $allowed), $source ? '' : '; the rest comes from the English file'));
        }

        return [$head, trim($match[2])];
    }

    /**
     * @param array<mixed> $head
     */
    private static function title(array $head, string $file): string
    {
        $title = $head['title'] ?? null;

        if (!\is_string($title) || '' === trim($title)) {
            throw new HelpException(\sprintf('The help file %s needs a "title:" in its head.', $file));
        }

        return trim($title);
    }

    /**
     * @param array<mixed> $head
     *
     * @return list<string>
     */
    private static function about(array $head, string $file): array
    {
        $about = $head['about'] ?? [];
        $entries = [];

        foreach (\is_array($about) ? $about : [null] as $entry) {
            if (!\is_string($entry) || 1 !== preg_match('/^[a-z]+:\S+$/', $entry)) {
                throw new HelpException(\sprintf('"about" in the help file %s must be a list such as [screen:backend.pages, action:page.publish, block:acme/site/Teaser].', $file));
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<mixed> $head
     */
    private static function permission(array $head, string $file): ?string
    {
        $permission = $head['permission'] ?? null;

        if (null !== $permission && !\is_string($permission)) {
            throw new HelpException(\sprintf('"permission" in the help file %s must be the name of an action or query, e.g. page.publish.', $file));
        }

        return $permission;
    }
}
