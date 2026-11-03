<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\I18n\Translator;
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
 *       departments: { title: Abteilung, types: [frootbox/news/article, frootbox/jobs/job] }
 */
final class CategoryGroups
{
    public const string FILE = 'categories.yaml';

    public const string KEY_PATTERN = '^[a-z0-9][a-z0-9-]*$';

    /** @var list<CategoryGroup>|null */
    private ?array $config = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly AdminGroups $admin,
        private readonly ProjectPaths $paths,
        private readonly Translator $translator,
        private readonly Plugins $plugins,
    ) {}

    /**
     * @return list<CategoryGroup>
     */
    public function all(): array
    {
        $groups = [...$this->registry->items(CategoryGroup::class), ...$this->config()];

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
     * @return list<CategoryGroup>
     */
    private function config(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }

        $file = $this->paths->config(self::FILE);

        if (!is_file($file)) {
            return $this->config = [];
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new ConfigException(\sprintf('config/%s is no valid YAML: %s', self::FILE, $exception->getMessage()), 0, $exception);
        }

        $groups = [];
        $entries = \is_array($data) && \is_array($data['groups'] ?? null) ? $data['groups'] : [];

        foreach ($entries as $key => $entry) {
            $key = (string) $key;
            $title = \is_array($entry) && \is_string($entry['title'] ?? null) ? trim($entry['title']) : '';
            $types = \is_array($entry) && \is_array($entry['types'] ?? null) ? array_values(array_filter($entry['types'], \is_string(...))) : [];

            if (1 !== preg_match('/' . self::KEY_PATTERN . '/', $key) || '' === $title || [] === $types) {
                throw new ConfigException(\sprintf('The group "%s" in config/%s needs a name of a-z, 0-9 and hyphens, a title and a list of types, e.g. departments: { title: Abteilung, types: [frootbox/news/article] }.', $key, self::FILE));
            }

            $groups[] = new CategoryGroup($key, $title, $types, GroupSource::Config);
        }

        return $this->config = $groups;
    }
}
