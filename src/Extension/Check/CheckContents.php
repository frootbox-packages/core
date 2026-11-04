<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

/**
 * What a checked package brings (decision 0278), each list sorted.
 */
final readonly class CheckContents
{
    /**
     * @param list<string> $blocks
     * @param list<string> $layouts
     * @param list<string> $actions
     * @param list<string> $queries
     * @param list<string> $fieldTypes
     * @param list<string> $tables
     * @param list<string> $settings     Keys, the package or "<package>.<section>"
     * @param list<string> $translations Languages with texts in translations/
     * @param list<string> $help         Languages with pages in help/
     */
    public function __construct(
        public array $blocks = [],
        public array $layouts = [],
        public array $actions = [],
        public array $queries = [],
        public array $fieldTypes = [],
        public array $tables = [],
        public array $settings = [],
        public array $translations = [],
        public array $help = [],
    ) {}

    /**
     * Counts for people, e.g. "2 blocks, 1 action", empty for nothing.
     */
    public function summary(): string
    {
        $parts = [];

        foreach (['block' => $this->blocks, 'layout' => $this->layouts, 'action' => $this->actions, 'query' => $this->queries, 'field type' => $this->fieldTypes, 'table' => $this->tables] as $noun => $items) {
            $count = \count($items);

            if ($count > 0) {
                $parts[] = $count . ' ' . (1 === $count ? $noun : ('query' === $noun ? 'queries' : $noun . 's'));
            }
        }

        if ([] !== $this->settings) {
            $parts[] = 'settings';
        }

        if ([] !== $this->translations) {
            $parts[] = 'texts in ' . implode(', ', $this->translations);
        }

        if ([] !== $this->help) {
            $parts[] = 'help in ' . implode(', ', $this->help);
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'blocks' => $this->blocks,
            'layouts' => $this->layouts,
            'actions' => $this->actions,
            'queries' => $this->queries,
            'fieldTypes' => $this->fieldTypes,
            'tables' => $this->tables,
            'settings' => $this->settings,
            'languages' => ['translations' => $this->translations, 'help' => $this->help],
        ];
    }
}
