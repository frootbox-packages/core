<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Console;

use Frootbox\Core\Cache\CacheFile;
use Frootbox\Core\Console\BlocksCheckCommand;
use Frootbox\Core\Console\BlocksCommand;
use Frootbox\Core\Console\CacheClearCommand;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(BlocksCommand::class)]
#[CoversClass(BlocksCheckCommand::class)]
#[CoversClass(CacheClearCommand::class)]
#[CoversClass(CacheFile::class)]
final class BlockCommandsTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsBlocksAndLayouts(): void
    {
        [$exitCode, $output] = $this->console('blocks');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Theme: acme/site → acme/base', $output);
        self::assertStringContainsString('acme/base/Teaser', $output);
        self::assertStringContainsString('Default (acme/site), ImageLeft (acme/site)', $output);
        self::assertStringContainsString('Default, Compact (acme/site)', $output);
        self::assertStringContainsString('acme/base/Page', $output);
        self::assertStringNotContainsString('Promo', $output);
    }

    public function testChecksAllBlocks(): void
    {
        [$exitCode, $output] = $this->console('blocks:check');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('5 blocks and 1 layouts with 9 templates are fine.', $output);
    }

    public function testReportsSchemaAndTwigErrors(): void
    {
        $root = $this->project->root . '/broken';
        mkdir($root . '/blocks/Twig/views', 0o777, true);
        file_put_contents($root . '/frootbox.yaml', 'title: Broken');
        file_put_contents($root . '/blocks/Twig/views/Default.twig', "{# frootbox\ntitle: Twig\n#}{% if %}");

        [$exitCode, $output] = $this->console('blocks:check', new Extensions([ExtensionPackage::fromManifest('acme/broken', $root)]));
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('acme/broken/Twig, variant Default: Unexpected token', $output);

        mkdir($root . '/blocks/Schema/views', 0o777, true);
        file_put_contents($root . '/blocks/Schema/views/Default.twig', "{# frootbox\ntitle: Schema\nfields:\n  x: { type: nope }\n#}");

        [$exitCode, $output] = $this->console('blocks:check', new Extensions([ExtensionPackage::fromManifest('acme/broken', $root)]));
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('there is no field type "nope"', $output);
    }

    public function testClearsTheCache(): void
    {
        CacheFile::write($this->project->root . '/var/cache/a/b.cache', 'x', [1]);
        self::assertSame([1], CacheFile::read($this->project->root . '/var/cache/a/b.cache', 'x', []));
        self::assertNull(CacheFile::read($this->project->root . '/var/cache/a/b.cache', 'y', []));

        [$exitCode, $output] = $this->console('cache:clear');

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Removed 1 cached file(s).', $output);
        self::assertDirectoryExists($this->project->root . '/var/cache');
        self::assertDirectoryDoesNotExist($this->project->root . '/var/cache/a');
    }

    /**
     * @return array{int, string}
     */
    private function console(string $command, ?Extensions $extensions = null): array
    {
        $console = FixtureSite::container($this->project, null === $extensions ? 'acme/site' : null, extensions: $extensions)->get(ConsoleApplication::class);
        self::assertInstanceOf(ConsoleApplication::class, $console);
        $console->setAutoExit(false);
        $output = new BufferedOutput();

        return [$console->run(new ArrayInput(['command' => $command]), $output), $output->fetch()];
    }
}
