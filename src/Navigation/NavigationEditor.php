<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Revision\RevisionStore;

/**
 * The work behind the navigation actions: checks the items against the
 * theme and the pages, saves drafts and publishes them (decision 0106).
 */
final readonly class NavigationEditor
{
    public function __construct(
        private Definitions $definitions,
        private NavigationReader $reader,
        private Navigations $navigations,
        private ItemChecker $checker,
        private RevisionStore $store,
        private Languages $languages,
    ) {}

    /**
     * The navigation as declared by the theme.
     *
     * @throws InvalidInputException
     */
    public function definition(string $action, string $name, string $pointer = '/name'): Definition
    {
        return $this->definitions->get($name) ?? throw new InvalidInputException($action, [$pointer => \sprintf(
            'The theme has no navigation "%s". Navigations: %s.',
            $name,
            [] === $this->definitions->all() ? 'none' : implode(', ', array_keys($this->definitions->all())),
        )]);
    }

    /**
     * Replaces the items of the given languages in the draft. A language
     * set to null is removed, languages left out are kept:
     *
     *     {"de": [{"type": "target", "target": "page:01J…"}], "en": null}
     *
     * @param array<mixed> $items
     *
     * @throws InvalidInputException
     */
    public function update(string $action, string $name, array $items): NavigationDetails
    {
        $definition = $this->definition($action, $name);

        if ([] === $items || array_is_list($items)) {
            throw new InvalidInputException($action, ['/items' => 'must be an object by language code, e.g. {"de": [{"type": "url", "url": "https://…", "label": "Shop"}]}.']);
        }

        $current = $this->reader->current($name);

        foreach ($items as $language => $list) {
            if (null === $list) {
                unset($current[(string) $language]);
            } else {
                $current[(string) $language] = $list;
            }
        }

        return $this->save($action, $definition, $current);
    }

    /**
     * Copies the items of one language into another, with new ids. The
     * items of the other language are replaced.
     *
     * @throws InvalidInputException
     */
    public function copy(string $action, string $name, string $from, string $to): NavigationDetails
    {
        $definition = $this->definition($action, $name);
        $current = $this->reader->current($name);

        if (!isset($current[$from])) {
            throw new InvalidInputException($action, ['/from' => \sprintf('The navigation has no items in "%s". Languages: %s.', $from, [] === $current ? 'none' : implode(', ', array_keys($current)))]);
        }

        if ($from === $to) {
            throw new InvalidInputException($action, ['/to' => 'must be another language than "from".']);
        }

        $current[$to] = self::withoutIds($current[$from]);

        return $this->save($action, $definition, $current);
    }

    /**
     * Publishes the drafts of several navigations as one change.
     *
     * @param list<string> $names
     *
     * @return list<NavigationDetails>
     *
     * @throws InvalidInputException
     */
    public function publish(string $action, array $names): array
    {
        $names = array_values(array_unique($names));

        if ([] === $names) {
            throw new InvalidInputException($action, ['/names' => 'needs at least one navigation, e.g. ["main"].']);
        }

        $errors = [];
        $drafts = [];

        foreach ($names as $index => $name) {
            $at = '/names/' . $index;
            $definition = $this->definitions->get($name);
            $draft = $this->reader->draft($name);

            if (null === $definition) {
                $errors[$at] = \sprintf('The theme has no navigation "%s".', $name);
            } elseif (null === $draft) {
                $errors[$at] = \sprintf('The navigation "%s" has no draft to publish.', $name);
            } else {
                [$checked, $found] = $this->check($definition, $draft);
                $drafts[$name] = [$definition, $checked];

                foreach ($found as $pointer => $message) {
                    $errors[$at . $pointer] = $message;
                }
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $published = [];

        foreach ($drafts as $name => [$definition, $items]) {
            $this->store->saveDraft(NavigationReader::TYPE, $name, ['items' => $items]);
            $this->write($name, $items);
            $this->store->publish(NavigationReader::TYPE, $name);
            $published[] = $this->reader->details($definition);
        }

        return $published;
    }

    /**
     * Removes the draft.
     *
     * @throws InvalidInputException
     */
    public function discard(string $action, string $name): NavigationDetails
    {
        $definition = $this->definition($action, $name);

        if (!$this->store->discard(NavigationReader::TYPE, $name)) {
            throw new InvalidInputException($action, ['/name' => \sprintf('The navigation "%s" has no draft.', $name)]);
        }

        return $this->reader->details($definition);
    }

    /**
     * @param array<string, mixed> $items Items by language
     *
     * @throws InvalidInputException
     */
    private function save(string $action, Definition $definition, array $items): NavigationDetails
    {
        [$checked, $errors] = $this->check($definition, $items);

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $this->store->saveDraft(NavigationReader::TYPE, $definition->name, ['items' => $checked]);

        return $this->reader->details($definition);
    }

    /**
     * @param array<string, mixed> $items Items by language
     *
     * @return array{array<string, list<array<string, mixed>>>, array<string, string>} The items, and messages by JSON pointer
     */
    private function check(Definition $definition, array $items): array
    {
        $checked = [];
        $errors = [];

        foreach ($this->languages->codes() as $language) {
            if (!\array_key_exists($language, $items)) {
                continue;
            }

            [$checked[$language], $found] = $this->checker->check($items[$language], $definition, '/items/' . $language);
            $errors = [...$errors, ...$found];
        }

        foreach (array_diff(array_keys($items), $this->languages->codes()) as $language) {
            $errors['/items/' . $language] = \sprintf('"%s" is not a language of the website. Languages: %s.', $language, implode(', ', $this->languages->codes()));
        }

        return [$checked, $errors];
    }

    /**
     * Writes the published items, one row per language.
     *
     * @param array<string, list<array<string, mixed>>> $items
     */
    private function write(string $name, array $items): void
    {
        $rows = $this->navigations->of($name);

        foreach ($items as $language => $list) {
            $row = $rows[$language] ?? null;

            if (null === $row) {
                $this->navigations->insert(['name' => $name, 'language' => $language, 'items' => $list]);
            } elseif ($row->items !== $list) {
                $this->navigations->update($row->id, ['items' => $list]);
            }
        }

        foreach (array_diff_key($rows, $items) as $row) {
            $this->navigations->delete($row->id);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutIds(array $items): array
    {
        return array_map(static function (array $item): array {
            unset($item['id']);

            if (isset($item['children'])) {
                $item['children'] = self::withoutIds(NavigationReader::list($item['children']));
            }

            return $item;
        }, $items);
    }
}
