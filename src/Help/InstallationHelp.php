<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\I18n\Labels;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\User\Roles;

/**
 * Help pages that describe this installation (decisions 0215, 0218): the
 * blocks the theme offers with their descriptions, and the roles with
 * their permissions. They are written in the language of the reader.
 */
final readonly class InstallationHelp
{
    public const string BLOCKS = 'blocks';

    public const string ROLES = 'roles';

    public function __construct(
        private Components $components,
        private Labels $labels,
        private Translator $translator,
        private Roles $roles,
    ) {}

    /**
     * @return list<HelpTopic>
     */
    public function topics(): array
    {
        return [$this->blocks(), $this->roles()];
    }

    private function blocks(): HelpTopic
    {
        $lines = [Markdown::escape($this->translator->get('These blocks can be added to the pages of this website. Each one is designed for a purpose; choose the one that fits instead of building it from others.'))];
        $blocks = [];

        foreach ($this->components->all(ComponentKind::Block) as $block) {
            if ($this->components->offers(ComponentKind::Block, $block->name) && !$block->widget) {
                $block = $this->labels->component($block);
                $blocks[$block->title . "\0" . $block->name] = $block;
            }
        }

        ksort($blocks);

        foreach ($blocks as $block) {
            $lines[] = '';
            $lines[] = '## ' . Markdown::escape($block->title);

            if ('' !== $block->description) {
                $lines[] = Markdown::escape($block->description);
            }
        }

        return $this->topic(self::BLOCKS, $this->translator->get('Blocks of this website'), implode("\n", $lines), ['screen:edit-mode', 'action:page.update'], 'page.update', 35);
    }

    private function roles(): HelpTopic
    {
        $lines = [Markdown::escape($this->translator->get('Roles bundle what a user may do. A higher level manages the lower ones. "*" stands for every action of its kind, e.g. "page.*" for everything with pages.'))];

        foreach ($this->roles->fetch(['order' => ['level desc', 'name']]) as $role) {
            $lines[] = '';
            $lines[] = '## ' . Markdown::escape($this->translator->label($role->title));
            $lines[] = Markdown::escape($this->translator->get('Level {level}', ['level' => $role->level]));
            $lines[] = '';

            foreach ($role->permissions as $permission) {
                $lines[] = '- `' . str_replace('`', '', $permission) . '`';
            }
        }

        return $this->topic(self::ROLES, $this->translator->get('Roles of this website'), implode("\n", $lines), ['screen:backend.roles', 'action:role.update'], 'role.list', 85);
    }

    /**
     * @param list<string> $about
     */
    private function topic(string $name, string $title, string $markdown, array $about, string $permission, int $order): HelpTopic
    {
        $language = $this->translator->language();
        $content = ['title' => $title, 'markdown' => $markdown];

        return new HelpTopic($name, null, $name, $about, $permission, $order, [HelpLibrary::SOURCE => $content, $language => $content], '');
    }
}
