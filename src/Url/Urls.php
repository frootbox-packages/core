<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use DateTimeImmutable;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\View\Delivery;

/**
 * The URLs of targets (decision 0105): language prefix or domain plus
 * the stored path.
 *
 *     $urls->href('page:01J…')          "/en/about-us" in the visitor's language
 *     $urls->path($target, 'de')        "/ueber-uns"
 *     $urls->absolute($target, 'de', 'https://example.org')
 */
final class Urls
{
    /** @var array<string, array<string, Address>> */
    private array $addresses = [];

    /** Whether the addresses of all pages are read, see preload() */
    private bool $pages = false;

    public function __construct(
        private readonly Addresses $repository,
        private readonly Languages $languages,
        private readonly Delivery $delivery,
        private readonly Config $config,
        private readonly EditMode $editMode,
        private readonly DraftAddresses $drafts,
    ) {}

    /**
     * The path of a target in a language, e.g. "/en/about-us". Null if it
     * has no address there.
     */
    public function path(Target|string $target, ?string $language = null): ?string
    {
        $language = null === $language ? $this->delivery->language() : $this->languages->get($language);
        $path = $this->pagePath($target, $language->code);

        return null === $path ? null : $language->path($path);
    }

    /**
     * The URL for a link on the current page: a path, or a full URL if
     * the language lives on another domain.
     */
    public function href(Target|string $target, ?string $language = null): ?string
    {
        $path = $this->path($target, $language);

        if (null === $path) {
            return null;
        }

        $language = null === $language ? $this->delivery->language() : $this->languages->get($language);

        if (null === $language->domain || $language->domain === $this->delivery->language()->domain) {
            return $path;
        }

        return $this->base($language, null) . $path;
    }

    /**
     * The full URL, e.g. for canonical and hreflang.
     *
     * @param string|null $site The address of the website, see SiteUrl
     */
    public function absolute(Target|string $target, string $language, ?string $site): ?string
    {
        $path = $this->path($target, $language);
        $base = $this->base($this->languages->get($language), $site);

        return null === $path || null === $base ? null : $base . $path;
    }

    /**
     * The address of the website in a language: its domain, otherwise
     * the address of the website.
     */
    public function base(Language $language, ?string $site): ?string
    {
        if (null === $language->domain) {
            return $site;
        }

        $scheme = parse_url($site ?? $this->config->string('app.url', ''), \PHP_URL_SCHEME);

        return (\is_string($scheme) ? $scheme : 'https') . '://' . $language->domain;
    }

    /**
     * The current addresses of a target by language.
     *
     * @return array<string, Address>
     */
    public function addresses(Target|string $target): array
    {
        $key = (string) $target;

        // In the editing mode pages have the addresses of their drafts (decision 0119).
        if ($this->editMode->active() && str_starts_with($key, Target::PAGE . ':')) {
            return array_map(
                static fn(PagePath $path): Address => new Address(0, $key, $path->language, null, $path->path, true, $path->language !== $path->contentLanguage, new DateTimeImmutable(), null),
                $this->drafts->of(substr($key, \strlen(Target::PAGE) + 1)),
            );
        }

        if ($this->pages && !isset($this->addresses[$key]) && str_starts_with($key, Target::PAGE . ':')) {
            return [];
        }

        return $this->addresses[$key] ??= $this->repository->of($target instanceof Target ? $target : Target::parse($target));
    }

    /**
     * Reads the addresses of all pages at once, e.g. before building a
     * navigation with many links.
     */
    public function preload(): void
    {
        if ($this->pages) {
            return;
        }

        foreach ($this->repository->pages() as $address) {
            $this->addresses[$address->target][$address->language] = $address;
        }

        $this->pages = true;
    }

    /**
     * Forgets what was read, e.g. after publishing.
     */
    public function clear(): void
    {
        $this->addresses = [];
        $this->pages = false;
        $this->drafts->clear();
    }

    private function pagePath(Target|string $target, string $language): ?string
    {
        $address = $this->addresses($target)[$language] ?? null;

        if (null === $address) {
            return null;
        }

        if (null === $address->basePageId) {
            return $address->path;
        }

        $base = $this->pagePath(Target::page($address->basePageId), $language);

        return null === $base ? null : ltrim($base . '/' . $address->path, '/');
    }
}
