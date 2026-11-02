<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;
use Frootbox\Core\Site\SiteFields;
use Frootbox\Core\Theme\ThemeException;

/**
 * The fields of the website (decision 0128), such as name and address,
 * in every language, the draft first (decision 0214).
 */
#[AsSearchSource('site', 'Website')]
final readonly class SiteSource implements SearchSource
{
    private const string ITEM = 'site';

    public function __construct(
        private SiteFields $fields,
        private FieldTypes $types,
        private Languages $languages,
    ) {}

    public function changed(string $table, array $row): array
    {
        return Revisions::TABLE === $table && SiteFields::TYPE === ($row['type'] ?? null) ? [self::ITEM] : [];
    }

    public function items(): iterable
    {
        return [self::ITEM];
    }

    public function entries(array $items): iterable
    {
        try {
            $fields = $this->fields->fields();
        } catch (ThemeException) {
            return;
        }

        if (!\in_array(self::ITEM, $items, true) || [] === $fields) {
            return;
        }

        foreach ($this->languages->all() as $language) {
            $text = $this->types->text($fields, $this->fields->values($language->code, true));

            if ('' !== $text) {
                yield new SearchEntry(self::ITEM, 'Fields of the website', $text, 'site.show', $language->code, label: true);
            }
        }
    }
}
