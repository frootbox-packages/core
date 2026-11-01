<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Page\PageData;

/**
 * Finds the media a page uses: in the fields of its layout and of all
 * blocks, in every language (decision 0126); also those of the fields
 * of the website.
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
        return self::merge($this->inPageByLanguage($data));
    }

    /**
     * @return array<string, list<string>> by language
     */
    public function inPageByLanguage(PageData $data): array
    {
        $layout = $this->components->layout($data->layout);

        if (null === $layout) {
            return [];
        }

        $ids = [];

        foreach ($data->translations as $language => $translation) {
            $ids[$language] = array_values(array_unique($this->inBody($layout, $translation->content)));
        }

        return $ids;
    }

    /**
     * The media in stored values of fields, e.g. those of the website.
     *
     * @param array<string, Field>                $fields
     * @param array<string, array<string, mixed>> $languages Values by language and field name
     *
     * @return array<string, list<string>> by language
     */
    public function inFieldsByLanguage(array $fields, array $languages): array
    {
        $ids = [];

        foreach ($languages as $language => $values) {
            $ids[$language] = array_values(array_unique($this->types->media($fields, $values)));
        }

        return $ids;
    }

    /**
     * @param array<string, list<string>> $byLanguage
     *
     * @return list<string>
     */
    public static function merge(array $byLanguage): array
    {
        return array_values(array_unique(array_merge(...array_values($byLanguage))));
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
