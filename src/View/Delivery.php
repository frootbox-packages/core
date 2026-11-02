<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Url\Target;

/**
 * What is being delivered right now (decision 0105): the target, the
 * language of the visitor and the language its content comes from. They
 * differ where a page has no translation and falls back, e.g. English
 * around German content. Until a page is found both languages are the
 * default language.
 */
final class Delivery
{
    /** The longest value of a query parameter a block gets. */
    public const int LONGEST = 100;

    private ?Language $language = null;

    private ?Language $content = null;

    private ?Target $target = null;

    /** @var array<string, string> */
    private array $query = [];

    /** @var array<string, true> The query parameters the rendered blocks declared */
    private array $used = [];

    public function __construct(private readonly Languages $languages) {}

    public function set(Language $language, ?Language $content = null, ?Target $target = null): void
    {
        $this->language = $language;
        $this->content = $content ?? $language;
        $this->target = $target;
    }

    /**
     * The language of the visitor: frame, labels and links.
     */
    public function language(): Language
    {
        return $this->language ?? $this->languages->default();
    }

    /**
     * The language the content of the page is in.
     */
    public function contentLanguage(): Language
    {
        return $this->content ?? $this->language();
    }

    public function target(): ?Target
    {
        return $this->target;
    }

    /**
     * The query of the request. Only text values up to 100 characters
     * count, blocks get only those they declare (decision 0219).
     *
     * @param array<mixed> $query
     */
    public function setQuery(array $query): void
    {
        $this->query = [];

        foreach ($query as $name => $value) {
            if (\is_string($name) && \is_string($value) && mb_strlen($value) <= self::LONGEST) {
                $this->query[$name] = $value;
            }
        }
    }

    /**
     * The values of these query parameters, as far as given. Remembers
     * the names, so the page cache can tell the pages apart.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    public function query(array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            $this->used[$name] = true;

            if (isset($this->query[$name])) {
                $values[$name] = $this->query[$name];
            }
        }

        return $values;
    }

    /**
     * The query parameters the rendered blocks declared.
     *
     * @return list<string>
     */
    public function parameters(): array
    {
        return array_keys($this->used);
    }

    public function isFallback(): bool
    {
        return $this->contentLanguage()->code !== $this->language()->code;
    }
}
