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

    public function testHintsAtMissingGroupsAndPreviews(): void
    {
        $root = $this->project->root . '/kit';
        mkdir($root . '/blocks/Good/views', 0o777, true);
        mkdir($root . '/blocks/Odd/views', 0o777, true);
        file_put_contents($root . '/frootbox.yaml', 'title: Kit');
        file_put_contents($root . '/blocks/Good/views/Default.twig', "{# frootbox\ntitle: Good\ngroup: teaser\n#}");
        file_put_contents($root . '/blocks/Good/preview.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" id="preview"/>');
        file_put_contents($root . '/blocks/Odd/views/Default.twig', "{# frootbox\ntitle: Odd\ngroup: acme/kit/gone\n#}");
        file_put_contents($root . '/blocks/Odd/preview.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"/>');
        file_put_contents($root . '/blocks/Odd/preview.Wide.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        [$exitCode, $output] = $this->console('blocks:check', new Extensions([ExtensionPackage::fromManifest('acme/kit', $root)]));
        $output = (string) preg_replace('/\s+/', ' ', $output);

        self::assertSame(0, $exitCode, 'Hints do not fail the check.');
        self::assertStringNotContainsString('acme/kit/Good', $output);
        self::assertStringContainsString('acme/kit/Odd names the group "acme/kit/gone", which is not registered.', $output);
        self::assertStringContainsString('acme/kit/Odd: preview.svg: needs a viewBox in 16:10', $output);
        self::assertStringContainsString('acme/kit/Odd: preview.Wide.png: there is no variant Wide', $output);
        self::assertStringContainsString('2 blocks and 0 layouts with 2 templates are fine.', $output);

        file_put_contents($root . '/blocks/Odd/views/Default.twig', "{# frootbox\ntitle: Odd\n#}");
        file_put_contents($root . '/blocks/Odd/views/Wide.twig', "{# frootbox\ntitle: Wide\n#}");
        file_put_contents($root . '/blocks/Odd/preview.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 100"/>');

        [, $output] = $this->console('blocks:check', new Extensions([ExtensionPackage::fromManifest('acme/kit', $root)]));
        $output = (string) preg_replace('/\s+/', ' ', $output);

        self::assertStringContainsString('acme/kit/Odd has no group', $output);
        self::assertStringContainsString('acme/kit/Odd: preview.svg: needs id="preview"', $output);
        self::assertStringContainsString('acme/kit/Odd: preview.Wide.png: is 1x1; previews are 16:10', $output);
    }

    public function testHintsAtHeadingsThatBreakTheOutline(): void
    {
        $root = $this->project->root . '/kit';
        $blocks = [
            'Good' => "{{ heading('title') }}{% for item in field('items') %}{{ item.heading('question', 1) }}{% endfor %}{{ field('body') }}",
            'Fixed' => "<section>\n  < H2 class=\"x\">{{ field('title') }}</h2></section>",
            'Hidden' => "{{ heading('title') }}{{ ('<h' ~ 4 ~ '>Deep</h4>')|raw }}",
        ];

        foreach ($blocks as $name => $template) {
            mkdir($root . '/blocks/' . $name . '/views', 0o777, true);
            file_put_contents($root . '/blocks/' . $name . '/views/Default.twig', "{# frootbox\ntitle: {$name}\ngroup: text\nfields:\n  title: { type: headline, placeholder: '<h2>' }\n  body: { type: richtext, optional: true }\n  items: { type: list, optional: true, fields: { question: { type: headline } } }\n#}" . $template);
            file_put_contents($root . '/blocks/' . $name . '/preview.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" id="preview"/>');
        }

        file_put_contents($root . '/frootbox.yaml', 'title: Kit');

        [$exitCode, $output] = $this->console('blocks:check', new Extensions([ExtensionPackage::fromManifest('acme/kit', $root)]));
        $output = (string) preg_replace('/\s+/', ' ', $output);

        self::assertSame(0, $exitCode, 'Hints do not fail the check.');
        self::assertStringNotContainsString('acme/kit/Good', $output);
        self::assertStringContainsString('acme/kit/Fixed, variant Default: writes a fixed <h2>. Use heading()', $output);
        self::assertStringContainsString('acme/kit/Hidden, variant Default, on top of a page: h4 follows h1 and skips a level.', $output);
        self::assertStringContainsString('acme/kit/Hidden, variant Default, further down: h4 follows h2 and skips a level.', $output);
        self::assertStringNotContainsString('in a section with a heading', $output, 'There the h4 fits under the h3 of the block.');
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
