<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Language\Languages;
use Frootbox\Core\Navigation\MenuItem;
use Frootbox\Core\Navigation\Menus;
use Frootbox\Core\Site\SiteFields;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Psr\Container\ContainerInterface;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Functions for block and layout templates:
 *
 *     {{ field('title') }}            the field, ready for output
 *     {{ value('button').url }}       the stored value
 *     {{ area('columns') }}           the blocks placed in an area
 *     {{ url('page:01J…') }}          the URL of a target in the visitor's language
 *     {{ url(value('button')) }}      the URL of a link field
 *     {{ language() }}                the language of the visitor, e.g. "en"
 *     {{ content_language() }}        the language of the content, e.g. "de" on a fallback
 *     {{ site_field('address') }}     a field of the website, the same on every page
 *     {{ site_value('brand') }}       its stored value
 *     {% for item in alternates() %}  the page in all languages, for a language switcher
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
            new TwigFunction('field', fn(array $context, string $name): mixed => self::frame($context, 'field')->scope->field($name), ['needs_context' => true]),
            new TwigFunction('value', fn(array $context, string $name): mixed => self::frame($context, 'value')->scope->value($name), ['needs_context' => true]),
            new TwigFunction('area', fn(array $context, string $name): Markup => new Markup($this->renderer->area(self::frame($context, 'area'), $name), 'UTF-8'), ['needs_context' => true]),
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('language', fn(): string => $this->service(Delivery::class)->language()->code),
            new TwigFunction('content_language', fn(): string => $this->service(Delivery::class)->contentLanguage()->code),
            new TwigFunction('alternates', $this->alternates(...)),
            new TwigFunction('navigation', $this->navigation(...)),
            new TwigFunction('site_field', fn(string $name): mixed => $this->site()->field($name)),
            new TwigFunction('site_value', fn(string $name): mixed => $this->site()->value($name)),
            new TwigFunction('breadcrumb', fn(): array => $this->service(Menus::class)->breadcrumb()),
        ];
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
     * @return list<array{code: string, title: string, url: string, current: bool, fallback: bool}>
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
    private function site(): Scope
    {
        return $this->service(SiteFields::class)->scope($this->service(Delivery::class)->language()->code);
    }
}
