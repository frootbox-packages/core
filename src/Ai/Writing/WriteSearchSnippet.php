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
use Frootbox\Core\Page\PageEditor;
use Frootbox\Core\Page\PageText;
use Frootbox\Database\Ulid;

/**
 * A title and a description for search engines from the text of a page
 * (decision 0296). Nothing is saved; page.update takes them as seoTitle
 * and description.
 *
 * @implements Action<SearchSnippet>
 */
#[AsAction('ai.page-snippet', 'Suggests a title and a description for search engines from the draft of a page with the AI. Changes nothing; page.update saves them as seoTitle and description', permission: 'ai.use', undoable: false)]
final readonly class WriteSearchSnippet implements Action, Scoped
{
    public const string NAME = 'ai.page-snippet';

    /** Search engines cut longer titles. */
    public const int TITLE_MAX = 60;

    public const int DESCRIPTION_MIN = 50;

    public const int DESCRIPTION_MAX = 160;

    /** At most this much of the page goes to the provider. */
    public const int TEXT_MAX = 12000;

    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Language of the translation, e.g. "de"')]
        public string $language,
        #[Description('What to keep in mind, in the words of the person, e.g. a search term')]
        #[Length(max: 1000)]
        public ?string $instruction = null,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageEditor $editor, PageText $pageText, Wording $wording): SearchSnippet
    {
        $page = $editor->current($this->id);
        $translation = $page->translations[$this->language] ?? throw new InvalidInputException(self::NAME, ['/language' => 'The page has no translation in this language.']);
        $text = trim(mb_substr($pageText->of($page->layout, $translation->content), 0, self::TEXT_MAX));

        if ('' === $text && '' === trim($translation->title)) {
            throw new InvalidInputException(self::NAME, ['/id' => 'The page has no text yet.']);
        }

        $instruction = trim((string) $this->instruction);
        $lines = [
            'Website: ' . $wording->site(),
            'Page: ' . $translation->title,
            'Language of the result: ' . $wording->language($this->language),
            '',
            \sprintf('Task: Write the title and the description of this page for search engines. The title says what the page is about in at most %d characters, without the name of the website (it is added). The description makes people want to open the page, in %d to %d characters. Answer with JSON only: {"title": "…", "description": "…"}', self::TITLE_MAX - 5, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX),
        ];

        if ('' !== $instruction) {
            $lines[] = '';
            $lines[] = Wording::block('instruction', $instruction);
        }

        $lines[] = '';
        $lines[] = Wording::block('page', $text);
        $answer = $wording->ask(self::NAME, 'page.snippet', implode("\n", $lines), maxTokens: 1024);
        $data = json_decode((string) preg_replace('~^[^{]*|[^}]*$~', '', $answer), true);
        $title = \is_array($data) && \is_string($data['title'] ?? null) ? self::line($data['title']) : '';
        $description = \is_array($data) && \is_string($data['description'] ?? null) ? self::line($data['description']) : '';

        if ('' === $title || '' === $description) {
            throw new InvalidInputException(self::NAME, ['' => 'The suggestion of the AI does not fit the field. Try again.']);
        }

        return new SearchSnippet(mb_substr($title, 0, PageEditor::SEO_TITLE_MAX), mb_substr($description, 0, PageEditor::DESCRIPTION_MAX));
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }
}
