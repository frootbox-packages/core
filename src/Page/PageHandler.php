<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Http\SiteUrl;
use Frootbox\Core\Http\WelcomeHandler;
use Frootbox\Core\Language\Languages;
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

        $resolution = $this->resolve($request);

        if (null === $resolution) {
            return '/' === $path ? $this->service(WelcomeHandler::class)->handle($request) : throw HttpException::notFound($path);
        }

        if (null !== $resolution->page) {
            return $this->responses->createResponse(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody($this->streams->createStream($this->render($resolution->page, $request)));
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
     * Null if Frootbox is not installed or migrated yet: no pages then.
     */
    private function resolve(ServerRequestInterface $request): ?Resolution
    {
        $database = $this->config->get('database.url');

        if (!\is_string($database) || '' === $database) {
            return null;
        }

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
