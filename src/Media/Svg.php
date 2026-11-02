<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMText;

/**
 * SVG graphics without anything that runs (decision 0182). clean() keeps
 * only elements and attributes on a fixed list: shapes, text, gradients,
 * masks, filters and <style>, as drawing programs write them. Out go
 * scripts, foreignObject, animations, event handlers, links to other
 * documents, DOCTYPE with entities and in CSS @import and url() to
 * anything but the same document or an embedded raster image.
 *
 * inline() prepares a clean SVG for the page itself: hidden from screen
 * readers, currentColor where the drawing leaves fill open, and IDs,
 * classes and CSS rules bound to this one graphic, so two on a page do
 * not touch each other or the page.
 */
final class Svg
{
    public const string TYPE = 'image/svg+xml';

    public const string NAMESPACE = 'http://www.w3.org/2000/svg';

    private const string XLINK = 'http://www.w3.org/1999/xlink';

    private const string XML = 'http://www.w3.org/XML/1998/namespace';

    /** Larger files are no graphics but data. */
    private const int MAX_BYTES = 5 * 1024 * 1024;

    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath', 'image',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix',
        'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood',
        'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge',
        'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting',
        'feSpotLight', 'feTile', 'feTurbulence',
    ];

    private const array ATTRIBUTES = [
        'id', 'class', 'style', 'lang', 'transform', 'viewBox', 'preserveAspectRatio', 'version',
        'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
        'd', 'points', 'pathLength', 'href',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'color',
        'display', 'visibility', 'overflow', 'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits',
        'maskContentUnits', 'filter', 'filterUnits', 'primitiveUnits', 'offset', 'stop-color', 'stop-opacity',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'patternUnits', 'patternContentUnits',
        'patternTransform', 'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient',
        'marker-start', 'marker-mid', 'marker-end',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-stretch', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing',
        'text-decoration', 'writing-mode', 'direction', 'unicode-bidi', 'dx', 'dy', 'rotate', 'textLength',
        'lengthAdjust', 'startOffset', 'method', 'spacing', 'side',
        'in', 'in2', 'result', 'stdDeviation', 'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'values', 'type',
        'tableValues', 'slope', 'intercept', 'amplitude', 'exponent', 'kernelMatrix', 'order', 'divisor',
        'bias', 'targetX', 'targetY', 'edgeMode', 'preserveAlpha', 'surfaceScale', 'diffuseConstant',
        'specularConstant', 'specularExponent', 'lighting-color', 'flood-color', 'flood-opacity', 'azimuth',
        'elevation', 'pointsAtX', 'pointsAtY', 'pointsAtZ', 'limitingConeAngle', 'scale', 'xChannelSelector',
        'yChannelSelector', 'radius', 'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',
        'color-interpolation', 'color-interpolation-filters', 'shape-rendering', 'text-rendering',
        'image-rendering', 'mix-blend-mode', 'isolation', 'paint-order', 'vector-effect',
        'role', 'aria-label', 'aria-hidden', 'focusable',
    ];

    /** An embedded raster image is the only data: URL that stays. */
    private const string RASTER = '/^data:image\/(?:png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]+$/i';

    /**
     * Whether a file looks like an SVG: its first element is <svg>.
     */
    public static function isSvg(string $path): bool
    {
        $head = @file_get_contents($path, length: 4096);

        return \is_string($head) && 1 === preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*\?>\s*|<!--.*?-->\s*|<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>\s*)*<svg[\s>]/is', $head);
    }

    /**
     * The graphic without anything that runs, or null if it is no SVG or
     * nothing to see is left.
     */
    public static function clean(string $source): ?string
    {
        if (\strlen($source) > self::MAX_BYTES) {
            return null;
        }

        // Entities could expand without end or reach outside; graphics need none.
        if (1 === preg_match('/<!ENTITY/i', $source)) {
            return null;
        }

        $document = self::parse($source);
        $root = $document?->documentElement;

        if (null === $document || null === $root || 'svg' !== $root->localName) {
            return null;
        }

        // Without the namespace a browser shows the file as XML, not as a graphic.
        if (self::NAMESPACE !== $root->namespaceURI) {
            if (null !== $root->namespaceURI) {
                return null;
            }

            $document = self::parse((string) preg_replace('/<svg(?=[\s>])/i', '<svg xmlns="' . self::NAMESPACE . '"', $source, 1));
            $root = $document?->documentElement;

            if (null === $document || null === $root || self::NAMESPACE !== $root->namespaceURI) {
                return null;
            }
        }

        foreach (iterator_to_array($document->childNodes, false) as $node) {
            if ($node !== $root) {
                $document->removeChild($node);
            }
        }

        self::cleanElement($root);

        if (!self::hasContent($root)) {
            return null;
        }

        return (string) $document->saveXML($root);
    }

    /**
     * Width and height in pixels: from width and height, else from the
     * viewBox, else the 300 × 150 of a browser.
     *
     * @return array{int, int}
     */
    public static function size(string $svg): array
    {
        $root = self::parse($svg)?->documentElement;

        if (null === $root) {
            return [300, 150];
        }

        $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        $box = \is_array($box) && 4 === \count($box) && is_numeric($box[2]) && is_numeric($box[3]) && (float) $box[2] > 0 && (float) $box[3] > 0
            ? [(float) $box[2], (float) $box[3]]
            : null;
        $width = self::pixels($root->getAttribute('width'));
        $height = self::pixels($root->getAttribute('height'));

        if (null !== $width && null !== $height) {
            return [max(1, (int) round($width)), max(1, (int) round($height))];
        }

        if (null !== $box) {
            $scale = null !== $width ? $width / $box[0] : (null !== $height ? $height / $box[1] : 1.0);

            return [max(1, (int) round($box[0] * $scale)), max(1, (int) round($box[1] * $scale))];
        }

        return [300, 150];
    }

    /**
     * A clean SVG for the page itself.
     *
     * @param string $prefix Unique on the page, e.g. "fbi-3f2a"
     */
    public static function inline(string $svg, string $prefix): string
    {
        $document = self::parse($svg);
        $root = $document?->documentElement;

        if (null === $document || null === $root) {
            return '';
        }

        foreach (['role', 'aria-label', 'aria-hidden', 'focusable'] as $name) {
            $root->removeAttribute($name);
        }

        if (!$root->hasAttribute('fill') && 1 !== preg_match('/(?:^|;)\s*fill\s*:/', $root->getAttribute('style'))) {
            $root->setAttribute('fill', 'currentColor');
        }

        $ids = [];
        $classes = [];

        foreach (self::elements($root) as $element) {
            if ('' !== $element->getAttribute('id')) {
                $ids[$element->getAttribute('id')] = true;
            }

            foreach (self::classes($element->getAttribute('class')) as $class) {
                $classes[$class] = true;
            }
        }

        $styles = [];

        foreach (self::elements($root) as $element) {
            if ('style' === $element->localName) {
                $styles[] = $element;

                continue;
            }

            foreach (iterator_to_array($element->attributes ?? [], false) as $attribute) {
                $attribute->value = self::rename($attribute->name, $attribute->value, $ids, $classes, $prefix);
            }
        }

        $root->setAttribute('data-fb-svg', $prefix);

        foreach ($styles as $style) {
            $css = self::scope(self::renameCss($style->textContent, $ids, $classes, $prefix), '[data-fb-svg="' . $prefix . '"]');
            $style->textContent = $css;
        }

        $markup = (string) $document->saveXML($root);

        return '<svg aria-hidden="true" focusable="false"' . substr($markup, 4);
    }

    private static function parse(string $source): ?DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($source, \LIBXML_NONET | \LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }

    private static function cleanElement(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? [], false) as $attribute) {
            if (!self::keepAttribute($element, $attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes, false) as $child) {
            if ($child instanceof DOMElement) {
                if (self::NAMESPACE === $child->namespaceURI && \in_array($child->localName, self::ELEMENTS, true)) {
                    self::cleanElement($child);
                } else {
                    $element->removeChild($child);
                }
            } elseif ($child instanceof DOMText) {
                if ('style' === $element->localName) {
                    $child->data = self::cleanCss($child->data);
                }
            } else {
                // Comments, processing instructions, entity references.
                $element->removeChild($child);
            }
        }
    }

    private static function keepAttribute(DOMElement $element, DOMAttr $attribute): bool
    {
        $namespace = $attribute->namespaceURI;

        if (self::XML === $namespace) {
            return 'space' === $attribute->localName || 'lang' === $attribute->localName;
        }

        $name = self::XLINK === $namespace && 'href' === $attribute->localName ? 'href' : (null === $namespace ? $attribute->name : null);

        if (null === $name || !\in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }

        $value = $attribute->value;

        if ('href' === $name) {
            return 1 === preg_match('/^#[^\s]+$/', trim($value)) || ('image' === $element->localName && 1 === preg_match(self::RASTER, trim($value)));
        }

        if ('style' === $name) {
            $attribute->value = self::cleanCss($value);

            return '' !== trim($attribute->value);
        }

        return self::safeValue($value);
    }

    /**
     * No url() to elsewhere, no script.
     */
    private static function safeValue(string $value): bool
    {
        if (1 === preg_match('/javascript:|vbscript:|expression\s*\(|-moz-binding|behavior\s*:/i', $value)) {
            return false;
        }

        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', $value, $urls) > 0) {
            foreach ($urls[2] as $url) {
                if (!str_starts_with(trim($url), '#') && 1 !== preg_match(self::RASTER, trim($url))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Declarations and rules that could reach outside go.
     */
    private static function cleanCss(string $css): string
    {
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $css = (string) preg_replace('/@(?:import|charset|namespace)[^;]*;?/i', '', $css);
        // Fonts from elsewhere: the whole rule goes.
        $css = (string) preg_replace('/@font-face\s*\{[^}]*\}/i', '', $css);
        $css = (string) preg_replace('/<\/?[a-z]/i', '', $css);

        return (string) preg_replace_callback(
            '/([^;{}]+)(;|(?=\})|$)/',
            static fn(array $match): string => self::safeValue($match[1]) ? $match[0] : '',
            $css,
        );
    }

    private static function hasContent(DOMElement $root): bool
    {
        foreach (self::elements($root) as $element) {
            if (!\in_array($element->localName, ['svg', 'g', 'defs', 'title', 'desc', 'style'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DOMElement> The element and all below it
     */
    private static function elements(DOMElement $root): array
    {
        $found = [$root];

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                array_push($found, ...self::elements($child));
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function classes(string $value): array
    {
        $found = preg_split('/\s+/', trim($value));

        return false === $found ? [] : array_values(array_filter($found, static fn(string $class): bool => '' !== $class));
    }

    private static function pixels(string $length): ?float
    {
        if (1 !== preg_match('/^\s*(\d+(?:\.\d+)?|\.\d+)\s*(px|pt|pc|mm|cm|in)?\s*$/', $length, $match) || (float) $match[1] <= 0) {
            return null;
        }

        return (float) $match[1] * match ($match[2] ?? '') {
            'pt' => 4 / 3,
            'pc' => 16.0,
            'mm' => 96 / 25.4,
            'cm' => 96 / 2.54,
            'in' => 96.0,
            default => 1.0,
        };
    }

    /**
     * @param array<string, true> $ids
     * @param array<string, true> $classes
     */
    private static function rename(string $name, string $value, array $ids, array $classes, string $prefix): string
    {
        return match ($name) {
            'id' => isset($ids[$value]) ? $prefix . '-' . $value : $value,
            'class' => implode(' ', array_map(static fn(string $class): string => $prefix . '-' . $class, self::classes($value))),
            'href', 'xlink:href' => str_starts_with($value, '#') && isset($ids[substr($value, 1)]) ? '#' . $prefix . '-' . substr($value, 1) : $value,
            'style' => self::renameCss($value, $ids, [], $prefix),
            default => self::renameUrls($value, $ids, $prefix),
        };
    }

    /**
     * @param array<string, true> $ids
     */
    private static function renameUrls(string $value, array $ids, string $prefix): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)#([^\'")\s]+)\1\s*\)/',
            static fn(array $match): string => isset($ids[$match[2]]) ? 'url(#' . $prefix . '-' . $match[2] . ')' : $match[0],
            $value,
        );
    }

    /**
     * @param array<string, true> $ids
     * @param array<string, true> $classes
     */
    private static function renameCss(string $css, array $ids, array $classes, string $prefix): string
    {
        $css = self::renameUrls($css, $ids, $prefix);

        foreach (array_keys($ids) as $id) {
            $css = (string) preg_replace('/#' . preg_quote($id, '/') . '(?![\w-])/', '#' . $prefix . '-' . $id, $css);
        }

        foreach (array_keys($classes) as $class) {
            $css = (string) preg_replace('/\.' . preg_quote($class, '/') . '(?![\w-])/', '.' . $prefix . '-' . $class, $css);
        }

        return $css;
    }

    /**
     * Every rule only within the graphic; at-rules like @media go.
     */
    private static function scope(string $css, string $scope): string
    {
        $scoped = '';
        $length = \strlen($css);
        $at = 0;

        while ($at < $length) {
            $open = strpos($css, '{', $at);

            if (false === $open) {
                break;
            }

            $selectors = trim(substr($css, $at, $open - $at));
            $depth = 1;
            $end = $open + 1;

            while ($end < $length && $depth > 0) {
                $depth += match ($css[$end]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                ++$end;
            }

            if ('' !== $selectors && !str_starts_with($selectors, '@')) {
                $list = array_map(static fn(string $selector): string => $scope . ' ' . trim($selector), explode(',', $selectors));
                $scoped .= implode(', ', $list) . '{' . substr($css, $open + 1, $end - $open - 2) . '}';
            }

            $at = $end;
        }

        return $scoped;
    }
}
