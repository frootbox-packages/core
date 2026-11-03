<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Field\Field;

/**
 * One further field of a type of objects (decision 0240): a field of the
 * core's types, whether it holds a value per language, and the views or
 * extensions that name it. Fields of a space (decision 0250) also have
 * a label per language for visitors.
 */
final readonly class ObjectField
{
    /** Types whose values are text, so they hold one per language unless "translatable: false". */
    public const array TEXT_TYPES = ['headline', 'text', 'richtext', 'link'];

    /**
     * @param list<string>          $sources E.g. "frootbox/news/ArticleList, view Default"
     * @param array<string, string> $labels  By language, from "labels"
     */
    public function __construct(
        public Field $field,
        public bool $translatable,
        public array $sources,
        public array $labels = [],
    ) {}

    public static function of(Field $field, string $source): self
    {
        $translatable = $field->setting('translatable');
        $labels = [];

        foreach (\is_array($field->setting('labels')) ? $field->setting('labels') : [] as $language => $label) {
            if (\is_string($label) && '' !== trim($label)) {
                $labels[(string) $language] = trim($label);
            }
        }

        return new self($field, \is_bool($translatable) ? $translatable : \in_array($field->type, self::TEXT_TYPES, true), [$source], $labels);
    }

    public function from(string $source): self
    {
        return \in_array($source, $this->sources, true) ? $this : new self($this->field, $this->translatable, [...$this->sources, $source], $this->labels);
    }

    /**
     * The label in the first of the languages that has one, else the label
     * of the field.
     *
     * @param list<string> $languages The language and its fallbacks
     */
    public function label(array $languages): string
    {
        foreach ($languages as $language) {
            if (isset($this->labels[$language])) {
                return $this->labels[$language];
            }
        }

        return $this->field->label;
    }
}
