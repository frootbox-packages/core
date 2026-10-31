<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

/**
 * An item of a navigation or the breadcrumb, ready for a template:
 *
 *     {% for item in navigation('main') %}
 *         <a href="{{ item.url }}"{% if item.current %} aria-current="page"{% endif %}>{{ item.label }}</a>
 *     {% endfor %}
 */
final readonly class MenuItem
{
    /**
     * @param string               $type    target, url, anchor, phone, email, heading; "pages" gives items of type target
     * @param string|null          $url     null only for headings
     * @param bool                 $current whether it leads to the page being shown
     * @param bool                 $active  whether it or one of its children leads to the page being shown or a page below it
     * @param array<string, mixed> $options the options of the navigation, with defaults
     * @param list<MenuItem>       $children
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public ?string $url,
        public bool $current = false,
        public bool $active = false,
        public bool $newWindow = false,
        public array $options = [],
        public array $children = [],
    ) {}
}
