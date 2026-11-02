<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search\Sources;

use Frootbox\Core\Help\HelpLibrary;
use Frootbox\Core\Help\Markdown;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\Fingerprinted;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Search\SearchSource;

/**
 * The pages of the help in all their languages (decisions 0215, 0218).
 * They come from files, so the index reads them again when they change.
 */
#[AsSearchSource('help', 'Help')]
final readonly class HelpSource implements SearchSource, Fingerprinted
{
    public function __construct(private HelpLibrary $library) {}

    public function changed(string $table, array $row): array
    {
        return [];
    }

    public function items(): iterable
    {
        return array_map(strval(...), array_keys($this->library->all()));
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            $topic = $this->library->get($id);

            foreach (null === $topic ? [] : $topic->languages as $language => $content) {
                yield new SearchEntry(
                    item: $id,
                    title: $content['title'],
                    text: Markdown::toText($content['markdown']),
                    permission: $topic->permission ?? 'help.list',
                    language: $language,
                );
            }
        }
    }

    public function fingerprint(): string
    {
        return $this->library->fingerprint();
    }
}
