<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\View;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\FileLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;
use Twig\Error\LoaderError;

#[CoversClass(FileLoader::class)]
final class PartialsTest extends NotesTestCase
{
    public function testFindsPartialsThroughTheThemeChain(): void
    {
        $twig = $this->get(Environment::class);

        self::assertSame(
            '<b>site brand</b>|<i>Team</i>',
            $twig->createTemplate("{% include '@theme/brand.twig' %}|{% include '@theme/nav/item.twig' with { label: 'Team' } %}")->render(),
            'The child theme overrides the partial of its parent.',
        );

        $loader = $twig->getLoader();
        self::assertStringEndsWith('/site/partials/brand.twig', $loader->getCacheKey('@theme/brand.twig'));
        self::assertTrue($loader->isFresh('@theme/brand.twig', time() + 10));
        self::assertFalse($loader->exists('@theme/missing.twig'));
        self::assertFalse($loader->exists('@theme/../frootbox.yaml'));
    }

    public function testTranslatesTextsOfTheThemeIntoTheLanguageOfThePage(): void
    {
        $twig = $this->get(Environment::class);

        self::assertSame(
            'Zum Inhalt|Hauptnavigation|Hallo Lena|Unknown text',
            $twig->createTemplate("{{ t('Skip to content') }}|{{ t('Main navigation') }}|{{ t('Hello {name}', {name: 'Lena'}) }}|{{ t('Unknown text') }}")->render(),
            'The child first, then its parents; without a translation as written.',
        );
    }

    public function testExplainsWhatIsMissing(): void
    {
        $loader = $this->get(Environment::class)->getLoader();

        foreach ([
            '@theme/missing.twig' => 'The partial "@theme/missing.twig" does not exist. Looked in partials/ of acme/site, acme/base.',
            '@theme/../x.twig' => '"@theme/../x.twig" is no valid partial name: letters, digits, "-", "_" and "/", ending in .twig, e.g. "@theme/header.twig".',
            'header.twig' => 'The template "header.twig" does not exist.',
        ] as $name => $message) {
            try {
                $loader->getSourceContext($name);
                self::fail($name . ' was found.');
            } catch (LoaderError $error) {
                self::assertSame($message, $error->getRawMessage());
            }
        }

        self::assertFalse(new FileLoader()->exists('@theme/brand.twig'), 'Without a theme there are no partials.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }
}
