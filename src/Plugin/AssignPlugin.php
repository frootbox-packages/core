<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Page\PageDetails;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\TranslationData;
use Frootbox\Core\Support\Message;
use Frootbox\Database\Ulid;

/**
 * Lets a page show a space of a plugin (decision 0232), e.g. the jobs
 * "Ausbildung" at /karriere/ausbildung: the layout shows the view of the
 * plugin at plugin(), and with details on the objects get their
 * addresses below the page once it is published. Only for admins by
 * default: a page that shows a space decides the addresses of its objects.
 *
 * @implements Action<PageDetails>
 */
#[AsAction('plugin.assign', 'Lets a page show a space of a plugin, e.g. the news or one space of the jobs, or nothing; in the draft of the page')]
final readonly class AssignPlugin implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('The type of the plugin, e.g. "frootbox/jobs/job"; null to show none')]
        public ?string $type = null,
        #[Description('The space, "default" for the default one')]
        public string $space = Space::DEFAULT,
        #[Description('Pages of their own for the objects, where the plugin lets the admin choose; left out for yes')]
        public ?bool $details = null,
        #[Length(1, 100)]
        #[Description('The name of a new space to create and show instead of "space", e.g. "Ausbildung"')]
        public ?string $newSpace = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, Plugins $plugins, PluginSpaces $spaces, SpaceEditor $spaceEditor, Components $components): PageDetails
    {
        $current = $editor->current($this->id);

        if (null === $this->type) {
            return $editor->saveDraft('plugin.assign', $this->id, $current->withPlugin(null)->with(translations: self::views($current->translations, null)));
        }

        $plugin = $plugins->get($this->type) ?? throw new InvalidInputException('plugin.assign', ['/type' => new Message('There is no plugin "{type}".', ['type' => $this->type])]);

        if (true !== $components->layout($current->layout)?->plugin) {
            throw new InvalidInputException('plugin.assign', ['/id' => 'The layout of the page has no place for a plugin. Choose another layout first.']);
        }

        $space = null === $this->newSpace ? $this->space : $spaceEditor->create('plugin.assign', $this->type, $this->newSpace)->id;

        if (!$spaces->exists($this->type, $space)) {
            throw new InvalidInputException('plugin.assign', ['/space' => 'There is no such space of the plugin.']);
        }

        $page = $spaces->pageOf($this->type, $space);

        if (null !== $page && $page !== $this->id) {
            throw new InvalidInputException('plugin.assign', ['/space' => 'Another page shows this space already. A space has one page.']);
        }

        $place = new PluginPlace($this->type, $space, $plugin->details()->resolve($this->details));

        return $editor->saveDraft('plugin.assign', $this->id, $current->withPlugin($place)->with(translations: self::views($current->translations, $plugin->block())));
    }

    /**
     * The view of the plugin in the content of each translation; kept
     * with its options and fields if it is the same block.
     *
     * @param array<string, TranslationData> $translations
     *
     * @return array<string, TranslationData>
     */
    private static function views(array $translations, ?string $block): array
    {
        foreach ($translations as $language => $translation) {
            $content = $translation->content;
            $view = $content['plugin'] ?? null;

            if (null === $block) {
                unset($content['plugin']);
            } elseif (!\is_array($view) || ($view['block'] ?? null) !== $block) {
                $content['plugin'] = ['block' => $block];
            }

            $translations[$language] = new TranslationData($translation->title, $translation->slug, $content, $translation->description);
        }

        return $translations;
    }
}
