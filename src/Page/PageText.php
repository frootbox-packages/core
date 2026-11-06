<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\FieldTypes;

/**
 * The plain text of all fields of layout and blocks of a page in one
 * language, e.g. for the search (decision 0214) or the AI (decision 0296).
 */
final readonly class PageText
{
    public function __construct(
        private Components $components,
        private FieldTypes $types,
    ) {}

    /**
     * @param array<mixed> $content The content of a translation, with "fields" and "areas"
     */
    public function of(string $layout, array $content): string
    {
        $component = $this->components->layout($layout);

        return null === $component ? '' : $this->text($component, $content);
    }

    /**
     * The plain text of one block with the blocks inside it.
     *
     * @param array<mixed> $block A block of the content, with "block", "fields" and "areas"
     */
    public function block(array $block): string
    {
        $component = \is_string($block['block'] ?? null) ? $this->components->block($block['block']) : null;

        return null === $component ? '' : $this->text($component, $block);
    }

    /**
     * @param array<mixed> $body With "fields" and "areas"
     */
    private function text(Component $component, array $body): string
    {
        $texts = [\is_array($body['fields'] ?? null) ? $this->types->text($component->fields, $body['fields']) : ''];

        foreach (\is_array($body['areas'] ?? null) ? $body['areas'] : [] as $blocks) {
            foreach (\is_array($blocks) ? $blocks : [] as $block) {
                $child = \is_array($block) && \is_string($block['block'] ?? null) ? $this->components->block($block['block']) : null;

                if (null !== $child) {
                    $texts[] = $this->text($child, $block);
                }
            }
        }

        return implode("\n", array_filter($texts, static fn(string $text): bool => '' !== $text));
    }
}
