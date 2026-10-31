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
use Frootbox\Core\View\ComponentRenderer;
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
 * Delivers published pages when no route matches (decision 0104). Without
 * a published start page, "/" shows the welcome page.
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

        $page = $this->find($path);

        if (null === $page) {
            return '/' === $path ? $this->service(WelcomeHandler::class)->handle($request) : throw HttpException::notFound($path);
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($this->render($page, $request)));
    }

    private function find(string $path): ?ResolvedPage
    {
        $database = $this->config->get('database.url');

        // Not installed or not migrated yet: no pages.
        if (!\is_string($database) || '' === $database) {
            return null;
        }

        try {
            return $this->service(PageResolver::class)->resolve($path);
        } catch (TableNotFoundException) {
            return null;
        }
    }

    private function render(ResolvedPage $page, ServerRequestInterface $request): string
    {
        $assets = new AssetList();
        $layout = Instance::fromArray(['layout' => $page->data->layout, 'variant' => $page->data->variant, ...$page->translation->content], ComponentKind::Layout);
        $body = $this->service(ComponentRenderer::class)->layout($layout, $assets);

        $site = $this->config->string('app.name', 'Frootbox');
        $base = $this->service(SiteUrl::class)->base($request);
        $head = new Head(
            $page->language,
            $page->isStart() ? $site : $page->translation->title . ' – ' . $site,
            null === $base ? null : $base . $page->path,
        );

        return $this->service(Document::class)->render($head, $body, $assets, $request, $page->id);
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
