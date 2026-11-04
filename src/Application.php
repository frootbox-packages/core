<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core;

use DI\Container;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\AuthenticationMiddleware;
use Frootbox\Core\Backup\RestoreLock;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Config\ConfigLoader;
use Frootbox\Core\Config\TimeZone;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Error\ErrorMiddleware;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\Source\ComposerSource;
use Frootbox\Core\Extension\Source\ExtensionSource;
use Frootbox\Core\Http\AfterResponse;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\ResponseEmitter;
use Frootbox\Core\Install\InstallCommand;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Routing\RoutingMiddleware;
use Frootbox\Core\Site\SiteAccessMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Starts an installation and answers requests.
 *
 * public/index.php only calls Application::fromProjectRoot(...)->run().
 */
final readonly class Application
{
    /**
     * Middleware in the order they see the request. Public so tests run
     * the same chain.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array MIDDLEWARE = [
        // Before anything reads the database (decision 0183).
        RestoreLock::class,
        ErrorMiddleware::class,
        AuthenticationMiddleware::class,
        // Again behind the sign-in, so error pages know the user (decision 0132).
        ErrorMiddleware::class,
        // Preview links and the password of the website, before the page cache (decisions 0152, 0153).
        SiteAccessMiddleware::class,
        RoutingMiddleware::class,
    ];

    private function __construct(private Container $container) {}

    /**
     * @param array<string, string>|null $serverEnvironment Defaults to the real environment (getenv()).
     * @param list<ExtensionSource>|null $sources           Where extensions are found, by default the
     *                                                      Composer packages of the project; the
     *                                                      workbench brings its own (decision 0254)
     */
    public static function fromProjectRoot(string $projectRoot, ?array $serverEnvironment = null, ?array $sources = null): self
    {
        $paths = new ProjectPaths($projectRoot);
        $config = ConfigLoader::load($paths, $serverEnvironment ?? getenv());
        TimeZone::apply($config);

        $sources ??= [new ComposerSource($paths->root . '/vendor', $paths->root . '/extensions')];
        $extensions = ExtensionLoader::discover($sources, $paths, useCache: !$config->environment->isDevelopment());

        return new self(ContainerFactory::create($config, $paths, $extensions, ExtensionLoader::boot($extensions)));
    }

    /**
     * Entry point of bin/frootbox. "install" runs before the installation
     * is booted, so it also works without a configuration; other commands
     * then point to it.
     *
     * @param array<string, string>|null $serverEnvironment Defaults to the real environment (getenv()).
     * @param list<ExtensionSource>|null $sources           See fromProjectRoot()
     */
    public static function console(string $projectRoot, ?InputInterface $input = null, ?OutputInterface $output = null, ?array $serverEnvironment = null, ?array $sources = null): int
    {
        $input ??= new ArgvInput();
        $output ??= new ConsoleOutput();
        $serverEnvironment ??= getenv();
        $paths = new ProjectPaths($projectRoot);

        if ('install' === $input->getFirstArgument()) {
            $console = new ConsoleApplication('Frootbox', Frootbox::VERSION);
            $console->setAutoExit(false);
            $console->addCommand(new InstallCommand($paths, $serverEnvironment, $sources));

            return $console->run($input, $output);
        }

        try {
            $application = self::fromProjectRoot($projectRoot, $serverEnvironment, $sources);
        } catch (ConfigException $error) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln(\sprintf('<error>%s</error>', $error->getMessage()));
            $errorOutput->writeln('Not set up yet? Run: php bin/frootbox install');

            return Command::FAILURE;
        }

        return $application->runConsole($input, $output);
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_map($this->middleware(...), self::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->service(PageHandler::class, RequestHandlerInterface::class))
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

        // E.g. jobs of the queue without cron (decision 0221).
        $after = $this->service(AfterResponse::class, AfterResponse::class);

        if ($after->pending()) {
            ignore_user_abort(true);

            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $after->run();
        }
    }

    /**
     * Runs a command of bin/frootbox and returns its exit code.
     *
     * Failed commands are logged. Wrong input such as an unknown command or
     * a missing argument is only shown, not logged.
     */
    public function runConsole(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $console = $this->service(ConsoleApplication::class, ConsoleApplication::class);
        $console->setAutoExit(false);
        $console->setCatchExceptions(false);
        $output ??= new ConsoleOutput();

        // Commands act as the system, like a cron job.
        $this->service(CurrentContext::class, CurrentContext::class)->set(Context::system(Channel::Console));

        try {
            return $console->run($input, $output);
        } catch (Throwable $throwable) {
            if (!$throwable instanceof ConsoleException) {
                $this->service(LoggerInterface::class, LoggerInterface::class)
                    ->error('Console command failed: {message}', ['message' => $throwable->getMessage(), 'exception' => $throwable]);
            }

            $console->renderThrowable($throwable, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

            return Command::FAILURE;
        }
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
