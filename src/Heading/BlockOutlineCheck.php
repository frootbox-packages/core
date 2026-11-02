<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\OutputsHeadings;
use Frootbox\Core\View\ComponentRenderer;
use Throwable;

/**
 * Checks that the headings of a block or layout stay right wherever it
 * stands (decision 0129): no fixed <h1> to <h6> in its templates, and
 * with sample text a valid outline at three places, on top of a page,
 * further down and in a section with a heading.
 */
final readonly class BlockOutlineCheck
{
    /** A fixed heading in a template. */
    private const string FIXED = '~<\s*(h[1-6])[\s>]~i';

    /** The places: the levels before the block and the level it starts at. */
    private const array PLACES = [
        'on top of a page' => [[], 1],
        'further down' => [[1, 2], 2],
        'in a section with a heading' => [[1, 2], 3],
    ];

    public function __construct(
        private ComponentRenderer $renderer,
        private FieldTypes $types,
    ) {}

    /**
     * @return list<string> Hints, empty if all is fine
     */
    public function check(Component $component): array
    {
        $hints = [];

        foreach ($component->variants as $variant) {
            $source = @file_get_contents($variant->template);

            if (\is_string($source) && 1 === preg_match(self::FIXED, (string) preg_replace('/\{#.*?#\}/s', '', $source), $match)) {
                $hints[] = \sprintf('%s, variant %s: writes a fixed <%s>. Use heading() so the level fits where it stands, e.g. {{ heading(\'title\') }}.', $component->name, $variant->name, strtolower($match[1]));
            }
        }

        if (ComponentKind::Block !== $component->kind || [] !== $hints) {
            return $hints;
        }

        foreach ($component->variants as $variant) {
            foreach (self::PLACES as $place => [$before, $start]) {
                $outline = new Outline();

                foreach ($before as $level) {
                    $outline->take($level, 'Before');
                }

                try {
                    $html = $this->renderer->block(new Instance($component->name, $variant->name, [], $this->samples($component->fields)), headings: new Headings($outline, $start, 'check'));
                } catch (Throwable $error) {
                    return [\sprintf('%s: could not be rendered with sample text to check its headings: %s', $component->name, $error->getMessage())];
                }

                $problem = self::problem($html, $before);

                if (null !== $problem) {
                    $hints[] = \sprintf('%s, variant %s, %s: %s', $component->name, $variant->name, $place, $problem);
                }
            }
        }

        return $hints;
    }

    /**
     * @param list<int> $before
     */
    private static function problem(string $html, array $before): ?string
    {
        preg_match_all('~<h([1-6])[\s>]~i', $html, $matches);
        $last = [] === $before ? 0 : $before[array_key_last($before)];
        $main = \in_array(1, $before, true);

        foreach ($matches[1] as $level) {
            $level = (int) $level;

            if ($level > $last + 1) {
                return \sprintf('h%d follows h%d and skips a level.', $level, $last);
            }

            if (1 === $level && $main) {
                return 'a second h1.';
            }

            $last = $level;
            $main = $main || 1 === $level;
        }

        return null;
    }

    /**
     * Sample text for the fields that can hold headings, two entries per list.
     *
     * @param array<string, Field> $fields
     *
     * @return array<string, mixed>
     */
    private function samples(array $fields): array
    {
        $values = [];

        foreach ($fields as $name => $field) {
            $type = $this->types->has($field->type) ? $this->types->get($field->type) : null;

            if ([] !== $field->fields) {
                $values[$name] = [$this->samples($field->fields), $this->samples($field->fields)];
            } elseif (\in_array($field->type, ['headline', 'text'], true)) {
                $values[$name] = '' === $field->placeholder ? $field->label : $field->placeholder;
            } elseif ($type instanceof OutputsHeadings) {
                $content = [];

                for ($rank = 1; $rank <= $type->headingRanks($field); ++$rank) {
                    $content[] = ['type' => 'heading', 'attrs' => ['level' => $rank], 'content' => [['type' => 'text', 'text' => $field->label]]];
                }

                $values[$name] = ['type' => 'doc', 'content' => [...$content, ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $field->label]]]]];
            }
        }

        return $values;
    }
}
