<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

/**
 * What a package says about itself for its page in the directory
 * (decision 0282): from composer.json, frootbox.yaml, README.md and the
 * previews of its blocks. Files are relative to the package.
 */
final readonly class Listing
{
    /**
     * @param list<string>                                  $keywords
     * @param array<string, string>                         $support     e.g. "issues" => URL
     * @param list<array{name: string, email: string|null, homepage: string|null}> $authors
     * @param list<array{file: string, caption: string}>    $screenshots
     * @param list<array{component: string, variant: string, file: string}> $previews
     * @param bool                                          $theme       a theme rather than a module
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $readme = null,
        public array $keywords = [],
        public ?string $homepage = null,
        public array $support = [],
        public array $authors = [],
        public ?string $icon = null,
        public array $screenshots = [],
        public array $previews = [],
        public bool $theme = false,
    ) {}

    /**
     * The files to copy with the package for the directory, relative.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_unique([
            ...null === $this->icon ? [] : [$this->icon],
            ...array_column($this->screenshots, 'file'),
            ...array_column($this->previews, 'file'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'readme' => $this->readme,
            'keywords' => $this->keywords,
            'homepage' => $this->homepage,
            'support' => (object) $this->support,
            'authors' => $this->authors,
            'icon' => $this->icon,
            'screenshots' => $this->screenshots,
            'previews' => $this->previews,
            'theme' => $this->theme,
        ];
    }
}
