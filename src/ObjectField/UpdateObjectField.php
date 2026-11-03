<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Parts;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\ContentField;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Url\Target;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * Changes one further field of an object in its draft, e.g. while editing
 * on the website (decision 0241). The change goes through the action of
 * the module, "<type>.update", which also checks the rights.
 *
 * @implements Action<ObjectFieldUpdated>
 */
#[AsAction('object.update-field', 'Changes one further field of an object, e.g. the subtitle of a news article, in its draft through the action "<type>.update" of the module; fails with 409 if the field no longer holds "previous"', signedIn: true)]
final readonly class UpdateObjectField implements Action
{
    public function __construct(
        #[Description('The object, e.g. "frootbox/news/article:<id>"')]
        #[Pattern(Target::PATTERN)]
        public string $target,
        #[Description('Language of the value, e.g. "de"; fields with one value for all languages ignore it')]
        public string $language,
        #[Description('Name of the field as object.fields lists it, e.g. "subtitle"')]
        #[Pattern('^[a-zA-Z][a-zA-Z0-9]*$')]
        public string $name,
        #[Description('The new value as the type of the field stores it; null removes it')]
        public mixed $value = null,
        #[Description('The value as it was read, in this language or its fallback; if the field holds something else now, nothing is saved')]
        public mixed $previous = null,
    ) {}

    public function handle(ObjectSchema $schema, ObjectFields $fields, FieldTypes $types, Languages $languages, Catalog $catalog, Runner $runner, Journal $journal): ObjectFieldUpdated
    {
        $target = Target::parse($this->target);
        $space = $target->isPage() ? null : $schema->spaceOf($target->type, $target->id);
        $field = $schema->field($target->type, $this->name, $space);
        $action = $target->type . '.update';

        if (null === $field || $target->isPage() || !$catalog->has($action)) {
            $known = array_keys($schema->fields($target->type, $space));

            throw new InvalidInputException('object.update-field', ['/name' => new Message('{type} has no field "{name}". Fields: {fields}.', ['type' => $target->type, 'name' => $this->name, 'fields' => [] === $known ? 'none' : implode(', ', $known)])]);
        }

        if (!$languages->has($this->language)) {
            throw new InvalidInputException('object.update-field', ['/language' => new Message('There is no language "{language}". Languages: {languages}.', ['language' => $this->language, 'languages' => implode(', ', $languages->codes())])]);
        }

        $shown = $languages->contentLanguages($this->language);

        if (!ContentField::same(ObjectFields::valueIn($field, $fields->current($target->type, $target->id), $this->name, $shown), $this->previous)) {
            throw ConflictException::field();
        }

        $runner->call($action, ['id' => $target->id, 'fields' => [$this->name => $field->translatable ? [$this->language => $this->value] : $this->value]]);

        $saved = ObjectFields::valueIn($field, $fields->current($target->type, $target->id), $this->name, $shown);
        $type = $types->get($field->field->type);
        $output = $type->output($saved, $field->field);

        return new ObjectFieldUpdated(
            $this->target,
            $this->language,
            $this->name,
            $saved,
            $output instanceof Markup ? (string) $output : (\is_string($output) ? Html::escape($output) : ''),
            $journal->currentEntryId(),
            Parts::html($type, $saved, $field->field),
        );
    }
}
