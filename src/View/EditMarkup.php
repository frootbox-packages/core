<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Field\Field;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * What the editing mode adds to fields, blocks and areas (decision 0119),
 * only for users who may change the page. Visitors never get it.
 *
 *     <fb-field data-fb-field="slides/0/title" data-fb-type="headline" data-fb-label="Title" data-fb-placeholder="Fresh every week" tabindex="0">…</fb-field>
 *     <fb-field data-fb-field="address" data-fb-type="text" data-fb-label="Address" data-fb-optional data-fb-site tabindex="0">…</fb-field>
 *     <section data-fb-block="01J…" data-fb-name="acme/website/Teaser" data-fb-title="Teaser" data-fb-area="main">…</section>
 *     <fb-area data-fb-area="main" data-fb-empty></fb-area>
 *
 * In a preview only light marks to comment on (decision 0156): blocks as
 * above, fields with path and label on the element they are alone in,
 * no <fb-field> left, nothing for empty fields and areas.
 */
final readonly class EditMarkup
{
    /** Up to the first tag of a rendered block, after comments. */
    private const string ROOT = '/^\s*(?:<!--.*?-->\s*)*</s';

    /** A field that was not moved onto its element. */
    private const string WRAPPER = '~<fb-field\s[^>]*>((?:(?!</?fb-field[\s>]).)*)</fb-field>~s';

    /**
     * @param bool $site    For the fields of the website rather than of the page (decision 0128)
     * @param bool $anchors Only light marks for comments in a preview (decision 0156)
     */
    public function __construct(
        private bool $site = false,
        public bool $anchors = false,
    ) {}

    /**
     * The markup for fields of the website, saved with site.update-field.
     */
    public function forSite(): self
    {
        return new self(true, $this->anchors);
    }

    /**
     * Only light marks for comments in a preview (decision 0156).
     */
    public function forPreview(): self
    {
        return new self($this->site, true);
    }

    /**
     * Wraps the output of a field that is text or markup. The stored value
     * goes along as JSON for the editors (decision 0122).
     *
     * @param string $path Inside the block or layout, e.g. "title" or "slides/0/title"
     */
    public function field(string $path, Field $field, string|Markup $output, mixed $value = null): Markup
    {
        if ($this->anchors) {
            return new Markup(\sprintf(
                '<fb-field data-fb-field="%s" data-fb-label="%s"%s>%s</fb-field>',
                Html::escape($path),
                Html::escape($field->label),
                $this->site ? ' data-fb-site' : '',
                $output instanceof Markup ? (string) $output : Html::escape($output),
            ), 'UTF-8');
        }

        return new Markup(\sprintf(
            '<fb-field data-fb-field="%s" data-fb-type="%s" data-fb-label="%s"%s%s%s tabindex="0"%s>%s</fb-field>',
            Html::escape($path),
            Html::escape($field->type),
            Html::escape($field->label),
            // The bar offers to remove only what may stay empty (decision 0150).
            $field->optional ? ' data-fb-optional' : '',
            '' === $field->placeholder ? '' : ' data-fb-placeholder="' . Html::escape($field->placeholder) . '"',
            $this->site ? ' data-fb-site' : '',
            null === $value ? '' : ' data-fb-value="' . Html::escape(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)) . '"',
            $output instanceof Markup ? (string) $output : Html::escape($output),
        ), 'UTF-8');
    }

    /**
     * Moves the marks of a field that is alone in its element onto that
     * element, so the page keeps the structure visitors get and rules of
     * the theme such as ".text__body > * + *" still apply (decision 0121).
     * The outer element of a block keeps the wrapper when $keepRoot is
     * set, because it gets the marks of the block. Elements that carry
     * marks or a tabindex already keep it as well.
     */
    public function unwrap(string $html, bool $keepRoot = false): string
    {
        $root = $keepRoot && 1 === preg_match(self::ROOT, $html, $match) ? \strlen($match[0]) - 1 : -1;

        // On a failure of the pattern, e.g. a very long text, the page stays as it is.
        return preg_replace_callback(
            '~<([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^>]*)?)>(\s*)<fb-field ([^>]*)>((?:(?!</?fb-field[\s>]).)*)</fb-field>(\s*)</\1>~s',
            static function (array $match) use ($root): string {
                [$whole, $offset] = $match[0];
                $attributes = $match[2][0];

                if ($offset === $root || str_contains($attributes, 'data-fb-') || str_contains($attributes, 'tabindex')) {
                    return $whole;
                }

                return \sprintf('<%1$s%2$s %3$s>%4$s%5$s%6$s</%1$s>', $match[1][0], rtrim($attributes), $match[4][0], $match[3][0], $match[5][0], $match[6][0]);
            },
            $html,
            flags: \PREG_OFFSET_CAPTURE,
        ) ?? $html;
    }

    /**
     * Removes the fields that could not be moved onto their element, so a
     * preview keeps the structure visitors get (decision 0156).
     */
    public function strip(string $html): string
    {
        do {
            $before = $html;
            $html = preg_replace(self::WRAPPER, '$1', $html) ?? $before;
        } while ($html !== $before);

        return $html;
    }

    /**
     * Adds the block and its area to the outer element of a rendered block.
     */
    public function block(string $html, Instance $block, Component $component, string $area): string
    {
        $attributes = \sprintf(
            '%s data-fb-name="%s" data-fb-title="%s" data-fb-area="%s"',
            null === $block->id ? '' : ' data-fb-block="' . Html::escape($block->id) . '"',
            Html::escape($component->name),
            Html::escape($component->title),
            Html::escape($area),
        );

        return (string) preg_replace('/^(\s*(?:<!--.*?-->\s*)*<[a-zA-Z][a-zA-Z0-9-]*)(?=[\s>\/])/s', '$1' . $attributes, $html, 1);
    }

    /**
     * Holds the place of an area without blocks.
     */
    public function emptyArea(string $area): string
    {
        return \sprintf('<fb-area data-fb-area="%s" data-fb-empty></fb-area>', Html::escape($area));
    }
}
