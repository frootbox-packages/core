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
 * extensions that name it.
 */
final readonly class ObjectField
{
    /** Types whose values are text, so they hold one per language unless "translatable: false". */
    public const array TEXT_TYPES = ['headline', 'text', 'richtext', 'link'];

    /**
     * @param list<string> $sources E.g. "frootbox/news/ArticleList, view Default"
     */
    public function __construct(
        public Field $field,
        public bool $translatable,
        public array $sources,
    ) {}

    public static function of(Field $field, string $source): self
    {
        $translatable = $field->setting('translatable');

        return new self($field, \is_bool($translatable) ? $translatable : \in_array($field->type, self::TEXT_TYPES, true), [$source]);
    }

    public function from(string $source): self
    {
        return \in_array($source, $this->sources, true) ? $this : new self($this->field, $this->translatable, [...$this->sources, $source]);
    }
}
