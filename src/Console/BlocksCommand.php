<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Theme\ThemeChain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the available blocks and layouts with their variants.
 */
#[AsCommand(name: 'blocks', description: 'Lists the available blocks and layouts with their variants')]
final class BlocksCommand extends Command
{
    public function __construct(
        private readonly Components $components,
        private readonly ThemeChain $themes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chain = array_map(static fn($theme): string => $theme->name, $this->themes->themes);
        $io->text('Theme: ' . ([] === $chain ? 'none (set "theme:" in config/app.yaml)' : implode(' → ', $chain)));

        foreach (['Blocks' => $this->components->blocks, 'Layouts' => $this->components->layouts] as $heading => $components) {
            $io->section($heading);

            if ([] === $components) {
                $io->text('none');

                continue;
            }

            $io->table(['Name', 'Title', 'Variants'], array_map(static fn(Component $component): array => [
                $component->name,
                $component->title,
                implode(', ', array_map(
                    static fn($variant): string => $variant->from === $component->package() ? $variant->name : $variant->name . ' (' . $variant->from . ')',
                    array_values($component->variants),
                )),
            ], array_values($components)));
        }

        return Command::SUCCESS;
    }
}
