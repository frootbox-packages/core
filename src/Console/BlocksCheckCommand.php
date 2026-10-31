<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment as Twig;
use Twig\Error\SyntaxError;

/**
 * Checks all blocks and layouts: schemas, overrides and Twig syntax.
 * Ignores the cache, so it also suits a deployment pipeline.
 */
#[AsCommand(name: 'blocks:check', description: 'Checks schemas, overrides and Twig syntax of all blocks and layouts')]
final class BlocksCheckCommand extends Command
{
    public function __construct(
        private readonly ComponentLoader $loader,
        private readonly Twig $twig,
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

        $errors = [];
        $templates = 0;

        foreach ([...$components->blocks, ...$components->layouts] as $component) {
            foreach ($component->variants as $variant) {
                ++$templates;

                try {
                    $this->twig->parse($this->twig->tokenize($this->twig->getLoader()->getSourceContext($variant->template)));
                } catch (SyntaxError $error) {
                    $errors[] = \sprintf('%s, variant %s: %s', $component->name, $variant->name, $error->getMessage());
                }
            }
        }

        if ([] !== $errors) {
            $io->error(\sprintf('%d problem(s) found', \count($errors)));
            $io->listing($errors);

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d blocks and %d layouts with %d templates are fine.', \count($components->blocks), \count($components->layouts), $templates));

        return Command::SUCCESS;
    }
}
