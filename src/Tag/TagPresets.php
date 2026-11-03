<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Plugin\Spaces;
use Frootbox\Core\ProjectPaths;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The tags an agency sets out in config/tags.yaml and extensions
 * register (decisions 0233, 0239): everywhere, for a type of object and
 * for one space of it. A type or a space can be closed: editors choose
 * only from its presets. A space is named by its id or its title.
 *
 *     # config/tags.yaml
 *     tags:
 *       - { de: Nachhaltigkeit, en: Sustainability }
 *       - { key: featured, internal: true }
 *     types:
 *       frootbox/news/article:
 *         - { de: Presse, en: Press }
 *       frootbox/jobs/job:
 *         closed: true
 *         tags: [{ de: Pflege, en: Care }]
 *         spaces:
 *           Ausbildung: [{ de: Duales Studium }]
 */
final class TagPresets
{
    public const string FILE = 'tags.yaml';

    public const string KEY_PATTERN = '^[a-z0-9][a-z0-9-]*$';

    /** @var array{presets: list<TagPreset>, scopes: list<TagScope>}|null */
    private ?array $config = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly ProjectPaths $paths,
        private readonly Languages $languages,
        private readonly Spaces $spaces,
    ) {}

    /**
     * Every preset, from the configuration first.
     *
     * @return list<TagPreset>
     */
    public function all(): array
    {
        return [...$this->config()['presets'], ...$this->registry->items(TagPreset::class)];
    }

    /**
     * The presets for objects of a type in a space: those for everywhere,
     * for the type and for the space, each key once.
     *
     * @return list<TagPreset>
     */
    public function for(string $type, ?string $space = null): array
    {
        $result = [];

        foreach ($this->all() as $preset) {
            if ((null === $preset->type || ($preset->type === $type && (null === $preset->space || $this->isSpace($type, $preset->space, $space)))) && !isset($result[$preset->key])) {
                $result[$preset->key] = $preset;
            }
        }

        return array_values($result);
    }

    public function byKey(string $key): ?TagPreset
    {
        return array_find($this->all(), static fn(TagPreset $preset): bool => $preset->key === $key);
    }

    /**
     * Whether editors choose only from the presets here. A rule for the
     * space wins over the one for the type.
     */
    public function closed(string $type, ?string $space = null): bool
    {
        $closed = false;
        $scopes = [...$this->config()['scopes'], ...$this->registry->items(TagScope::class)];

        foreach ($scopes as $scope) {
            if ($scope->type === $type && null === $scope->space) {
                $closed = $scope->closed;
            }
        }

        foreach ($scopes as $scope) {
            if ($scope->type === $type && null !== $scope->space && $this->isSpace($type, $scope->space, $space)) {
                $closed = $scope->closed;
            }
        }

        return $closed;
    }

    /**
     * Whether the name of a space in a preset (id or title) is the space
     * of an object.
     */
    private function isSpace(string $type, string $name, ?string $space): bool
    {
        $space ??= Space::DEFAULT;

        if ($name === $space) {
            return true;
        }

        if (Space::DEFAULT === $space) {
            return false;
        }

        $row = $this->spaces->fetchById($space);

        return null !== $row && $row->type === $type && mb_strtolower($row->title) === mb_strtolower($name);
    }

    /**
     * @return array{presets: list<TagPreset>, scopes: list<TagScope>}
     */
    private function config(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }

        $file = $this->paths->config(self::FILE);

        if (!is_file($file)) {
            return $this->config = ['presets' => [], 'scopes' => []];
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new ConfigException(\sprintf('config/%s is no valid YAML: %s', self::FILE, $exception->getMessage()), 0, $exception);
        }

        $data = \is_array($data) ? $data : [];

        // A typo would otherwise go unnoticed, e.g. spaces outside a type.
        if (array_diff(array_keys($data), ['tags', 'types']) !== []) {
            throw new ConfigException(\sprintf('config/%s knows only tags and types; spaces go below their type: types: { frootbox/jobs/job: { spaces: { Ausbildung: [...] } } }.', self::FILE));
        }

        $presets = $this->entries($data['tags'] ?? [], null, null, 'tags');
        $scopes = [];
        $types = \is_array($data['types'] ?? null) ? $data['types'] : [];

        foreach ($types as $type => $rule) {
            $type = (string) $type;
            [$list, $closed, $spaces] = $this->rule($rule, 'types.' . $type);
            $presets = [...$presets, ...$this->entries($list, $type, null, 'types.' . $type)];

            if (null !== $closed) {
                $scopes[] = new TagScope($type, null, $closed);
            }

            foreach ($spaces as $space => $spaceRule) {
                $space = (string) $space;
                [$spaceList, $spaceClosed] = $this->rule($spaceRule, 'types.' . $type . '.spaces.' . $space);
                $presets = [...$presets, ...$this->entries($spaceList, $type, $space, 'types.' . $type . '.spaces.' . $space)];

                if (null !== $spaceClosed) {
                    $scopes[] = new TagScope($type, $space, $spaceClosed);
                }
            }
        }

        return $this->config = ['presets' => $presets, 'scopes' => $scopes];
    }

    /**
     * A list of presets or {tags, closed, spaces}.
     *
     * @return array{mixed, bool|null, array<mixed>}
     */
    private function rule(mixed $rule, string $at): array
    {
        if (\is_array($rule) && array_is_list($rule)) {
            return [$rule, null, []];
        }

        if (!\is_array($rule) || array_diff(array_keys($rule), ['tags', 'closed', 'spaces']) !== []) {
            throw new ConfigException(\sprintf('%s in config/%s must be a list of tags or have tags, closed and spaces, e.g. { closed: true, tags: [{ de: Pflege }] }.', $at, self::FILE));
        }

        $closed = $rule['closed'] ?? null;

        if (null !== $closed && !\is_bool($closed)) {
            throw new ConfigException(\sprintf('%s.closed in config/%s must be true or false.', $at, self::FILE));
        }

        return [$rule['tags'] ?? [], $closed, \is_array($rule['spaces'] ?? null) ? $rule['spaces'] : []];
    }

    /**
     * @return list<TagPreset>
     */
    private function entries(mixed $list, ?string $type, ?string $space, string $at): array
    {
        if (!\is_array($list) || !array_is_list($list)) {
            throw new ConfigException(\sprintf('%s in config/%s must be a list, e.g. [{ de: Nachhaltigkeit, en: Sustainability }].', $at, self::FILE));
        }

        $presets = [];

        foreach ($list as $index => $entry) {
            $entry = \is_string($entry) ? [$this->languages->default()->code => $entry] : $entry;
            $labels = [];
            $key = null;
            $internal = false;

            foreach (\is_array($entry) ? $entry : [] as $name => $value) {
                $name = (string) $name;

                if ('key' === $name && \is_string($value)) {
                    $key = $value;
                } elseif ('internal' === $name && \is_bool($value)) {
                    $internal = $value;
                } elseif ($this->languages->has($name) && \is_string($value) && '' !== trim($value)) {
                    $labels[$name] = trim($value);
                } else {
                    throw new ConfigException(\sprintf('%s.%d.%s in config/%s is neither key, internal nor a language of the website.', $at, $index, $name, self::FILE));
                }
            }

            if (null === $key && [] !== $labels) {
                $language = array_key_first($labels);
                $key = Slug::latin($labels[$language], $language);
            }

            if (null === $key || 1 !== preg_match('/' . self::KEY_PATTERN . '/', $key)) {
                throw new ConfigException(\sprintf('%s.%d in config/%s needs a label or a key of a-z, 0-9 and hyphens, e.g. { key: featured, internal: true }.', $at, $index, self::FILE));
            }

            $presets[] = new TagPreset($key, $labels, $internal, $type, $space);
        }

        return $presets;
    }
}
