<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Support\Message;

/**
 * Writes spaces of plugins (decision 0232). The default space of a type
 * cannot be renamed or deleted, a space with objects or a page neither.
 */
final readonly class SpaceEditor
{
    public function __construct(
        private Spaces $spaces,
        private Plugins $plugins,
        private PluginSpaces $read,
    ) {}

    public function create(string $action, string $type, string $title): Space
    {
        $plugin = $this->plugins->get($type) ?? throw new InvalidInputException($action, ['/type' => new Message('There is no plugin "{type}".', ['type' => $type])]);

        if (!$plugin->spaces()) {
            throw new InvalidInputException($action, ['/type' => 'The plugin has only its default space.']);
        }

        $title = $this->title($action, $type, $title, null);

        return $this->spaces->insert(['type' => $type, 'title' => $title]);
    }

    public function rename(string $action, string $id, string $title): Space
    {
        $space = $this->find($action, $id);
        $title = $this->title($action, $space->type, $title, $space->id);

        return $this->spaces->update($space->id, ['title' => $title]);
    }

    public function delete(string $action, string $id): void
    {
        $space = $this->find($action, $id);
        $count = $this->plugins->get($space->type)?->count($space->id) ?? 0;

        if ($count > 0) {
            throw new InvalidInputException($action, ['/id' => new Message('The space still holds {count} entries. Move or delete them first.', ['count' => $count])]);
        }

        if (null !== $this->read->pageOf($space->type, $space->id)) {
            throw new InvalidInputException($action, ['/id' => 'A page shows the space. Take it away from the page first.']);
        }

        $this->spaces->delete($space->id);
    }

    private function find(string $action, string $id): Space
    {
        $space = Space::DEFAULT === $id ? null : $this->spaces->fetchById($id);

        return $space instanceof Space ? $space : throw new InvalidInputException($action, ['/id' => Space::DEFAULT === $id ? 'The default space stays.' : 'There is no space with this id.']);
    }

    private function title(string $action, string $type, string $title, ?string $id): string
    {
        $title = trim($title);

        if ('' === $title) {
            throw new InvalidInputException($action, ['/title' => 'The space needs a name.']);
        }

        foreach ($this->read->all($type) as $space) {
            if ($space->id !== $id && mb_strtolower($space->title) === mb_strtolower($title)) {
                throw new InvalidInputException($action, ['/title' => 'Another space has this name.']);
            }
        }

        return $title;
    }
}
