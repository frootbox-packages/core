<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Extension\ExtensionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Name and description of a command, read from #[AsCommand] without
 * creating the command.
 */
final readonly class CommandInfo
{
    private function __construct(
        public string $name,
        public string $description,
        public bool $hidden,
    ) {}

    /**
     * @param class-string<Command> $class
     */
    public static function of(string $class): self
    {
        foreach (new \ReflectionClass($class)->getAttributes(AsCommand::class) as $attribute) {
            $command = $attribute->newInstance();
            // Symfony folds aliases and "hidden" into the name: "name|alias",
            // or "|name|alias" for a hidden command. Only the name is used.
            $parts = explode('|', $command->name);
            $hidden = '' === $parts[0];
            $name = $hidden ? $parts[1] ?? '' : $parts[0];

            if ('' !== $name) {
                return new self($name, $command->description ?? '', $hidden);
            }
        }

        throw new ExtensionException(\sprintf('The command %s needs a name in #[AsCommand(name: ...)].', $class));
    }
}
