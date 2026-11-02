<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\I18n;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Component\Area;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Option;
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\I18n\Labels;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\I18n\UserLanguages;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Labels::class)]
final class LabelsTest extends TestCase
{
    public function testFindsThePackageOfAName(): void
    {
        self::assertSame('acme/website', Labels::packageOf('acme/website/Teaser'));
        self::assertSame('acme/website', Labels::packageOf('acme/website/teasers'));
        self::assertSame(Labels::CORE, Labels::packageOf('headline'));
    }

    public function testTranslatesEveryTextOfAComponent(): void
    {
        $project = TemporaryProject::create();

        try {
            mkdir($project->root . '/theme/translations', 0o777, true);
            file_put_contents($project->root . '/theme/translations/de.yaml', Yaml::dump([
                'Teaser' => 'Anreißer', 'A short teaser.' => 'Ein kurzer Anreißer.', 'Heading' => 'Überschrift', 'Say it short' => 'Sag es kurz',
                'Entries' => 'Einträge', 'Main' => 'Inhalt', 'Wide' => 'Breit', 'Width' => 'Breite', 'Narrow' => 'Schmal', 'Short' => 'Kurz',
            ]));
            $config = new Config(Environment::Development, ['backend' => ['language' => 'de']]);
            $users = new class implements UserLanguages {
                public function of(int $userId): ?string
                {
                    return null;
                }
            };
            $translator = new Translator($config, Languages::fromConfig($config), new Extensions([new ExtensionPackage('acme/website', $project->root . '/theme', 'Theme')]), new ProjectPaths($project->root), new CurrentContext(), $users);

            $component = new Labels($translator)->component(new Component(
                ComponentKind::Block,
                'acme/website/Teaser',
                '/tmp',
                'Teaser',
                'A short teaser.',
                ['title' => new Field('title', 'headline', 'Heading', placeholder: 'Say it short'), 'items' => new Field('items', 'list', 'Entries', fields: ['text' => new Field('text', 'text', 'Heading')])],
                ['main' => new Area('main', 'Main')],
                ['Default' => new Variant('Default', 'Wide', '', ['width' => new Option('width', 'choice', 'Width', 'wide', choices: ['wide' => 'Wide', 'narrow' => 'Narrow'])], '/tmp/Default.twig', 'acme/website')],
                keywords: ['Short'],
            ));
        } finally {
            $project->remove();
        }

        self::assertSame(['Anreißer', 'Ein kurzer Anreißer.'], [$component->title, $component->description]);
        self::assertSame(['Überschrift', 'Sag es kurz'], [$component->fields['title']->label, $component->fields['title']->placeholder]);
        self::assertSame(['Einträge', 'Überschrift'], [$component->fields['items']->label, $component->fields['items']->fields['text']->label]);
        self::assertSame('Inhalt', $component->areas['main']->label);
        self::assertSame('Breit', $component->variants['Default']->title);
        self::assertSame('Breite', $component->variants['Default']->options['width']->label);
        self::assertSame(['wide' => 'Breit', 'narrow' => 'Schmal'], $component->variants['Default']->options['width']->choices);
        self::assertSame(['Kurz'], $component->keywords);
    }
}
