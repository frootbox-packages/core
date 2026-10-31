<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Http\SiteUrl;
use Frootbox\Core\Http\WelcomeHandler;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Url\NotFoundLog;
use Frootbox\Core\Url\Resolution;
use Frootbox\Core\Url\UrlResolver;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\Head;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delivers published pages when no route matches (decisions 0104 and
 * 0105): finds the page by language and path, follows redirects, logs
 * what found nothing. Without a published start page, "/" shows the
 * welcome page.
 */
final readonly class PageHandler implements RequestHandlerInterface
{
    private const array METHODS = ['GET', 'HEAD'];

    /**
     * @param ContainerInterface $container The services are loaded on first use, so requests that a route answers need no database
     */
    public function __construct(
        private ContainerInterface $container,
        private Config $config,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!\in_array($request->getMethod(), self::METHODS, true)) {
            throw HttpException::methodNotAllowed($request->getMethod(), self::METHODS);
        }

        $uri = $request->getUri();
        $path = $uri->getPath();

        if ('/' !== $path && str_ends_with($path, '/')) {
            $target = rtrim($path, '/');

            return $this->responses->createResponse(301)->withHeader('Location', ('' === $target ? '/' : $target) . ('' === $uri->getQuery() ? '' : '?' . $uri->getQuery()));
        }

        $installed = $this->isInstalled();
        $editing = $installed && $this->wantsEditMode($request);
        $this->service(EditMode::class)->set($editing);

        if ($installed) {
            $this->service(Scheduler::class)->runIfDue();
            $cached = $this->service(PageCache::class)->get($request);

            if (null !== $cached) {
                return $this->page($cached, 'hit');
            }
        }

        $resolution = $installed ? $this->resolve($request) : null;

        if (null === $resolution) {
            return '/' === $path ? $this->service(WelcomeHandler::class)->handle($request) : throw HttpException::notFound($path);
        }

        if (null !== $resolution->page) {
            // Locked pages are not cached, the cache only holds pages that were delivered.
            // Editors see them in the editing mode, the bar says they are locked.
            if (!$editing && null !== $this->service(PageOutline::class)->lockedBy($resolution->page->id)) {
                throw HttpException::locked($path);
            }

            if ($editing) {
                $this->service(EditMode::class)->set(true, $this->mayChange($request, $resolution->page->id));
            }

            $html = $this->render($resolution->page, $request);
            $cache = $this->service(PageCache::class);

            if (!$cache->accepts($request)) {
                return $this->page($html, null);
            }

            $cache->put($request, $html);

            return $this->page($html, 'miss');
        }

        if (null !== $resolution->location) {
            $response = $this->responses->createResponse($resolution->status)->withHeader('Location', $resolution->location);

            return [] === $resolution->vary ? $response : $response->withHeader('Vary', implode(', ', $resolution->vary));
        }

        // No start page published yet.
        if ('/' === $path) {
            return $this->service(WelcomeHandler::class)->handle($request);
        }

        if (410 === $resolution->status) {
            throw HttpException::gone($path);
        }

        if ('GET' === $request->getMethod()) {
            $this->service(NotFoundLog::class)->record($path);
        }

        throw HttpException::notFound($path);
    }

    /**
     * The editing mode is on for users who may see pages and switched it
     * on in the bar (decision 0119).
     */
    private function wantsEditMode(ServerRequestInterface $request): bool
    {
        $authentication = $request->getAttribute(Authentication::class);

        if ('1' !== ($request->getCookieParams()[EditMode::COOKIE] ?? null) || !$authentication instanceof Authentication || ActorType::User !== $authentication->actor->type) {
            return false;
        }

        $catalog = $this->service(Catalog::class);

        return $catalog->has('page.show') && $this->service(Gate::class)->allows($authentication->actor, $catalog->get('page.show'));
    }

    /**
     * Whether the user may change the page, so its fields get marked.
     */
    private function mayChange(ServerRequestInterface $request, string $id): bool
    {
        $authentication = $request->getAttribute(Authentication::class);
        $catalog = $this->service(Catalog::class);

        return $authentication instanceof Authentication
            && $catalog->has('page.update')
            && $this->service(Gate::class)->allowsOn($authentication->actor, $catalog->get('page.update'), [Scope::page($id)]);
    }

    private function isInstalled(): bool
    {
        $database = $this->config->get('database.url');

        return \is_string($database) && '' !== $database;
    }

    private function page(string $html, ?string $cache): ResponseInterface
    {
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($html));

        return null === $cache ? $response : $response->withHeader(PageCache::HEADER, $cache);
    }

    /**
     * Null if Frootbox is not migrated yet: no pages then.
     */
    private function resolve(ServerRequestInterface $request): ?Resolution
    {
        $this->service(Urls::class)->clear();
        $this->service(PageOutline::class)->clear();

        try {
            return $this->service(UrlResolver::class)->resolve($request);
        } catch (TableNotFoundException) {
            return null;
        }
    }

    private function render(ResolvedPage $page, ServerRequestInterface $request): string
    {
        $languages = $this->service(Languages::class);
        $urls = $this->service(Urls::class);
        $this->service(Delivery::class)->set($languages->get($page->language), $languages->get($page->contentLanguage), $page->target());

        $assets = new AssetList();
        $layout = Instance::fromArray(['layout' => $page->data->layout, 'variant' => $page->data->variant, ...$page->translation->content], ComponentKind::Layout);
        $body = $this->service(ComponentRenderer::class)->layout($layout, $assets);

        $site = $this->config->string('app.name', 'Frootbox');
        $base = $this->service(SiteUrl::class)->base($request);
        $head = new Head(
            $page->language,
            $page->isStart() ? $site : $page->translation->title . ' – ' . $site,
            // A fallback points search engines to the original.
            $urls->absolute($page->target(), $page->isFallback() ? $page->contentLanguage : $page->language, $base),
        );

        if (null !== $this->service(PageOutline::class)->excludedFromSearchEnginesBy($page->id)) {
            $head->add('<meta name="robots" content="noindex">');
        }

        $addresses = $urls->addresses($page->target());

        foreach ($languages->codes() as $code) {
            if (isset($addresses[$code]) && !$addresses[$code]->fallback) {
                $url = $urls->absolute($page->target(), $code, $base);

                if (null !== $url) {
                    $head->alternate($code, $url);
                }
            }
        }

        return $this->service(Document::class)->render($head, $body, $assets, $request, $page->target());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);

        return $service instanceof $class ? $service : throw new LogicException(\sprintf('%s is not available.', $class));
    }
}
