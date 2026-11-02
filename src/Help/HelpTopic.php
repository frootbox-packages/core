<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

/**
 * One page of the help in all its languages (decisions 0215, 0218). The
 * English file is the source: it says what the page is about, who may
 * read it and where it stands; other languages only bring title and text.
 */
final readonly class HelpTopic
{
    /**
     * @param string               $id         e.g. "frootbox/backend/pages", without a package only the name
     * @param list<string>         $about      e.g. ["screen:backend.pages", "action:page.publish"]
     * @param string|null          $permission an action or query the reader must be allowed, none for all
     * @param array<string, array{title: string, markdown: string}> $languages by code, English first
     * @param string               $directory  the folder help/ of the package, for images
     */
    public function __construct(
        public string $id,
        public ?string $package,
        public string $name,
        public array $about,
        public ?string $permission,
        public int $order,
        public array $languages,
        public string $directory,
    ) {}

    /**
     * The language to show for this one, else English.
     */
    public function language(string $wanted): string
    {
        return isset($this->languages[$wanted]) ? $wanted : 'en';
    }

    public function title(string $language): string
    {
        return $this->languages[$this->language($language)]['title'];
    }

    public function markdown(string $language): string
    {
        return $this->languages[$this->language($language)]['markdown'];
    }
}
