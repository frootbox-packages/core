<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Component\ComponentCheck;
use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks all blocks and layouts: schemas, overrides and Twig syntax.
 * Ignores the cache, so it also suits a deployment pipeline. Missing
 * groups and previews are warnings (decision 0148), so are fixed
 * headings and an outline that breaks at one of three places (decision
 * 0129). extension:check runs the same checks for one package.
 */
#[AsCommand(name: 'blocks:check', description: 'Checks schemas, overrides and Twig syntax of all blocks and layouts')]
final class BlocksCheckCommand extends Command
{
    public function __construct(
        private readonly ComponentLoader $loader,
        private readonly ComponentCheck $check,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $components = $this->loader->load();
        } catch (ComponentException $exception) {
            $io->error(\sprintf('%d problem(s) found', \count($exception->errors)));
            $io->listing($exception->errors);

            return Command::FAILURE;
        }

        $all = [...$components->blocks, ...$components->layouts];
        $errors = [...$this->check->syntax($all), ...$this->check->objects()];

        if ([] !== $errors) {
            $io->error(\sprintf('%d problem(s) found', \count($errors)));
            $io->listing($errors);

            return Command::FAILURE;
        }

        $warnings = [];
        $templates = 0;

        foreach ($all as $component) {
            $templates += \count($component->variants);
            array_push($warnings, ...$this->check->warnings($component));
        }

        if ([] !== $warnings) {
            $io->warning(\sprintf('%d hint(s)', \count($warnings)));
            $io->listing($warnings);
        }

        $io->success(\sprintf('%d blocks and %d layouts with %d templates are fine.', \count($components->blocks), \count($components->layouts), $templates));

        return Command::SUCCESS;
    }
}
