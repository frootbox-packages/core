<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Runner;
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
use Frootbox\Core\ObjectField\MarkedObjects;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Url\NotFoundLog;
use Frootbox\Core\Url\Resolution;
use Frootbox\Core\Url\UrlPath;
use Frootbox\Core\Url\UrlResolver;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\Head;
use Frootbox\Core\Visitor\VisitorSession;
use Frootbox\Database\Ulid;
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
        // POST only for the password of a protected page (decision 0358).
        $password = 'POST' === $request->getMethod() ? (((array) $request->getParsedBody())[PagePasswords::FIELD] ?? null) : null;

        if (!\in_array($request->getMethod(), self::METHODS, true) && !\is_string($password)) {
            throw HttpException::methodNotAllowed($request->getMethod(), self::METHODS);
        }

        $uri = $request->getUri();
        $path = $uri->getPath();

        if ('/' !== $path && str_ends_with($path, '/')) {
            $target = rtrim($path, '/');

            return $this->responses->createResponse(301)->withHeader('Location', ('' === $target ? '/' : $target) . ('' === $uri->getQuery() ? '' : '?' . $uri->getQuery()));
        }

        $installed = $this->isInstalled();
        // A preview link shows the drafts like the editing mode, read only (decision 0152).
        $preview = $request->getAttribute(PreviewAccess::class) instanceof PreviewAccess;
        $editing = $installed && ($preview || $this->wantsEditMode($request));
        $this->service(EditMode::class)->set($editing);
        $this->service(MarkedObjects::class)->clear();

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

            // A page with a password: never in the page cache (decision 0358).
            $protectedBy = $this->service(PageOutline::class)->protectedBy($resolution->page->id);

            if (null !== $protectedBy) {
                $inside = $preview
                    || $request->getAttribute(Authentication::class) instanceof Authentication
                    || $this->service(PagePasswords::class)->grants($protectedBy, $request->getCookieParams());

                if (!$inside) {
                    return \is_string($password) ? $this->enter($request, $protectedBy, $password) : throw HttpException::pageProtected($path);
                }

                if (\is_string($password)) {
                    return $this->redirect($request);
                }
            } elseif (\is_string($password)) {
                throw HttpException::methodNotAllowed($request->getMethod(), self::METHODS);
            }

            if ($editing && !$preview) {
                // A page in a fallback language has no content of its own to change (decision 0122).
                $marking = !$resolution->page->isFallback() && $this->mayChange($request, $resolution->page->id);
                $this->service(EditMode::class)->set(true, $marking, $marking && $this->mayChangeSite($request));
            } elseif ($preview) {
                // Light marks, so the customer can comment on a block or field (decision 0156).
                $this->service(EditMode::class)->set(true, anchors: true);
            }

            $html = $this->render($resolution->page, $request);

            if (null !== $protectedBy) {
                return self::protected($this->page($html, null));
            }

            $cache = $this->service(PageCache::class);

            if (!$cache->accepts($request) || !$this->service(Delivery::class)->cacheable() || $this->service(VisitorSession::class)->active()) {
                return $this->page($html, null);
            }

            $cache->put($request, $html, $this->service(Delivery::class)->parameters(), $this->service(Delivery::class)->cacheLifetime());

            return $this->page($html, 'miss');
        }

        if (\is_string($password)) {
            throw HttpException::methodNotAllowed($request->getMethod(), self::METHODS);
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
            $this->service(NotFoundLog::class)->record(UrlPath::decode($path) ?? $path);
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

    /**
     * Whether the user may change the fields of the website (decision 0128).
     */
    private function mayChangeSite(ServerRequestInterface $request): bool
    {
        $authentication = $request->getAttribute(Authentication::class);
        $catalog = $this->service(Catalog::class);

        return $authentication instanceof Authentication
            && $catalog->has('site.update-field')
            && $this->service(Gate::class)->allows($authentication->actor, $catalog->get('site.update-field'));
    }

    /**
     * Checks the password a visitor entered for the protected page and
     * leads back to the same address with the cookie (decision 0358).
     */
    private function enter(ServerRequestInterface $request, string $pageId, string $password): ResponseInterface
    {
        try {
            $access = $this->service(Runner::class)->run(new EnterPage($pageId, $password));
        } catch (ActionException $exception) {
            throw HttpException::pageProtected($request->getUri()->getPath(), $exception);
        }

        $parts = [$access->cookie . '=' . rawurlencode($access->value), 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=' . ($access->days * 86400)];

        if ('https' === $request->getUri()->getScheme()) {
            $parts[] = 'Secure';
        }

        return $this->redirect($request)->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    private function redirect(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getUri()->getQuery();

        return $this->responses->createResponse(303)
            ->withHeader('Location', $request->getUri()->getPath() . ('' === $query ? '' : '?' . $query))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * A protected page is for this visitor only, never for caches or
     * search engines.
     */
    private static function protected(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
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
        $this->service(Delivery::class)->set($languages->get($page->language), $languages->get($page->contentLanguage), $page->target(), $page->subpage);
        $view = $page->translation->content['plugin'] ?? null;
        $this->service(Delivery::class)->setPlugin($page->data->plugin, \is_array($view) ? $view : null);
        $this->service(Delivery::class)->setQuery($request->getQueryParams());

        $assets = new AssetList();
        $layout = Instance::fromArray(['layout' => $page->data->layout, 'variant' => $page->data->variant, ...$page->translation->content], ComponentKind::Layout);
        // An object of a plugin has its own title and addresses (decision 0219).
        $shown = $page->subpage->target ?? $page->target();
        $title = $page->subpage->title ?? $page->translation->title;
        $body = $this->service(ComponentRenderer::class)->layout($layout, $assets, $title);

        $site = $this->config->string('app.name', 'Frootbox');
        $base = $this->service(SiteUrl::class)->base($request);
        // A title of its own for search engines is for the page alone, not for its objects (decision 0266).
        $start = $page->isStart() && null === $page->subpage;
        $head = new Head(
            $page->language,
            null === $page->subpage ? $page->translation->searchTitle($site, $start) : TranslationData::defaultTitle($title, $site, false),
            // A fallback points search engines to the original.
            $urls->absolute($shown, $page->isFallback() ? $page->contentLanguage : $page->language, $base),
            $languages->get($page->language)->direction->value,
        );
        $head->name = null === $page->subpage && '' !== $page->translation->seoTitle ? $page->translation->seoTitle : $title;
        $head->site = $site;
        $head->start = $start;
        $head->description = null === $page->subpage ? $page->translation->description : $page->subpage->description;
        $head->image = null === $page->subpage ? self::firstImage($page->translation->content) : $page->subpage->image;

        if (null !== $this->service(PageOutline::class)->excludedFromSearchEnginesBy($page->id) || null !== $this->service(PageOutline::class)->protectedBy($page->id)) {
            $head->add('<meta name="robots" content="noindex">');
        }

        $addresses = $urls->addresses($shown);

        foreach ($languages->codes() as $code) {
            if (isset($addresses[$code]) && !$addresses[$code]->fallback) {
                $url = $urls->absolute($shown, $code, $base);

                if (null !== $url) {
                    $head->alternate($code, $url);
                }
            }
        }

        return $this->service(Document::class)->render($head, $body, $assets, $request, $page->target());
    }

    /**
     * The first value of an image field in the content, e.g. for sharing
     * the page (decision 0223).
     *
     * @param array<mixed> $content
     *
     * @return array{media: string, alt?: string}|null
     */
    private static function firstImage(array $content): ?array
    {
        $media = $content['media'] ?? null;

        if (\is_string($media) && Ulid::isValid($media)) {
            $alt = $content['alt'] ?? null;

            return \is_string($alt) && '' !== $alt ? ['media' => $media, 'alt' => $alt] : ['media' => $media];
        }

        foreach ($content as $value) {
            $image = \is_array($value) ? self::firstImage($value) : null;

            if (null !== $image) {
                return $image;
            }
        }

        return null;
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
