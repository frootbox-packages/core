<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Extension;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\ExtensionList;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\ExtensionSummary;
use Frootbox\Core\Extension\ListExtensions;
use Frootbox\Core\Extension\OfficialModule;
use Frootbox\Core\Extension\OfficialModules;
use Frootbox\Core\Extension\Source\ComposerSource;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Tests\Support\FakeComposer;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The installed extensions for the backend, the REST API and the AI
 * (decision 0274).
 */
#[CoversClass(ListExtensions::class)]
#[CoversClass(ExtensionList::class)]
#[CoversClass(ExtensionSummary::class)]
#[CoversClass(ExtensionPackage::class)]
#[CoversClass(ComposerSource::class)]
final class ListExtensionsTest extends NotesTestCase
{
    private ?TemporaryProject $packages = null;

    public function testListsEachExtensionWithWhatItBrings(): void
    {
        $list = $this->runner()->ask(new ListExtensions());
        $byName = array_column(array_map(static fn(ExtensionSummary $summary): array => [$summary->name, $summary], $list->extensions), 1, 0);

        self::assertSame(Frootbox::version(), $list->version);
        self::assertSame(['acme/base', 'acme/plugin', 'acme/shop', 'acme/site', 'acme/notes'], array_keys($byName), 'Packages by name, those of the website last.');

        $base = $byName['acme/base'];
        self::assertTrue($base->theme);
        self::assertTrue($base->activeTheme, 'The parent of the active theme counts.');
        self::assertContains('acme/base/Teaser', $base->blocks);
        self::assertSame(['acme/base/Page'], $base->layouts);
        self::assertTrue($byName['acme/site']->activeTheme);
        self::assertFalse($byName['acme/plugin']->theme);
        self::assertSame(['acme/shop/order.list'], $byName['acme/shop']->queries);
        self::assertSame([], $byName['acme/shop']->actions);

        $notes = $byName['acme/notes'];
        self::assertTrue($notes->project);
        self::assertSame('1.2.0', $notes->version);
        self::assertTrue($notes->settings);
        self::assertTrue($notes->migrations);
        self::assertFalse($byName['acme/shop']->settings);
        self::assertFalse($byName['acme/shop']->migrations);
    }

    public function testNamesTheOfficialModulesThatAreNotInstalled(): void
    {
        $list = $this->runner()->ask(new ListExtensions());
        $names = array_map(static fn(OfficialModule $module): string => $module->name, $list->available);

        self::assertSame(array_map(static fn(OfficialModule $module): string => $module->name, OfficialModules::load()), $names, 'None of them is installed here.');
        self::assertContains('frootbox/news', $names);
        self::assertNotContains('frootbox/backend', $names, 'Only modules.');
        $news = array_values(array_filter($list->available, static fn(OfficialModule $module): bool => 'frootbox/news' === $module->name));
        self::assertSame('composer require frootbox/news', $news[0]->command);
    }

    public function testOnlySuperusersSeeIt(): void
    {
        $admin = $this->runner()->run(new CreateUser('admin@example.com', 'Admin', roles: ['admin']), Context::system())->user;

        $this->expectException(AccessDeniedException::class);
        $this->runner()->ask(new ListExtensions(), new Context(Actor::user($admin->id, $admin->name), Channel::Api));
    }

    public function testTheVersionComesFromComposer(): void
    {
        $project = TemporaryProject::create();

        try {
            $composer = new FakeComposer($project);
            $composer->add('acme/website', ['title' => 'Website'], version: '2.0.1');
            $composer->add('acme/plain', ['title' => 'Plain']);
            $packages = new ComposerSource($project->root . '/vendor', $project->root . '/extensions')->packages();

            self::assertSame(['2.0.1', null], array_map(static fn(ExtensionPackage $package): ?string => $package->version, $packages));
        } finally {
            $project->remove();
        }
    }

    protected function extensions(): Extensions
    {
        $this->packages = TemporaryProject::create();
        mkdir($this->packages->root . '/notes/migrations', 0o777, true);
        mkdir($this->packages->root . '/shop', 0o777, true);

        return new Extensions([
            ...FixtureSite::extensions('base', 'site', 'plugin')->packages,
            new ExtensionPackage('acme/notes', $this->packages->root . '/notes', 'Notes', psr4: ['Acme\Notes\Migrations\\' => ['migrations/']], isProject: true, version: '1.2.0'),
            new ExtensionPackage('acme/shop', $this->packages->root . '/shop', 'Shop'),
        ]);
    }

    protected function config(): array
    {
        return ['theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->packages?->remove();
    }
}
