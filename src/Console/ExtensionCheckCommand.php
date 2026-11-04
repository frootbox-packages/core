<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Extension\Check\CheckReport;
use Frootbox\Core\Extension\Check\ExtensionChecker;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks an extension package without installing it (decision 0278):
 * composer.json, frootbox.yaml, loading, names of actions, field types
 * and tables, migrations, blocks and layouts, licenses of dependencies.
 * Without a target it checks the packages of the website below
 * extensions/.
 */
#[AsCommand(name: 'extension:check', description: 'Checks an extension package: names, migrations, blocks and licenses')]
final class ExtensionCheckCommand extends Command
{
    public function __construct(
        private readonly ExtensionChecker $checker,
        private readonly Extensions $extensions,
        private readonly ProjectPaths $paths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'A folder with composer.json and frootbox.yaml, or the name of an installed package')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the reports as JSON')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Count hints as errors, e.g. in CI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getArgument('target');
        $strict = (bool) $input->getOption('strict');
        $reports = [];

        if (\is_string($target) && '' !== $target) {
            $folder = $this->folder($target);

            if (null !== $folder) {
                $reports[] = $this->checker->folder($folder);
            } elseif (null !== $this->extensions->get($target)) {
                $reports[] = $this->checker->package($target);
            } else {
                $io->error(\sprintf('"%s" is neither a folder nor an installed package.', $target));

                return Command::FAILURE;
            }
        } else {
            foreach ($this->extensions as $package) {
                if ($package->isProject) {
                    $reports[] = $this->checker->package($package->name);
                }
            }
        }

        $ok = [] === array_filter($reports, static fn(CheckReport $report): bool => !$report->ok($strict));

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode(['ok' => $ok, 'packages' => array_map(static fn(CheckReport $report): array => $report->toArray($strict), $reports)], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if ([] === $reports) {
            $io->note('This website has no packages below extensions/. Name a folder or an installed package, e.g. "extension:check frootbox/news".');

            return Command::SUCCESS;
        }

        foreach ($reports as $report) {
            $this->show($io, $report, $strict);
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function show(SymfonyStyle $io, CheckReport $report, bool $strict): void
    {
        $io->section($report->name . (null === $report->version ? '' : ' ' . $report->version));
        $facts = array_filter([
            [] === $report->license ? null : 'License ' . implode(' or ', $report->license),
            null === $report->frootbox ? null : 'Frootbox ' . $report->frootbox,
            $report->contents->summary(),
        ], static fn(?string $fact): bool => null !== $fact && '' !== $fact);

        if ([] !== $facts) {
            $io->text(implode(' · ', $facts));
        }

        if ([] !== $report->errors) {
            $io->error(\sprintf('%d problem(s) found', \count($report->errors)));
            $io->listing(array_map(strval(...), $report->errors));
        }

        if ([] !== $report->warnings) {
            $io->warning(\sprintf('%d hint(s)', \count($report->warnings)) . ($strict ? ', counted as problems with --strict' : ''));
            $io->listing(array_map(strval(...), $report->warnings));
        }

        if ($report->ok($strict)) {
            $io->success(\sprintf('%s is fine.', $report->name));
        }
    }

    /**
     * The target as a folder, relative to the current folder or the
     * project, or null when it is no folder.
     */
    private function folder(string $target): ?string
    {
        foreach ([$target, $this->paths->root . '/' . $target] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
