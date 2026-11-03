<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Consent\Embeds;
use Frootbox\Core\Consent\ExternalMarkup;
use Frootbox\Core\Consent\ExternalServices;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Site\SiteFields;
use Frootbox\Core\Tag\Taxonomies;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Url\BasePages;
use Frootbox\Core\Url\Subpage;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\RichText\Html;
use Psr\Container\ContainerInterface;
use Stringable;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Functions for block and layout templates:
 *
 *     {{ field('title') }}            the field, ready for output
 *     {{ field('image', 'caption') }} one part of a field, e.g. caption or copyright of an image
 *     {{ heading('title') }}          the field as a heading at the level of its place
 *     {{ item.heading('question', 1) }} a heading one rank below the main one
 *     {{ value('button').url }}       the stored value
 *     {{ area('columns') }}           the blocks placed in an area
 *     {{ render_block('frootbox/forms/Form', {form: id}) }} another block inside this one, with its options (decision 0230)
 *     {{ url('page:01J…') }}          the URL of a target in the visitor's language
 *     {{ url(value('button')) }}      the URL of a link field
 *     {{ language() }}                the language of the visitor, e.g. "en"
 *     {{ content_language() }}        the language of the content, e.g. "de" on a fallback
 *     {{ direction() }}               the writing direction of the visitor's language, "ltr" or "rtl"
 *     {{ content_direction() }}       the one of the content
 *     <p{{ content_attributes()|attributes }}> lang and dir of the content where they differ from the page
 *     {{ site_field('address') }}     a field of the website, the same on every page
 *     <p{{ site_attributes('address')|attributes }}> lang and dir of a field of the website from the first language
 *     {{ site_value('brand') }}       its stored value
 *     {% for item in alternates() %}  the page in all languages, for a language switcher
 *     {{ t('Skip to content') }}       a text of the theme in the language of the page
 *     <section{{ {id: jump_mark()}|attributes }}> the id of the block's jump mark, null without one
 *     {% for mark in jump_marks() %}  the jump marks of the page, each with id and label
 *     {% if editing() %}              shown for editing, e.g. a hint where visitors see nothing
 *     {{ external('acme/video/youtube', html, {link: url, title: 'Video: …', ratio: '16/9'}) }}
 *                                     content of another provider, loaded only after consent (decision 0224)
 *     {{ privacy_settings() }}        the button to change that consent, e.g. in the footer
 *     {{ video_embed(url, {title: 'Video: …'}) }} a video at YouTube or Vimeo behind consent, nothing for other addresses
 *     {{ map_embed({coordinates: '50.9, 6.9', address: '…', provider: 'openstreetmap', zoom: 16, title: 'Map: …'}) }}
 *                                     a map of OpenStreetMap or Google Maps behind consent (decision 0225)
 */
final class ViewExtension extends AbstractExtension
{
    /**
     * @param ContainerInterface $container URLs and languages are loaded on first use, so Twig works without a database
     */
    public function __construct(
        private readonly ComponentRenderer $renderer,
        private readonly ContainerInterface $container,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('field', fn(array $context, string $name, ?string $part = null): mixed => self::frame($context, 'field')->scope->field($name, $part), ['needs_context' => true]),
            new TwigFunction('heading', fn(array $context, string $name, int $rank = 0, array $attributes = []): Markup => self::frame($context, 'heading')->scope->heading($name, $rank, $attributes), ['needs_context' => true]),
            new TwigFunction('heading_text', fn(array $context, string $text, int $rank = 0, array $attributes = [], ?string $href = null): Markup => self::frame($context, 'heading_text')->scope->headingText($text, $rank, $attributes, $href), ['needs_context' => true]),
            new TwigFunction('value', fn(array $context, string $name): mixed => self::frame($context, 'value')->scope->value($name), ['needs_context' => true]),
            new TwigFunction('area', fn(array $context, string $name): Markup => new Markup($this->renderer->area(self::frame($context, 'area'), $name), 'UTF-8'), ['needs_context' => true]),
            new TwigFunction('plugin', fn(array $context): Markup => new Markup($this->renderer->plugin(self::frame($context, 'plugin')), 'UTF-8'), ['needs_context' => true]),
            new TwigFunction('render_block', fn(array $context, string $name, array $options = [], ?string $variant = null): Markup => new Markup($this->renderer->inner(self::frame($context, 'render_block'), $name, $options, $variant), 'UTF-8'), ['needs_context' => true]),
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('block_page', $this->blockPage(...)),
            // Categories and tags of a published object, e.g. tags('frootbox/news/article:' ~ id) (decisions 0233, 0239).
            new TwigFunction('categories', fn(string $target, ?string $group = null): array => $this->service(Taxonomies::class)->categories(...[...$this->target($target, 'categories'), 'languages' => $this->contentLanguages(), 'group' => $group])),
            new TwigFunction('tags', fn(string $target): array => $this->service(Taxonomies::class)->tags(...[...$this->target($target, 'tags'), 'languages' => $this->contentLanguages()])),
            new TwigFunction('has_tag', fn(string $target, string $key): bool => $this->service(Taxonomies::class)->hasTag(...[...$this->target($target, 'has_tag'), 'key' => $key])),
            new TwigFunction('language', fn(): string => $this->service(Delivery::class)->language()->code),
            new TwigFunction('content_language', fn(): string => $this->service(Delivery::class)->contentLanguage()->code),
            new TwigFunction('direction', fn(): string => $this->service(Delivery::class)->language()->direction->value),
            new TwigFunction('content_direction', fn(): string => $this->service(Delivery::class)->contentLanguage()->direction->value),
            new TwigFunction('content_attributes', $this->contentAttributes(...)),
            new TwigFunction('alternates', $this->alternates(...)),
            new TwigFunction('navigation', $this->navigation(...)),
            new TwigFunction('site_field', fn(string $name, ?string $part = null): mixed => $this->site()->field($name, $part)),
            new TwigFunction('site_attributes', $this->siteAttributes(...)),
            new TwigFunction('site_value', fn(string $name): mixed => $this->site()->value($name)),
            new TwigFunction('breadcrumb', fn(): array => $this->service(Menus::class)->breadcrumb()),
            // The object of a plugin shown below its base page, e.g. an article; layouts leave out their heading then (decision 0220).
            new TwigFunction('subpage', fn(): ?Subpage => $this->service(Delivery::class)->subpage()),
            new TwigFunction('t', $this->text(...)),
            new TwigFunction('jump_mark', fn(array $context): ?string => $this->service(JumpMarks::class)->id(self::frame($context, 'jump_mark')->instance), ['needs_context' => true]),
            new TwigFunction('jump_marks', fn(): array => $this->service(JumpMarks::class)->all()),
            new TwigFunction('editing', fn(): bool => $this->service(EditMode::class)->marking()),
            new TwigFunction('external', $this->external(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('privacy_settings', $this->privacySettings(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('video_embed', $this->videoEmbed(...), ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('map_embed', $this->mapEmbed(...), ['needs_context' => true, 'is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('attributes', self::attributes(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * lang and dir of the content where they differ from the page, e.g. on
     * a fallback to another language (decision 0133); empty otherwise.
     *
     * @return array<string, string>
     */
    public function contentAttributes(): array
    {
        $delivery = $this->service(Delivery::class);

        return self::differences($delivery->language(), $delivery->contentLanguage());
    }

    /**
     * lang and dir of a field of the website whose value comes from the
     * first language, e.g. the German address on an Arabic page (decision
     * 0170); empty if it is in the language of the page.
     *
     * @return array<string, string>
     */
    public function siteAttributes(string $name): array
    {
        $page = $this->service(Delivery::class)->language();
        $code = $this->service(SiteFields::class)->languageOf($name, $page->code);

        return null === $code ? [] : self::differences($page, $this->service(Languages::class)->get($code));
    }

    /**
     * lang and dir where the language of a text differs from the page.
     *
     * @return array<string, string>
     */
    private static function differences(Language $page, Language $text): array
    {
        $attributes = [];

        if ($text->code !== $page->code) {
            $attributes['lang'] = $text->code;
        }

        if ($text->direction !== $page->direction) {
            $attributes['dir'] = $text->direction->value;
        }

        return $attributes;
    }

    /**
     * Attributes for a tag, each with a space before it; null and false
     * are left out, true writes the bare name.
     *
     * @param array<mixed> $attributes
     */
    public static function attributes(array $attributes): string
    {
        return substr(Html::open('i', Scope::attributes($attributes, 'attributes')), 2, -1);
    }

    /**
     * @param mixed $target "page:01J…", a URL, or the value of a link field
     */
    public function url(mixed $target, ?string $language = null): ?string
    {
        if (\is_array($target)) {
            $target = $target['target'] ?? $target['url'] ?? null;
        }

        if (!\is_string($target)) {
            return null;
        }

        $parsed = Target::tryParse($target);

        return null === $parsed ? $target : $this->service(Urls::class)->href($parsed, $language);
    }

    /**
     * The address of the first published page with this block in the
     * language of the visitor, e.g. block_page('frootbox/search/Search')
     * for a link to the search in the head (decision 0228). Null if there
     * is none, also if the extension of the block is not installed.
     */
    /**
     * Type and id of a target in a template, e.g. "frootbox/news/article:01J…".
     *
     * @return array{type: string, id: string}
     */
    private function target(string $target, string $function): array
    {
        $parsed = Target::tryParse($target) ?? throw new RuntimeError(\sprintf('%s() needs a target like "frootbox/news/article:<id>", not "%s".', $function, $target));

        return ['type' => $parsed->type, 'id' => $parsed->id];
    }

    /**
     * The language of the content and its fallbacks.
     *
     * @return list<string>
     */
    private function contentLanguages(): array
    {
        return $this->service(Languages::class)->contentLanguages($this->service(Delivery::class)->contentLanguage()->code);
    }

    public function blockPage(string $block): ?string
    {
        $id = $this->service(BasePages::class)->of([$block])[$this->service(Delivery::class)->language()->code] ?? null;

        return null === $id ? null : $this->service(Urls::class)->href(Target::page($id));
    }

    /**
     * The items of a navigation of the theme in the language of the
     * visitor, e.g. navigation('main').
     *
     * @return list<MenuItem>
     */
    public function navigation(string $name): array
    {
        return $this->service(Menus::class)->navigation($name);
    }

    /**
     * The page being delivered in every language it has a URL in.
     *
     * @return list<array{code: string, title: string, direction: string, url: string, current: bool, fallback: bool}>
     */
    public function alternates(): array
    {
        $delivery = $this->service(Delivery::class);
        $target = $delivery->target();

        if (null === $target) {
            return [];
        }

        $urls = $this->service(Urls::class);
        $addresses = $urls->addresses($target);
        $alternates = [];

        foreach ($this->service(Languages::class)->all() as $language) {
            $url = isset($addresses[$language->code]) ? $urls->href($target, $language->code) : null;

            if (null !== $url) {
                $alternates[] = [
                    'code' => $language->code,
                    'title' => $language->title,
                    'direction' => $language->direction->value,
                    'url' => $url,
                    'current' => $language->code === $delivery->language()->code,
                    'fallback' => $addresses[$language->code]->fallback,
                ];
            }
        }

        return $alternates;
    }

    /**
     * @param array<mixed> $context
     */
    private static function frame(array $context, string $function): Frame
    {
        $frame = $context[Frame::VARIABLE] ?? null;

        return $frame instanceof Frame ? $frame : throw new RuntimeError(\sprintf('%s() works only in templates of blocks and layouts.', $function));
    }

    /**
     * Content of another provider behind a placeholder (decision 0224).
     *
     * @param array<string, mixed>                                            $context
     * @param array{link?: string|null, title?: string|null, ratio?: string|null} $options
     */
    private function external(array $context, string $service, string|Markup $html, array $options = []): Markup
    {
        $known = $this->service(ExternalServices::class)->get($service)
            ?? throw new RuntimeError(\sprintf('The external service "%s" is not registered. Register it with $registry->add(ExternalService::class, …).', $service));
        $this->consentAssets(self::frame($context, 'external'));

        return new Markup($this->markup()->external($known, (string) $html, $options['link'] ?? null, $options['title'] ?? null, $options['ratio'] ?? null), 'UTF-8');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function privacySettings(array $context): Markup
    {
        $services = $this->service(ExternalServices::class)->all();

        // Error pages render the footer without a frame and without assets.
        $frame = $context[Frame::VARIABLE] ?? null;

        if ([] !== $services && $frame instanceof Frame) {
            $this->consentAssets($frame);
        }

        return new Markup($this->markup()->settings($services), 'UTF-8');
    }

    /**
     * A video at YouTube or Vimeo behind a placeholder (decision 0225).
     *
     * @param array<string, mixed>     $context
     * @param array{title?: string|null} $options
     */
    private function videoEmbed(array $context, ?string $url, array $options = []): Markup|string
    {
        $embed = null === $url ? null : Embeds::video($url);

        if (null === $embed) {
            return '';
        }

        $title = $options['title'] ?? null;
        $iframe = Html::element('iframe', '', [
            'src' => $embed->src,
            'title' => $title ?? $this->text('Video', []),
            'loading' => 'lazy',
            'allow' => 'autoplay; fullscreen; picture-in-picture; encrypted-media',
            'allowfullscreen' => true,
            'referrerpolicy' => 'strict-origin-when-cross-origin',
        ]);

        return $this->external($context, $embed->service, new Markup($iframe, 'UTF-8'), ['link' => $embed->link, 'title' => $title, 'ratio' => '16 / 9']);
    }

    /**
     * A place on a map behind a placeholder (decision 0225).
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options coordinates (or latitude and longitude), address, provider, zoom, title
     */
    private function mapEmbed(array $context, array $options): Markup|string
    {
        $number = static fn(mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        // "51.9607, 7.6261" as copied from a map.
        if (\is_string($options['coordinates'] ?? null) && 1 === preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $options['coordinates'], $match)) {
            $options['latitude'] = $match[1];
            $options['longitude'] = $match[2];
        }

        $title = \is_string($options['title'] ?? null) && '' !== $options['title'] ? $options['title'] : null;
        $embed = Embeds::map(
            \is_string($options['provider'] ?? null) ? $options['provider'] : Embeds::OPENSTREETMAP,
            $number($options['latitude'] ?? null),
            $number($options['longitude'] ?? null),
            \is_string($options['address'] ?? null) ? $options['address'] : '',
            is_numeric($options['zoom'] ?? null) ? (int) $options['zoom'] : Embeds::ZOOM,
        );

        if (null === $embed) {
            return '';
        }

        $iframe = Html::element('iframe', '', [
            'src' => $embed->src,
            'title' => $title ?? $this->text('Map', []),
            'loading' => 'lazy',
            'referrerpolicy' => 'no-referrer-when-downgrade',
        ]);

        return $this->external($context, $embed->service, new Markup($iframe, 'UTF-8'), ['link' => $embed->link, 'title' => $title, 'ratio' => '4 / 3']);
    }

    private function consentAssets(Frame $frame): void
    {
        $core = \dirname(__DIR__, 2);
        $frame->assets?->add($core . '/' . ExternalMarkup::STYLE, 'base');
        $frame->assets?->add($core . '/' . ExternalMarkup::SCRIPT, 'base');
    }

    private function markup(): ExternalMarkup
    {
        return new ExternalMarkup(fn(string $text, array $parameters): string => $this->text($text, $parameters));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);

        return $service instanceof $class ? $service : throw new ViewException(\sprintf('%s is not available.', $class));
    }

    /**
     * The fields of the website in the language of the visitor (decision 0128).
     */
    /**
     * A text of the theme in the language of the page, the themes of the
     * chain first (decision 0162).
     *
     * @param string|array{string, string}                         $text
     * @param array<string, bool|float|int|string|Stringable|null> $parameters
     */
    private function text(string|array $text, array $parameters = []): string
    {
        $themes = array_map(static fn(Theme $theme): string => $theme->name, $this->service(ThemeChain::class)->themes);

        return $this->service(Translator::class)->in($this->service(Delivery::class)->language()->code, $text, $themes, $parameters);
    }

    private function site(): Scope
    {
        return $this->service(SiteFields::class)->scope($this->service(Delivery::class)->language()->code);
    }
}
