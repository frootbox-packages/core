<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageResolver;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Finds what a request path leads to (decision 0105), in this order:
 * the current address of a page, a manual redirect, an earlier address
 * (301 to the current one, 410 if the target is gone), nothing.
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
        $match = $this->languages->match($host, $uri->getPath());

        if (null !== $match) {
            $page = $this->pages->resolve($match[0], $match[1]);

            if (null !== $page) {
                return Resolution::page($page);
            }
        } elseif ('/' === $uri->getPath()) {
            $language = $this->languages->preferred($host, $request->getHeaderLine('Accept-Language'));

            return Resolution::redirect($this->link($language, '', $host), 302, ['Accept-Language']);
        }

        $redirect = $this->redirects->at($uri->getPath());

        if (null !== $redirect) {
            return $this->manual($redirect, $match[0] ?? null, $uri);
        }

        if (null === $match) {
            return Resolution::notFound();
        }

        $earlier = $this->addresses->earlier($match[0]->code, trim($match[1], '/'));

        if (null === $earlier) {
            return Resolution::notFound();
        }

        $current = $this->addresses->of(Target::parse($earlier->target))[$match[0]->code] ?? null;

        return null === $current ? Resolution::gone() : Resolution::redirect($this->link($match[0], $current->path, $host) . self::query($uri));
    }

    private function manual(Redirect $redirect, ?Language $language, UriInterface $uri): Resolution
    {
        $status = $redirect->permanent ? 301 : 302;

        if (null !== $redirect->location) {
            return Resolution::redirect($redirect->location, $status);
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
        $url = $language->path($path);

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
