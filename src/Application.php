<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core;

use DI\Container;
use Frootbox\Core\Config\ConfigLoader;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Error\ErrorMiddleware;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Http\ResponseEmitter;
use Frootbox\Core\Routing\RoutingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

/**
 * Starts an installation and answers requests.
 *
 * public/index.php only calls Application::fromProjectRoot(...)->run().
 */
final readonly class Application
{
    /**
     * Middleware in the order they see the request.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    private const array MIDDLEWARE = [
        ErrorMiddleware::class,
        RoutingMiddleware::class,
    ];

    private function __construct(private Container $container) {}

    /**
     * @param array<string, string>|null $serverEnvironment Defaults to the real environment (getenv()).
     */
    public static function fromProjectRoot(string $projectRoot, ?array $serverEnvironment = null): self
    {
        $paths = new ProjectPaths($projectRoot);
        $config = ConfigLoader::load($paths, $serverEnvironment ?? getenv());

        return new self(ContainerFactory::create($config, $paths));
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_map($this->middleware(...), self::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->service(NotFoundHandler::class, RequestHandlerInterface::class))
            ->handle($request);
    }

    /**
     * Reads the request from PHP globals and sends the response.
     */
    public function run(): void
    {
        $factory = new Psr17Factory();
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();

        new ResponseEmitter()->emit($this->handle($request));
    }

    /**
     * Runs a command of bin/frootbox and returns its exit code.
     */
    public function runConsole(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $console = $this->service(ConsoleApplication::class, ConsoleApplication::class);
        $console->setAutoExit(false);

        return $console->run($input, $output);
    }

    /**
     * @param class-string<MiddlewareInterface> $class
     */
    private function middleware(string $class): MiddlewareInterface
    {
        return $this->service($class, MiddlewareInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string    $id
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(string $id, string $type): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $type) {
            throw new UnexpectedValueException(\sprintf('Service "%s" must implement %s.', $id, $type));
        }

        return $service;
    }
}
