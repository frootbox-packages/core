<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Page\PageData;

/**
 * Finds the media a page uses: in the fields of its layout and of all
 * blocks, in every language (decision 0126).
 */
final readonly class MediaReferences
{
    public function __construct(
        private Components $components,
        private FieldTypes $types,
    ) {}

    /**
     * @return list<string>
     */
    public function inPage(PageData $data): array
    {
        $layout = $this->components->layout($data->layout);

        if (null === $layout) {
            return [];
        }

        $ids = [];

        foreach ($data->translations as $translation) {
            array_push($ids, ...$this->inBody($layout, $translation->content));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<mixed> $body With "fields" and "areas"
     *
     * @return list<string>
     */
    private function inBody(Component $component, array $body): array
    {
        $ids = \is_array($body['fields'] ?? null) ? $this->types->media($component->fields, $body['fields']) : [];

        foreach (\is_array($body['areas'] ?? null) ? $body['areas'] : [] as $blocks) {
            foreach (\is_array($blocks) ? $blocks : [] as $block) {
                $child = \is_array($block) && \is_string($block['block'] ?? null) ? $this->components->block($block['block']) : null;

                if (null !== $child) {
                    array_push($ids, ...$this->inBody($child, $block));
                }
            }
        }

        return $ids;
    }
}
