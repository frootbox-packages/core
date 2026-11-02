<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests;

use Frootbox\Core\Application;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigLoader;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Error\ErrorMiddleware;
use Frootbox\Core\Error\ErrorPageRenderer;
use Frootbox\Core\Http\CallableRequestHandler;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Http\WelcomeHandler;
use Frootbox\Core\Log\LoggerFactory;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Routing\Route;
use Frootbox\Core\Routing\RouteMatch;
use Frootbox\Core\Routing\Router;
use Frootbox\Core\Routing\RoutingMiddleware;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\Tests\Support\TextHandler;
use Frootbox\Core\Tests\Support\ThrowingHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(Application::class)]
#[CoversClass(ContainerFactory::class)]
#[CoversClass(ErrorMiddleware::class)]
#[CoversClass(ErrorPageRenderer::class)]
#[CoversClass(NotFoundHandler::class)]
#[CoversClass(RoutingMiddleware::class)]
#[CoversClass(WelcomeHandler::class)]
#[CoversClass(ProjectPaths::class)]
#[UsesClass(Config::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(Environment::class)]
#[UsesClass(CallableRequestHandler::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(MiddlewareDispatcher::class)]
#[UsesClass(Route::class)]
#[UsesClass(RouteMatch::class)]
#[UsesClass(Router::class)]
#[UsesClass(LoggerFactory::class)]
final class ApplicationTest extends TestCase
{
    private ?TemporaryProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->remove();
    }

    public function testShowsWelcomePage(): void
    {
        $response = $this->request($this->application('dev'), 'GET', '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Testsite', (string) $response->getBody());
        self::assertStringContainsString('Entwicklung', (string) $response->getBody());
    }

    public function testUnknownPathIs404(): void
    {
        $response = $this->request($this->application('prod'), 'GET', '/missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Seite nicht gefunden', (string) $response->getBody());
    }

    public function testWrongMethodIs405(): void
    {
        $response = $this->request($this->application('prod'), 'POST', '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testRouteParametersReachTheHandler(): void
    {
        $application = $this->application('dev');
        $this->router($application)->get('/items/{id}', TextHandler::class);

        self::assertSame('id=7', (string) $this->request($application, 'GET', '/items/7')->getBody());
    }

    public function testDevelopmentShowsErrorDetails(): void
    {
        $application = $this->application('dev');
        $this->router($application)->get('/boom', ThrowingHandler::class);

        $response = $this->request($application, 'GET', '/boom');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString(ThrowingHandler::MESSAGE, (string) $response->getBody());
    }

    public function testProductionHidesErrorDetails(): void
    {
        $application = $this->application('prod');
        $this->router($application)->get('/boom', ThrowingHandler::class);

        $response = $this->request($application, 'GET', '/boom');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString(ThrowingHandler::MESSAGE, (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testServerErrorsAreLogged(): void
    {
        $application = $this->application('prod');
        $this->router($application)->get('/boom', ThrowingHandler::class);

        $this->request($application, 'GET', '/boom');

        self::assertNotNull($this->project);
        $log = $this->project->log('prod');
        self::assertStringContainsString('frootbox.ERROR: GET /boom failed with 500: ' . ThrowingHandler::MESSAGE, $log);
        self::assertStringContainsString('[stacktrace]', $log);
    }

    public function testNotFoundIsOnlyLoggedAsDebug(): void
    {
        $application = $this->application('prod');

        $this->request($application, 'GET', '/missing');

        self::assertNotNull($this->project);
        self::assertSame('', $this->project->log('prod'));
    }

    public function testProductionCompilesTheContainer(): void
    {
        $this->application('prod');

        self::assertNotNull($this->project);
        self::assertFileExists($this->project->root . '/var/cache/container/CompiledContainer.php');
    }

    private function application(string $environment): Application
    {
        $this->project = TemporaryProject::create();

        return Application::fromProjectRoot($this->project->root, ['APP_ENV' => $environment]);
    }

    private function router(Application $application): Router
    {
        $router = $application->container()->get(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router;
    }

    private function request(Application $application, string $method, string $path): ResponseInterface
    {
        return $application->handle(new ServerRequest($method, $path));
    }
}
