<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai\Writing;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Field\EntryKey;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\OutputsHeadings;
use Frootbox\Core\Page\ContentField;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Database\Ulid;
use Frootbox\RichText\HtmlReader;
use Stringable;

/**
 * Writing at a field (decision 0296): the AI suggests a text for a
 * headline, text or rich text of a page, from the draft. Nothing is
 * saved; page.update-field takes the suggestion like an own input.
 *
 * @implements Action<Suggestion>
 */
#[AsAction('ai.field-write', 'Suggests a new text for a headline, text or rich text field of a page with the AI: rephrase, shorten, expand, correct, translate from another language of the page or follow an own instruction, also to draft an empty field. Changes nothing; page.update-field saves the suggestion', permission: 'ai.use', undoable: false)]
final readonly class WriteField implements Action, Scoped
{
    public const string NAME = 'ai.field-write';

    /** The field types the AI writes. */
    public const array TYPES = ['headline', 'text', 'richtext'];

    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation, e.g. "de"')]
        public string $language,
        #[Description('Path of the field, e.g. "title" or "slides/0/title"')]
        #[Pattern('^[a-zA-Z][a-zA-Z0-9]*(/' . EntryKey::SEGMENT . '/[a-zA-Z][a-zA-Z0-9]*)*$')]
        public string $path,
        public WriteTask $task,
        #[Description('Id of the block; left out for a field of the layout')]
        #[Pattern(ContentValidator::ID_PATTERN)]
        public ?string $block = null,
        #[Description('What should change, in the words of the person; needed for "instruct", e.g. notes to draft an empty field from')]
        #[Length(max: 2000)]
        public ?string $instruction = null,
        #[Description('For "translate": the language to translate from, e.g. "en"')]
        public ?string $from = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, Components $components, FieldTypes $types, Wording $wording): Suggestion
    {
        $page = $editor->current($this->id);
        $translation = $page->translations[$this->language] ?? throw new InvalidInputException(self::NAME, ['/language' => 'The page has no translation in this language.']);
        $field = ContentField::find($components, $page->layout, $translation->content, $this->block, $this->path);

        if (\is_array($field)) {
            throw new InvalidInputException(self::NAME, $field);
        }

        if (!\in_array($field->field->type, self::TYPES, true)) {
            throw new InvalidInputException(self::NAME, ['/path' => 'The AI writes only headlines, texts and rich texts.']);
        }

        $type = $types->get($field->field->type);
        $instruction = trim((string) $this->instruction);
        $source = $field->value($translation->content);

        if (WriteTask::Translate === $this->task) {
            $other = null === $this->from || $this->from === $this->language ? null : $page->translations[$this->from] ?? null;

            if (null === $other) {
                throw new InvalidInputException(self::NAME, ['/from' => 'Choose another language of the page to translate from.']);
            }

            $found = ContentField::find($components, $page->layout, $other->content, $this->block, $this->path);
            // Entries of the other language may carry other ids (decision 0344).
            $found = \is_array($found) ? ContentField::find($components, $page->layout, $other->content, $this->block, $field->positions()) : $found;
            $source = \is_array($found) ? null : $found->value($other->content);
        }

        $text = $this->text($type, $field->field, $source);

        if ('' === $text && WriteTask::Instruct !== $this->task) {
            throw new InvalidInputException(self::NAME, [WriteTask::Translate === $this->task ? '/from' : '/path' => WriteTask::Translate === $this->task ? 'The field is empty in this language.' : 'The field is empty. Say what to write instead.']);
        }

        if (WriteTask::Instruct === $this->task && '' === $instruction) {
            throw new InvalidInputException(self::NAME, ['/instruction' => 'Say what should change.']);
        }

        $answer = $wording->ask(self::NAME, 'field.' . $this->task->value, $this->prompt($wording, $type, $field->field, $text, $translation->title, $instruction), maxTokens: 'richtext' === $field->field->type ? 8192 : 2048);

        return $this->suggestion($type, $field->field, $answer);
    }

    private function text(FieldType $type, Field $field, mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if ('richtext' === $field->type) {
            $html = $type->output($value, $field);

            return trim($html instanceof Stringable || \is_string($html) ? (string) $html : '');
        }

        return \is_string($value) ? trim($value) : '';
    }

    private function prompt(Wording $wording, FieldType $type, Field $field, string $text, string $title, string $instruction): string
    {
        $limits = $type->valueSchema($field);
        $max = \is_int($limits['maxLength'] ?? null) ? $limits['maxLength'] : null;
        $ranks = $type instanceof OutputsHeadings ? $type->headingRanks($field) : 0;
        $kind = match (true) {
            'headline' === $field->type => 'A headline: one line, no full stop at the end' . (null === $max ? '' : ', at most ' . $max . ' characters') . '.',
            'richtext' === $field->type => 'Formatted text as HTML with only these elements: p, ' . ($ranks > 0 ? 'h2 to h' . (1 + $ranks) . ', ' : '') . 'strong, em, a with href, ul, ol, li, blockquote, br. No classes, no styles, no other elements.',
            true === $field->setting('multiline') => 'Plain text without formatting, paragraphs separated by an empty line' . (null === $max ? '' : ', at most ' . $max . ' characters') . '.',
            default => 'Plain text on one line without formatting' . (null === $max ? '' : ', at most ' . $max . ' characters') . '.',
        };
        $target = $wording->language($this->language);
        $lines = [
            'Website: ' . $wording->site(),
            'Page: ' . $title,
            'Field: ' . ('' === $field->label ? $field->name : $field->label) . ('' === $field->description ? '' : ' (' . $field->description . ')'),
            'Kind: ' . $kind,
            'Language of the result: ' . $target,
            '',
            'Task: ' . $this->task->prompt(),
        ];

        if (WriteTask::Translate === $this->task) {
            $lines[] = 'Translate from ' . $wording->language((string) $this->from) . ' into ' . $target . '.';
        }

        if ('' !== $instruction) {
            $lines[] = '';
            $lines[] = Wording::block('instruction', $instruction);
        }

        if ('' !== $text) {
            $lines[] = '';
            $lines[] = Wording::block('text', $text);
        } else {
            $lines[] = 'The field is empty: write its text from the instruction.';
        }

        return implode("\n", $lines);
    }

    private function suggestion(FieldType $type, Field $field, string $answer): Suggestion
    {
        if ('richtext' === $field->type) {
            $document = new HtmlReader()->read($answer);

            if ([] === $document['content'] || [] !== $type->validate($document, $field)) {
                throw self::unfit();
            }

            return new Suggestion($answer, $document);
        }

        $value = 'headline' === $field->type || true !== $field->setting('multiline')
            ? trim((string) preg_replace('~\s+~u', ' ', $answer))
            : trim((string) preg_replace("~\n{3,}~", "\n\n", str_replace("\r", '', $answer)));
        $max = $type->valueSchema($field)['maxLength'] ?? null;

        if ('' === $value || (\is_int($max) && mb_strlen($value) > $max)) {
            throw self::unfit();
        }

        return new Suggestion($value, $value);
    }

    private static function unfit(): InvalidInputException
    {
        return new InvalidInputException(self::NAME, ['' => 'The suggestion of the AI does not fit the field. Try again.']);
    }
}
