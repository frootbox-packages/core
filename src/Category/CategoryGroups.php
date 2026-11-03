<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Plugin\Plugins;
use Frootbox\Core\ProjectPaths;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The groups of categories from all three sources (decision 0233): the
 * ones extensions register, the ones in config/categories.yaml and the
 * ones admins create, in this order.
 *
 *     # config/categories.yaml
 *     groups:
 *       departments:
 *         title: Abteilung
 *         types: [frootbox/news/article, frootbox/jobs/job]
 *         categories:
 *           - { de: Kardiologie, en: Cardiology }
 *           - { key: surgery, de: Chirurgie, children: [{ de: Unfallchirurgie }] }
 *       frootbox/news/categories:
 *         categories: [{ de: Presse, en: Press }]
 *
 * A group of an extension named there only gets those presets.
 */
final class CategoryGroups
{
    public const string FILE = 'categories.yaml';

    public const string KEY_PATTERN = '^[a-z0-9][a-z0-9-]*$';

    /** @var array{groups: list<CategoryGroup>, presets: array<string, list<CategoryPreset>>}|null */
    private ?array $config = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly AdminGroups $admin,
        private readonly ProjectPaths $paths,
        private readonly Translator $translator,
        private readonly Plugins $plugins,
        private readonly Languages $languages,
    ) {}

    /**
     * @return list<CategoryGroup>
     */
    public function all(): array
    {
        $config = $this->config();
        $groups = [];

        foreach ($this->registry->items(CategoryGroup::class) as $group) {
            $more = $config['presets'][$group->key] ?? [];
            $groups[] = [] === $more ? $group : new CategoryGroup($group->key, $group->title, $group->types, $group->source, [...$group->presets, ...$more]);
        }

        $groups = [...$groups, ...$config['groups']];

        foreach ($this->admin->fetch(['order' => ['title', 'id']]) as $row) {
            $groups[] = new CategoryGroup($row->id, $row->title, $row->types, GroupSource::Admin);
        }

        return $groups;
    }

    public function get(string $key): ?CategoryGroup
    {
        return array_find($this->all(), static fn(CategoryGroup $group): bool => $group->key === $key);
    }

    /**
     * The groups that apply to a type of object.
     *
     * @return list<CategoryGroup>
     */
    public function forType(string $type): array
    {
        return array_values(array_filter($this->all(), static fn(CategoryGroup $group): bool => $group->appliesTo($type)));
    }

    /**
     * The name of a group for editors, translated for an extension.
     */
    public function title(CategoryGroup $group): string
    {
        return GroupSource::Module === $group->source ? $this->translator->get($group->title) : $group->title;
    }

    /**
     * The types a group can apply to: those of the plugins and of the
     * groups of extensions, each with a name for editors.
     *
     * @return list<array{type: string, label: string}>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->plugins->all() as $plugin) {
            $types[$plugin->type()] = $this->translator->get($plugin->label());
        }

        foreach ($this->registry->items(CategoryGroup::class) as $group) {
            foreach ($group->types as $type) {
                $types[$type] ??= $type;
            }
        }

        $list = [];

        foreach ($types as $type => $label) {
            $list[] = ['type' => $type, 'label' => $label];
        }

        return $list;
    }

    public function typeLabel(string $type): string
    {
        foreach ($this->types() as $known) {
            if ($known['type'] === $type) {
                return $known['label'];
            }
        }

        return $type;
    }

    /**
     * @return array{groups: list<CategoryGroup>, presets: array<string, list<CategoryPreset>>}
     */
    private function config(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }

        $file = $this->paths->config(self::FILE);

        if (!is_file($file)) {
            return $this->config = ['groups' => [], 'presets' => []];
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new ConfigException(\sprintf('config/%s is no valid YAML: %s', self::FILE, $exception->getMessage()), 0, $exception);
        }

        $groups = [];
        $presets = [];
        $entries = \is_array($data) && \is_array($data['groups'] ?? null) ? $data['groups'] : [];
        $registered = array_map(static fn(CategoryGroup $group): string => $group->key, $this->registry->items(CategoryGroup::class));

        foreach ($entries as $key => $entry) {
            $key = (string) $key;
            $list = $this->presets(\is_array($entry) ? $entry['categories'] ?? [] : [], 'groups.' . $key . '.categories');

            if (\in_array($key, $registered, true)) {
                $presets[$key] = $list;

                continue;
            }

            $title = \is_array($entry) && \is_string($entry['title'] ?? null) ? trim($entry['title']) : '';
            $types = \is_array($entry) && \is_array($entry['types'] ?? null) ? array_values(array_filter($entry['types'], \is_string(...))) : [];

            if (1 !== preg_match('/' . self::KEY_PATTERN . '/', $key) || '' === $title || [] === $types) {
                throw new ConfigException(\sprintf('The group "%s" in config/%s needs a name of a-z, 0-9 and hyphens, a title and a list of types, e.g. departments: { title: Abteilung, types: [frootbox/news/article] }, or must be the group of an extension.', $key, self::FILE));
            }

            $groups[] = new CategoryGroup($key, $title, $types, GroupSource::Config, $list);
        }

        return $this->config = ['groups' => $groups, 'presets' => $presets];
    }

    /**
     * Presets of categories: a title as text, or titles by language with
     * a key and children.
     *
     * @return list<CategoryPreset>
     */
    private function presets(mixed $list, string $at): array
    {
        if (!\is_array($list) || !array_is_list($list)) {
            throw new ConfigException(\sprintf('%s in config/%s must be a list, e.g. [{ de: Kurse, en: Courses }].', $at, self::FILE));
        }

        $presets = [];

        foreach ($list as $index => $entry) {
            $entry = \is_string($entry) ? [$this->languages->default()->code => $entry] : $entry;
            $titles = [];
            $key = null;
            $children = [];

            foreach (\is_array($entry) ? $entry : [] as $name => $value) {
                $name = (string) $name;

                if ('key' === $name && \is_string($value)) {
                    $key = $value;
                } elseif ('children' === $name) {
                    $children = $this->presets($value, $at . '.' . $index . '.children');
                } elseif ($this->languages->has($name) && \is_string($value) && '' !== trim($value)) {
                    $titles[$name] = trim($value);
                } else {
                    throw new ConfigException(\sprintf('%s.%d.%s in config/%s is neither key, children nor a language of the website.', $at, $index, $name, self::FILE));
                }
            }

            $first = (string) array_key_first($titles);
            $key ??= [] === $titles ? null : Slug::latin($titles[$first], $first);

            if ([] === $titles || null === $key || 1 !== preg_match('/' . self::KEY_PATTERN . '/', $key)) {
                throw new ConfigException(\sprintf('%s.%d in config/%s needs a title in a language of the website and a key of a-z, 0-9 and hyphens if one is given, e.g. { de: Kurse, en: Courses }.', $at, $index, self::FILE));
            }

            $presets[] = new CategoryPreset($key, $titles, $children);
        }

        return $presets;
    }
}
