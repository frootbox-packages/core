<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageResolver;
use Frootbox\Core\Page\ResolvedPage;
use Frootbox\Core\Plugin\CategoryPages;
use Frootbox\Core\Plugin\Plugins;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Finds what a request path leads to (decision 0105), in this order:
 * the current address of a page, an object of a plugin below a page
 * (decision 0219), a manual redirect, an earlier address (301 to the
 * current one, 410 if the target is gone), nothing.
 *
 * The language comes from the domain and the prefix. "/" without a
 * language for it goes to the best language for Accept-Language (302).
 */
final readonly class UrlResolver
{
    public function __construct(
        private Languages $languages,
        private PageResolver $pages,
        private Addresses $addresses,
        private Redirects $redirects,
        private AddressSync $sync,
        private Runner $runner,
        private Urls $urls,
        private Plugins $plugins,
        private CategoryPages $categories,
    ) {}

    public function resolve(ServerRequestInterface $request): Resolution
    {
        // The language settings changed: an action brings the addresses up to date, if needed at all.
        if ($this->sync->isStale()) {
            if ($this->sync->pending() > 0) {
                $this->runner->run(new SyncUrls(), Context::system());
            }

            $this->sync->markFresh();
        }

        $uri = $request->getUri();
        $host = $uri->getAuthority();
        // Compared as text in NFC (decision 0158); no valid UTF-8, no page.
        $path = UrlPath::decode($uri->getPath());

        if (null === $path) {
            return Resolution::notFound();
        }

        $match = $this->languages->match($host, $path);

        if (null !== $match) {
            $page = $this->pages->resolve($match[0], $match[1]);

            if (null !== $page) {
                return Resolution::page($page);
            }

            $subpage = $this->subpage($match[0], $match[1]);

            if (null !== $subpage) {
                return Resolution::page($subpage);
            }
        } elseif ('/' === $path) {
            $language = $this->languages->preferred($host, $request->getHeaderLine('Accept-Language'));

            return Resolution::redirect($this->link($language, '', $host), 302, ['Accept-Language']);
        }

        $redirect = $this->redirects->at($path);

        if (null !== $redirect) {
            return $this->manual($redirect, $match[0] ?? null, $uri);
        }

        if (null === $match) {
            return Resolution::notFound();
        }

        $earlier = $this->addresses->earlier($match[0]->code, trim($match[1], '/')) ?? $this->earlierSubpage($match[0], $match[1]);

        if (null === $earlier) {
            return Resolution::notFound();
        }

        if (null !== $earlier->basePageId) {
            $path = $this->subpagePath(Target::parse($earlier->target), $match[0]->code);

            return null === $path ? Resolution::gone() : Resolution::redirect($this->link($match[0], $path, $host) . self::query($uri));
        }

        $current = $this->addresses->of(Target::parse($earlier->target))[$match[0]->code] ?? null;

        return null === $current ? Resolution::gone() : Resolution::redirect($this->link($match[0], $current->path, $host) . self::query($uri));
    }

    /**
     * An object of a plugin below the deepest page of the path (decision
     * 0219), e.g. "news/neue-sorten" below "news", or the page of a
     * category there (decision 0243).
     */
    private function subpage(Language $language, string $path): ?ResolvedPage
    {
        foreach ($this->below($language, $path) as [$page, $rest]) {
            $address = $this->addresses->below($language->code, $page->id, $rest);
            $target = null === $address ? null : Target::tryParse($address->target);

            if (null !== $target && $target->isCategory()) {
                $category = $this->categories->subpage($target, $rest, $page->contentLanguage);

                return null === $category ? null : $page->withSubpage($category);
            }

            $plugin = null === $target ? null : $this->plugins->get($target->type);
            $title = null === $target ? null : $plugin?->title($target->id, $page->contentLanguage);

            if (null === $target || null === $plugin || null === $title) {
                return null;
            }

            return $page->withSubpage(new Subpage(
                $target,
                $rest,
                $title,
                $plugin->description($target->id, $page->contentLanguage) ?? '',
                $plugin->image($target->id, $page->contentLanguage),
            ));
        }

        return null;
    }

    /**
     * An earlier address of an object of a plugin: its path changed, or
     * its base page moved, e.g. "news/neue-sorten" after "news" became
     * "aktuelles".
     */
    private function earlierSubpage(Language $language, string $path): ?Address
    {
        foreach ($this->below($language, $path) as [$page, $rest]) {
            $address = $this->addresses->below($language->code, $page->id, $rest, current: false);

            if (null !== $address) {
                return $address;
            }
        }

        $parts = explode('/', trim($path, '/'));

        for ($i = \count($parts) - 1; $i >= 1; --$i) {
            $old = $this->addresses->earlier($language->code, implode('/', \array_slice($parts, 0, $i)));
            $target = null === $old ? null : Target::tryParse($old->target);

            if (null !== $target && $target->isPage()) {
                $rest = implode('/', \array_slice($parts, $i));

                return $this->addresses->below($language->code, $target->id, $rest) ?? $this->addresses->below($language->code, $target->id, $rest, current: false);
            }
        }

        return null;
    }

    /**
     * The deepest page in front of the path with the rest of it.
     *
     * @return iterable<array{ResolvedPage, string}>
     */
    private function below(Language $language, string $path): iterable
    {
        $parts = explode('/', trim($path, '/'));

        for ($i = \count($parts) - 1; $i >= 0; --$i) {
            $page = $this->pages->resolve($language, implode('/', \array_slice($parts, 0, $i)));

            if (null !== $page) {
                yield [$page, implode('/', \array_slice($parts, $i))];

                return;
            }
        }
    }

    /**
     * The current path of an object of a plugin, base page and rest.
     */
    private function subpagePath(Target $target, string $language): ?string
    {
        $address = $this->addresses->of($target)[$language] ?? null;
        $base = null === $address || null === $address->basePageId ? null : $this->addresses->of(Target::page($address->basePageId))[$language] ?? null;

        return null === $address || null === $base ? null : ltrim($base->path . '/' . $address->path, '/');
    }

    private function manual(Redirect $redirect, ?Language $language, UriInterface $uri): Resolution
    {
        $status = $redirect->permanent ? 301 : 302;

        if (null !== $redirect->location) {
            return Resolution::redirect(UrlPath::encode($redirect->location), $status);
        }

        $addresses = $this->addresses->of(Target::parse((string) $redirect->target));
        $code = null !== $language && isset($addresses[$language->code]) ? $language->code : (isset($addresses[$this->languages->default()->code]) ? $this->languages->default()->code : array_key_first($addresses));

        if (null === $code) {
            return Resolution::gone();
        }

        return Resolution::redirect($this->link($this->languages->get($code), $addresses[$code]->path, $uri->getAuthority()) . self::query($uri), $status);
    }

    /**
     * A path, or a full URL if the language lives on another host.
     */
    private function link(Language $language, string $path, string $host): string
    {
        $url = UrlPath::encode($language->path($path));

        if (null === $language->domain || \in_array($language, $this->languages->forHost($host), true)) {
            return $url;
        }

        return $this->urls->base($language, null) . $url;
    }

    private static function query(UriInterface $uri): string
    {
        return '' === $uri->getQuery() ? '' : '?' . $uri->getQuery();
    }
}
