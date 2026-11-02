<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Preview\OpenPreview;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Preview\PreviewEditor;
use Frootbox\Core\Routing\Router;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Who sees the website, before the page cache and the pages (decisions
 * 0152 to 0154):
 *
 * - A link with ?fb-preview=<token> keeps the token in a cookie and
 *   leads to the same address without it. With a valid cookie the
 *   request carries PreviewAccess, so pages show the draft, read only,
 *   and its context the link, for comments (decision 0156).
 *   ?fb-preview=0 ends the preview.
 * - While a password protects the website, pages need a signed-in user,
 *   a valid preview or the cookie of the password; everyone else gets
 *   401 with a form. Routes such as the backend and the API check access
 *   themselves and stay open.
 */
final readonly class SiteAccessMiddleware implements MiddlewareInterface
{
    /** The field of the password form. */
    public const string FIELD = 'fb-site-password';

    /**
     * @param ContainerInterface $container The services are loaded on first use, so routes need no database
     */
    public function __construct(
        private ContainerInterface $container,
        private Config $config,
        private Router $router,
        private ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $database = $this->config->get('database.url');

        if (!\is_string($database) || '' === $database) {
            return $handler->handle($request);
        }

        try {
            return $this->guard($request, $handler);
        } catch (TableNotFoundException) {
            // Not migrated yet: nothing to protect.
            return $handler->handle($request);
        }
    }

    private function guard(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $signedIn = $request->getAttribute(Authentication::class) instanceof Authentication;
        $token = $request->getQueryParams()[PreviewEditor::PARAMETER] ?? null;

        if (\is_string($token) && \in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->openPreview($request, $token);
        }

        $page = !$this->isRoute($request->getUri()->getPath());
        $preview = $this->preview($request, $signedIn, $page);

        if (null !== $preview) {
            $request = $request->withAttribute(PreviewAccess::class, $preview);
            $handler = $this->withPreview($handler, $preview->linkId);
        }

        $protection = $this->protection();

        if (!$page || !$protection->on()) {
            return null === $preview ? $handler->handle($request) : self::private($handler->handle($request));
        }

        if ('/robots.txt' === $request->getUri()->getPath()) {
            $response = $this->responses->createResponse(200)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->getBody()->write("User-agent: *\nDisallow: /\n");

            return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $cookie = $request->getCookieParams()[SiteProtection::COOKIE] ?? null;
        $inside = $signedIn || null !== $preview || $protection->grants(\is_string($cookie) ? $cookie : null);

        if (!$inside) {
            $password = 'POST' === $request->getMethod() ? (((array) $request->getParsedBody())[self::FIELD] ?? null) : null;

            if (\is_string($password)) {
                return $this->enter($request, $password);
            }

            throw HttpException::siteProtected($request->getUri()->getPath(), $protection->status()->password);
        }

        $response = $handler->handle($request)->withHeader('X-Robots-Tag', 'noindex, nofollow');

        return null === $preview ? $response : self::private($response);
    }

    /**
     * Checks the token of a link, keeps it in a cookie and leads to the
     * same address without it, so it is in neither history nor referrer.
     */
    private function openPreview(ServerRequestInterface $request, string $token): ResponseInterface
    {
        $location = self::without($request, PreviewEditor::PARAMETER);

        // ?fb-preview=0 ends the preview.
        if ('' === $token || '0' === $token) {
            return $this->redirect($location)->withAddedHeader('Set-Cookie', self::cookie($request, PreviewAccess::COOKIE, '', 0));
        }

        try {
            $opened = $this->service(Runner::class)->run(new OpenPreview($token));
        } catch (HttpException|ActionException $exception) {
            throw self::expired($request, $exception);
        }

        $seconds = max(1, $opened->expiresAt->getTimestamp() - time());

        return $this->redirect($location)->withAddedHeader('Set-Cookie', self::cookie($request, PreviewAccess::COOKIE, $token, $seconds));
    }

    /**
     * The valid preview of the browser, null without one. Signed-in users
     * see their own view (decision 0152). On a page an expired or revoked
     * link gives 410 once and forgets the cookie; routes ignore it.
     */
    private function preview(ServerRequestInterface $request, bool $signedIn, bool $page): ?PreviewAccess
    {
        $token = $request->getCookieParams()[PreviewAccess::COOKIE] ?? null;

        if (!\is_string($token) || '' === $token || $signedIn) {
            return null;
        }

        $link = $this->service(PreviewEditor::class)->valid($token);

        if (null === $link) {
            return $page ? throw self::expired($request) : null;
        }

        return new PreviewAccess($link->id, $link->expiresAt);
    }

    private function enter(ServerRequestInterface $request, string $password): ResponseInterface
    {
        try {
            $access = $this->service(Runner::class)->run(new EnterSite($password));
        } catch (ActionException $exception) {
            throw new HttpException(401, $exception->getMessage(), ['X-Robots-Tag' => 'noindex, nofollow'], $exception, 'site-protected');
        }

        return $this->redirect(self::without($request, ''))->withAddedHeader('Set-Cookie', self::cookie($request, SiteProtection::COOKIE, $access->cookie, $access->days * 86400));
    }

    /**
     * Whether a route answers the path, e.g. the backend, the API or
     * media; they check access themselves.
     */
    private function isRoute(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'), 2)[0];

        return '' !== $first && \in_array($first, $this->router->reservedSegments(), true);
    }

    /**
     * Runs the request with the link in the context, for actions with
     * preview: true such as comment.create (decision 0156).
     */
    private function withPreview(RequestHandlerInterface $handler, string $linkId): RequestHandlerInterface
    {
        return new readonly class ($handler, $this->service(CurrentContext::class), $linkId) implements RequestHandlerInterface {
            public function __construct(
                private RequestHandlerInterface $handler,
                private CurrentContext $context,
                private string $linkId,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->context->runAs($this->context->get()->withPreview($this->linkId), fn(): ResponseInterface => $this->handler->handle($request));
            }
        };
    }

    private function protection(): SiteProtection
    {
        return $this->service(SiteProtection::class);
    }

    private function redirect(string $location): ResponseInterface
    {
        return $this->responses->createResponse(303)->withHeader('Location', $location)->withHeader('Cache-Control', 'no-store');
    }

    private static function expired(ServerRequestInterface $request, ?Throwable $previous = null): HttpException
    {
        return new HttpException(410, 'This preview link has expired or was revoked.', [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Set-Cookie' => self::cookie($request, PreviewAccess::COOKIE, '', 0),
        ], $previous, 'preview-expired');
    }

    /**
     * The draft is for this visitor only, never for caches or search engines.
     */
    private static function private(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'same-origin');
    }

    /**
     * The address of the request without a query parameter ('' for none).
     */
    private static function without(ServerRequestInterface $request, string $parameter): string
    {
        $query = $request->getQueryParams();
        unset($query[$parameter]);
        $string = http_build_query($query);

        return $request->getUri()->getPath() . ('' === $string ? '' : '?' . $string);
    }

    private static function cookie(ServerRequestInterface $request, string $name, string $value, int $seconds): string
    {
        $parts = [$name . '=' . rawurlencode($value), 'Path=/', 'HttpOnly', 'SameSite=Lax', 'Max-Age=' . $seconds];

        if ('https' === $request->getUri()->getScheme()) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
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
