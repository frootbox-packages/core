<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use DOMDocument;
use DOMXPath;
use Frootbox\Core\Heading\BlockOutlineCheck;
use Frootbox\Core\ObjectField\ObjectSchema;
use Twig\Environment as Twig;
use Twig\Error\SyntaxError;

/**
 * The checks of blocks and layouts for blocks:check and extension:check
 * (decisions 0148, 0129, 0278): Twig syntax and further fields of objects
 * are errors; group, previews and headings are hints.
 */
final readonly class ComponentCheck
{
    public function __construct(
        private Twig $twig,
        private BlockGroups $groups,
        private BlockOutlineCheck $headings,
        private ObjectSchema $objects,
    ) {}

    /**
     * Templates that do not parse, by component and variant.
     *
     * @param iterable<Component> $components
     *
     * @return list<string>
     */
    public function syntax(iterable $components): array
    {
        $errors = [];

        foreach ($components as $component) {
            foreach ($component->variants as $variant) {
                try {
                    $this->twig->parse($this->twig->tokenize($this->twig->getLoader()->getSourceContext($variant->template)));
                } catch (SyntaxError $error) {
                    $errors[] = \sprintf('%s, variant %s: %s', $component->name, $variant->name, $error->getMessage());
                }
            }
        }

        return $errors;
    }

    /**
     * Further fields of objects: one type per name (decision 0240).
     *
     * @return list<string>
     */
    public function objects(): array
    {
        return $this->objects->problems();
    }

    /**
     * Group, previews and headings of a block or layout.
     *
     * @return list<string>
     */
    public function warnings(Component $component): array
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
