<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\View;

use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\TemplateFunction;
use Frootbox\Core\View\ViewExtension;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(TemplateFunction::class)]
#[CoversClass(ViewExtension::class)]
final class TemplateFunctionTest extends NotesTestCase
{
    public function testAnExtensionAddsAFunctionForTemplates(): void
    {
        $twig = new Environment(new ArrayLoader(['page' => '{{ acme_greeting("Bohne") }}|{{ acme_badge() }}|{{ url("https://example.org") }}']), ['autoescape' => 'html']);
        $twig->addExtension($this->get(ViewExtension::class));

        self::assertSame('Hallo &lt;Bohne&gt;|<b>neu</b>|https://example.org', $twig->render('page'));

        // Never in place of a function of the core.
        $names = array_map(static fn($function): string => $function->getName(), $this->get(ViewExtension::class)->getFunctions());
        self::assertSame(array_unique($names), $names);
    }

    public function testANameNeedsAPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateFunction('cart', Greeting::class);
    }

    protected function register(Registry $registry): void
    {
        $registry->add(TemplateFunction::class, new TemplateFunction('acme_greeting', Greeting::class, 'hello'));
        $registry->add(TemplateFunction::class, new TemplateFunction('acme_badge', Greeting::class, 'badge', html: true));
        $registry->add(TemplateFunction::class, new TemplateFunction('site_field', Greeting::class));
    }
}

final readonly class Greeting
{
    public function hello(string $name): string
    {
        return 'Hallo <' . $name . '>';
    }

    public function badge(): string
    {
        return '<b>neu</b>';
    }

    public function __invoke(): string
    {
        return '';
    }
}
