<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Console;

use DOMDocument;
use DOMXPath;
use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Component\Previews;
use Frootbox\Core\Heading\BlockOutlineCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment as Twig;
use Twig\Error\SyntaxError;

/**
 * Checks all blocks and layouts: schemas, overrides and Twig syntax.
 * Ignores the cache, so it also suits a deployment pipeline. Missing
 * groups and previews are warnings (decision 0148), so are fixed
 * headings and an outline that breaks at one of three places (decision
 * 0129).
 */
#[AsCommand(name: 'blocks:check', description: 'Checks schemas, overrides and Twig syntax of all blocks and layouts')]
final class BlocksCheckCommand extends Command
{
    public function __construct(
        private readonly ComponentLoader $loader,
        private readonly Twig $twig,
        private readonly BlockGroups $groups,
        private readonly BlockOutlineCheck $headings,
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

        $warnings = [];

        foreach ([...$components->blocks, ...$components->layouts] as $component) {
            array_push($warnings, ...$this->warnings($component));
        }

        if ([] !== $warnings) {
            $io->warning(\sprintf('%d hint(s)', \count($warnings)));
            $io->listing($warnings);
        }

        $io->success(\sprintf('%d blocks and %d layouts with %d templates are fine.', \count($components->blocks), \count($components->layouts), $templates));

        return Command::SUCCESS;
    }

    /**
     * Group, previews and headings of a block or layout.
     *
     * @return list<string>
     */
    private function warnings(Component $component): array
    {
        $warnings = [];

        if (ComponentKind::Block === $component->kind) {
            if (null === $component->group) {
                $warnings[] = \sprintf('%s has no group, so it stands under "Other". Add e.g. "group: teaser".', $component->name);
            } elseif (!$this->groups->has($component->group)) {
                $warnings[] = \sprintf('%s names the group "%s", which is not registered. Known: %s', $component->name, $component->group, implode(', ', array_map(static fn($group): string => $group->name, $this->groups->all())));
            }
        }

        if ([] === $component->previews) {
            $warnings[] = \sprintf('%s has no preview. Add preview.svg or preview.webp to its folder.', $component->name);
        }

        foreach ($component->previews as $variant => $file) {
            $where = \sprintf('%s: %s', $component->name, basename($file));

            if (!isset($component->variants[$variant])) {
                $warnings[] = \sprintf('%s: there is no variant %s', $where, $variant);

                continue;
            }

            $problem = 'svg' === pathinfo($file, \PATHINFO_EXTENSION) ? self::svgProblem($file) : self::imageProblem($file);

            if (null !== $problem) {
                $warnings[] = $where . ': ' . $problem;
            }
        }

        return [...$warnings, ...$this->headings->check($component)];
    }

    private static function svgProblem(string $file): ?string
    {
        $document = new DOMDocument();

        if (!@$document->load($file, \LIBXML_NONET) || null === $document->documentElement || 'svg' !== $document->documentElement->localName) {
            return 'is no valid SVG';
        }

        $root = $document->documentElement;
        $parts = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        $box = array_map(floatval(...), false === $parts ? [] : $parts);

        if (4 !== \count($box) || $box[3] <= 0 || abs($box[2] / $box[3] - Previews::RATIO) > 0.01) {
            return 'needs a viewBox in 16:10, e.g. "0 0 320 200"';
        }

        $marked = new DOMXPath($document)->query('//*[@id="preview"]');

        if (false === $marked || 0 === $marked->length) {
            return 'needs id="preview" on its root, so the picker can show it in the colours of the theme';
        }

        return null;
    }

    private static function imageProblem(string $file): ?string
    {
        $size = @getimagesize($file);

        if (false === $size || $size[1] <= 0) {
            return 'is no readable image';
        }

        if (abs($size[0] / $size[1] - Previews::RATIO) > 0.02) {
            return \sprintf('is %dx%d; previews are 16:10, e.g. 800x500', $size[0], $size[1]);
        }

        return null;
    }
}
