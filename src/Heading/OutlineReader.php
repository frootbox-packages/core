<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

use Dom\HTMLDocument;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageOutline;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Page\Pages;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Database\EntityNotFoundException;

/**
 * Renders a page as visitors get it and reads its headings (decision
 * 0129): those of heading() and rich text with their block and field,
 * those templates write with a fixed level, and the h1 the core adds.
 */
final readonly class OutlineReader
{
    public function __construct(
        private PageReader $pages,
        private Languages $languages,
        private ComponentRenderer $renderer,
        private Delivery $delivery,
        private EditMode $editMode,
        private PageOutline $outline,
        private Urls $urls,
    ) {}

    /**
     * @param bool $draft The draft, as the editing mode shows it, or the published page
     *
     * @return list<OutlineHeading>|null Null if the page has no translation in the language
     *
     * @throws EntityNotFoundException
     */
    public function read(string $id, string $language, bool $draft = true): ?array
    {
        $page = ($draft ? $this->pages->draft($id) : null) ?? $this->pages->published($id) ?? throw EntityNotFoundException::for(Pages::TABLE, $id);
        $translation = $page->translations[$language] ?? null;

        if (null === $translation) {
            return null;
        }

        $outline = new Outline();
        $state = [$this->editMode->active(), $this->editMode->marking(), $this->editMode->markingSite(), $this->editMode->anchors()];
        $delivery = [$this->delivery->language(), $this->delivery->contentLanguage(), $this->delivery->target()];

        try {
            $this->editMode->set($draft);
            $this->outline->clear();
            $this->urls->clear();
            $this->delivery->set($this->languages->get($language), null, Target::page($id));
            $layout = Instance::fromArray(['layout' => $page->layout, 'variant' => $page->variant, ...$translation->content], ComponentKind::Layout);
            $html = $this->renderer->layout($layout, null, $translation->title, $outline);
        } finally {
            $this->editMode->set(...$state);
            $this->outline->clear();
            $this->urls->clear();
            $this->delivery->set(...$delivery);
        }

        return self::headings($html, $outline->headings());
    }

    /**
     * The headings of the HTML in their order, joined with those the
     * outline counted.
     *
     * @param list<RenderedHeading> $rendered
     *
     * @return list<OutlineHeading>
     */
    private static function headings(string $html, array $rendered): array
    {
        $document = HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', \LIBXML_NOERROR);
        $headings = [];
        $next = 0;
        $last = 0;
        $main = false;

        foreach ($document->querySelectorAll('h1, h2, h3, h4, h5, h6') as $element) {
            $level = max(1, min(6, (int) substr(strtolower($element->localName), 1)));
            $text = trim((string) preg_replace('/\s+/u', ' ', $element->textContent ?? ''));
            $counted = $rendered[$next] ?? null;

            if (null !== $counted && $counted->level === $level && $counted->text === $text) {
                ++$next;
                $headings[] = new OutlineHeading($level, $text, $counted->rank, $counted->block, $counted->field, $counted->level === $counted->wanted ? [] : [OutlineHeading::ADJUSTED]);
            } elseif (1 === $level && str_contains(' ' . $element->getAttribute('class') . ' ', ' ' . ComponentRenderer::FALLBACK . ' ')) {
                $headings[] = new OutlineHeading(1, $text, notes: [OutlineHeading::ADDED]);
            } else {
                $notes = [OutlineHeading::FIXED];

                if ($level > $last + 1) {
                    $notes[] = OutlineHeading::SKIPS;
                }

                if (1 === $level && $main) {
                    $notes[] = OutlineHeading::SECOND_MAIN;
                }

                $headings[] = new OutlineHeading($level, $text, notes: $notes);
            }

            $last = $level;
            $main = $main || 1 === $level;
        }

        return $headings;
    }
}
