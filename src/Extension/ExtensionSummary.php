<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * One installed extension and what it brings (decision 0274).
 */
final readonly class ExtensionSummary
{
    /**
     * @param string|null  $version    as Composer knows it, e.g. "0.1.0" or "dev-main"
     * @param bool         $activeTheme the active theme or one of its parents
     * @param bool         $project    a package of this website below extensions/
     * @param list<string> $blocks     full names, e.g. "frootbox/news/List"
     * @param list<string> $layouts    full names
     * @param list<string> $actions    e.g. "frootbox/news/article.create"
     * @param list<string> $queries    e.g. "frootbox/news/article.list"
     * @param list<string> $fieldTypes e.g. "acme/shop/price"
     * @param bool         $settings   settings in the backend or in config/extensions/
     * @param bool         $migrations own tables or columns
     * @param list<string> $jobs       e.g. "frootbox/webhooks/delivery.send" (decision 0280)
     * @param list<string> $searchSources e.g. "frootbox/news/article"
     * @param list<string> $commands   of the console, e.g. "acme/shop:import"
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public ?string $version,
        public bool $theme,
        public bool $activeTheme,
        public bool $project,
        public array $blocks,
        public array $layouts,
        public array $actions,
        public array $queries,
        public array $fieldTypes,
        public bool $settings,
        public bool $migrations,
        public array $jobs = [],
        public array $searchSources = [],
        public array $commands = [],
    ) {}
}
