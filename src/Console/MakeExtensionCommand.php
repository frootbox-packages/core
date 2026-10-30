<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\ProjectPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a project extension in extensions/ and registers the folder as
 * a Composer path repository.
 */
#[AsCommand(name: 'make:extension', description: 'Creates a new project extension in extensions/')]
final class MakeExtensionCommand extends Command
{
    private const string NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    public function __construct(private readonly ProjectPaths $paths)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Composer package name, e.g. acme/website')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title shown in the backend');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $name = \is_string($name) ? $name : '';

        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            $io->error(\sprintf('"%s" is not a valid package name. Use vendor/package in lower case, e.g. acme/website.', $name));

            return Command::INVALID;
        }

        [$vendor, $package] = explode('/', $name);
        $directory = $this->paths->root . '/extensions/' . $vendor . '-' . $package;

        if (file_exists($directory)) {
            $io->error(\sprintf('%s already exists.', $this->relative($directory)));

            return Command::FAILURE;
        }

        $namespace = self::studly($vendor) . '\\' . self::studly($package);
        $class = self::studly($package) . 'Extension';
        $title = \is_string($input->getOption('title')) ? $input->getOption('title') : self::title($package);

        $this->write($directory . '/composer.json', $this->composerJson($name, $title, $namespace));
        $this->write($directory . '/' . ExtensionPackage::MANIFEST, $this->manifest($title, $namespace . '\\' . $class));
        $this->write($directory . '/src/' . $class . '.php', $this->extensionClass($namespace, $class));
        $repositoryAdded = $this->addPathRepository();

        $io->success(\sprintf('Created %s', $this->relative($directory)));

        if ($repositoryAdded) {
            $io->text('Added extensions/* as a path repository to composer.json.');
        }

        $io->text(['Install it once, changes then show up immediately:', '', \sprintf('  composer require %s', $name)]);

        return Command::SUCCESS;
    }

    private function composerJson(string $name, string $title, string $namespace): string
    {
        return self::json([
            'name' => $name,
            'description' => $title,
            'type' => 'frootbox-extension',
            'version' => '1.0.0',
            'license' => 'proprietary',
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                    $namespace . '\\Migrations\\' => 'migrations/',
                ],
            ],
        ]);
    }

    private function manifest(string $title, string $class): string
    {
        return \sprintf("title: %s\ndescription: ''\nextension: %s\n", self::yamlString($title), $class);
    }

    private function extensionClass(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Frootbox\\Core\\Extension\\Extension;
            use Frootbox\\Core\\Extension\\Registry;

            final class {$class} implements Extension
            {
                public function register(Registry \$registry): void
                {
                    // Routes, listeners and console commands of this extension, e.g.:
                    // \$registry->get('/feed.xml', FeedHandler::class);
                    // \$registry->listen(SomeEvent::class, SomeListener::class);
                    // \$registry->command(ImportCommand::class);
                    //
                    // Migrations in migrations/ are found automatically.
                }
            }

            PHP;
    }

    /**
     * Adds extensions/* to the repositories of the project's composer.json.
     * Returns true if it was missing.
     */
    private function addPathRepository(): bool
    {
        $file = $this->paths->root . '/composer.json';
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!\is_array($composer)) {
            return false;
        }

        $repositories = \is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [];

        foreach ($repositories as $repository) {
            if (\is_array($repository) && 'extensions/*' === ($repository['url'] ?? null)) {
                return false;
            }
        }

        $repositories[] = ['type' => 'path', 'url' => 'extensions/*'];
        $composer['repositories'] = $repositories;
        $this->write($file, self::json($composer));

        return true;
    }

    private function write(string $file, string $content): void
    {
        $directory = \dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Could not create %s.', $directory));
        }

        if (false === file_put_contents($file, $content)) {
            throw new \RuntimeException(\sprintf('Could not write %s.', $file));
        }
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->paths->root . '/') ? substr($path, \strlen($this->paths->root) + 1) : $path;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * acme-web_site => AcmeWebSite
     */
    private static function studly(string $name): string
    {
        $parts = preg_split('/[-_.]+/', $name);

        return implode('', array_map(ucfirst(...), false === $parts ? [$name] : $parts));
    }

    /**
     * my-website => My Website
     */
    private static function title(string $package): string
    {
        return ucwords(str_replace(['-', '_', '.'], ' ', $package));
    }

    private static function yamlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
