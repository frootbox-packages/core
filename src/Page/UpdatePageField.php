<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Parts;
use Frootbox\Database\Ulid;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * Changes one field of a page in its draft, e.g. while editing on the
 * website (decision 0122). The field is found by its block and path, so
 * other fields stay as they are, also when someone else changes them.
 *
 * @implements Action<FieldUpdated>
 */
#[AsAction('page.update-field', 'Changes one field of the layout or of a block of a page in its draft; fails with 409 if the field no longer holds "previous"')]
final readonly class UpdatePageField implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation to change, e.g. "de"')]
        public string $language,
        #[Description('Path of the field, e.g. "title" or "slides/0/title" for a field of the first entry of a list')]
        #[Pattern('^[a-zA-Z][a-zA-Z0-9]*(/(0|[1-9][0-9]*)/[a-zA-Z][a-zA-Z0-9]*)*$')]
        public string $path,
        #[Description('The new value as the type of the field stores it; null empties the field')]
        public mixed $value = null,
        #[Description('The value as it was read; if the field holds something else now, nothing is saved')]
        public mixed $previous = null,
        #[Description('Id of the block, e.g. "k3x9q2mf"; left out for a field of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $block = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, Components $components, FieldTypes $types, Journal $journal): FieldUpdated
    {
        $current = $editor->current($this->id);
        $translation = $current->translations[$this->language] ?? throw new InvalidInputException('page.update-field', ['/language' => 'The page has no translation in this language.']);
        $field = ContentField::find($components, $current->layout, $translation->content, $this->block, $this->path);

        if (\is_array($field)) {
            throw new InvalidInputException('page.update-field', $field);
        }

        if (!ContentField::same($field->value($translation->content), $this->previous)) {
            throw ConflictException::field();
        }

        $translations = $current->translations;
        $translations[$this->language] = $translation->withContent($field->with($translation->content, $this->value));
        $details = $editor->saveDraft('page.update-field', $this->id, $current->with(translations: $translations));
        $saved = $field->value(($details->draft ?? $current)->translations[$this->language]->content ?? []);
        $type = $types->get($field->field->type);
        $output = $type->output($saved, $field->field);

        return new FieldUpdated(
            $this->id,
            $this->language,
            $this->block,
            $this->path,
            $saved,
            $output instanceof Markup ? (string) $output : (\is_string($output) ? Html::escape($output) : ''),
            $journal->currentEntryId(),
            Parts::html($type, $saved, $field->field),
        );
    }
}
