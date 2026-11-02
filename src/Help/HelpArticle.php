<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

/**
 * A page of the help to read, as safe HTML.
 */
final readonly class HelpArticle
{
    /**
     * @param string       $language   the language of the text, English if there is none in the wanted one
     * @param bool         $translated false if the text is English instead of the wanted language
     * @param list<string> $about
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $html,
        public string $language,
        public bool $translated,
        public ?string $package,
        public array $about,
    ) {}
}
