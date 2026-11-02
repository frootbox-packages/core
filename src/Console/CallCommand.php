<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Normalizer;
use Frootbox\Core\Action\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs an action or query by name, e.g.
 *
 *     php bin/frootbox call action.undo '{"id": 12}'
 */
#[AsCommand(name: 'call', description: 'Runs an action or query by name with JSON input')]
final class CallCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name of the action or query, e.g. page.create');
        $this->addArgument('input', InputArgument::OPTIONAL, 'Input as a JSON object', '{}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $argument = $input->getArgument('input');
        $json = \is_string($argument) ? self::object($argument) : null;

        if (!\is_string($name) || null === $json) {
            $io->error('The input must be a JSON object, e.g. {"title": "Hello"}.');

            return Command::INVALID;
        }

        try {
            $result = $this->runner->call($name, $json);
        } catch (InvalidInputException $error) {
            $io->error('Invalid input for ' . $error->name);
            $io->listing(array_map(static fn(string $pointer, string $message): string => ('' === $pointer ? '/' : $pointer) . ': ' . $message, array_keys($error->errors), $error->errors));

            return Command::INVALID;
        } catch (ActionException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(json_encode(Normalizer::normalize($result), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null null if it is no JSON object
     */
    private static function object(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }

            $object[$key] = $value;
        }

        return $object;
    }
}
