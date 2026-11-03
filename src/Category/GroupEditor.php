<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Support\Message;

/**
 * Writes the groups of categories admins create (decision 0233). Groups
 * of extensions and of config/categories.yaml are not changed here.
 */
final readonly class GroupEditor
{
    public function __construct(
        private AdminGroups $rows,
        private CategoryGroups $groups,
        private Categories $categories,
    ) {}

    /**
     * @param list<string> $types
     *
     * @throws InvalidInputException
     */
    public function create(string $action, string $title, array $types): AdminGroup
    {
        return $this->rows->insert(['title' => $this->title($action, $title, null), 'types' => $this->types($action, $types)]);
    }

    /**
     * @param list<string>|null $types
     *
     * @throws InvalidInputException
     */
    public function update(string $action, string $id, ?string $title, ?array $types): AdminGroup
    {
        $row = $this->find($action, $id);

        return $this->rows->update($row->id, array_filter([
            'title' => null === $title ? null : $this->title($action, $title, $row->id),
            'types' => null === $types ? null : $this->types($action, $types),
        ], static fn(mixed $value): bool => null !== $value));
    }

    /**
     * @throws InvalidInputException
     */
    public function delete(string $action, string $id): void
    {
        $row = $this->find($action, $id);
        $count = $this->categories->count(['groupKey' => $row->id]);

        if ($count > 0) {
            throw new InvalidInputException($action, ['/id' => new Message('The group still has {count} categories. Delete them first.', ['count' => $count])]);
        }

        $this->rows->delete($row->id);
    }

    private function find(string $action, string $id): AdminGroup
    {
        return $this->rows->fetchById($id) ?? throw new InvalidInputException($action, ['/id' => null === $this->groups->get($id) ? 'There is no group with this id.' : 'Only groups created in the backend can be changed here.']);
    }

    private function title(string $action, string $title, ?string $id): string
    {
        $title = trim($title);

        if ('' === $title) {
            throw new InvalidInputException($action, ['/title' => 'The group needs a name.']);
        }

        foreach ($this->groups->all() as $group) {
            if ($group->key !== $id && mb_strtolower($this->groups->title($group)) === mb_strtolower($title)) {
                throw new InvalidInputException($action, ['/title' => 'Another group has this name.']);
            }
        }

        return $title;
    }

    /**
     * @param list<string> $types
     *
     * @return list<string>
     */
    private function types(string $action, array $types): array
    {
        $known = array_column($this->groups->types(), 'type');
        $errors = [];

        foreach ($types as $index => $type) {
            if (!\in_array($type, $known, true)) {
                $errors['/types/' . $index] = new Message('There is no type "{type}".', ['type' => $type]);
            }
        }

        if ([] === $types) {
            $errors['/types'] = 'Choose at least one kind of content.';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return array_values(array_unique($types));
    }
}
