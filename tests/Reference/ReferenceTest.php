<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Reference;

use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Reference\Reference;
use Frootbox\Core\Reference\ReferenceCommand;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The reference is written from the code (decision 0265).
 */
#[CoversClass(Reference::class)]
#[CoversClass(ReferenceCommand::class)]
final class ReferenceTest extends NotesTestCase
{
    public function testDescribesTheActionsCommandsAndExtensionPointsOfTheInstallation(): void
    {
        $pages = new Reference($this->get(Registry::class), $this->container)->pages();

        self::assertSame(['README.md', 'actions.md', 'console.md', 'registry.md', 'twig.md', 'field-types.md', 'events.md'], array_keys($pages));
        self::assertStringStartsWith(Reference::MARKER, $pages['actions.md']);
        self::assertStringContainsString("### `mail.test`\n\nSends a short test mail", $pages['actions.md']);
        self::assertStringContainsString('| `to` | string or null (email), optional | The recipient', $pages['actions.md']);
        self::assertStringContainsString('## acme/shop', $pages['actions.md'], 'Extensions too, after the core.');
        self::assertStringContainsString("## `about`\n\nShows version", $pages['console.md']);
        self::assertStringContainsString('## `action(string $class)`', $pages['registry.md']);
        self::assertStringNotContainsString('forPackage', $pages['registry.md'], 'Nothing internal.');
        self::assertStringContainsString("| `{{ heading('title') }}` | the field as a heading", $pages['twig.md']);
        self::assertStringContainsString('| `headline` | A single line for a heading |', $pages['field-types.md']);
        self::assertStringContainsString("## `image`\n\nAn image from the media library:\n\n```\n{\"media\": \"01J…\"", $pages['field-types.md'], 'Each type with the format of its value.');

        self::assertStringContainsString("## `ScheduleRan`\n\n`Frootbox\\Core\\Schedule\\ScheduleRan`", $pages['events.md']);
        self::assertStringContainsString('| `$now` | `DateTimeImmutable` |', $pages['events.md']);

        foreach ($pages as $file => $markdown) {
            self::assertDoesNotMatchRegularExpression('/decisions? \d{4}/', $markdown, $file . ' names no internal decision.');
        }
    }

    public function testListsEveryEventTheCoreDispatches(): void
    {
        $dispatched = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            // dispatch(new X(…)) or $event = new X(…) before dispatch($event).
            if (str_contains($code, '->events->dispatch(')) {
                preg_match_all('/->dispatch\(new (\w+)\(/', $code, $direct);
                preg_match_all('/\$event = new (\w+)\(/', $code, $prepared);
                $dispatched = [...$dispatched, ...$direct[1], ...$prepared[1]];
            }
        }

        $listed = array_map(static fn(string $class): string => substr($class, (int) strrpos($class, '\\') + 1), Reference::EVENTS);
        sort($dispatched);

        self::assertSame($listed, $dispatched, 'Each event the core dispatches is in Reference::EVENTS.');
    }

    public function testLeavesOutTheNumbersOfTheInternalDecisions(): void
    {
        self::assertSame('A group of the block picker, e.g. x.', Reference::withoutDecisions('A group of the block picker (decision 0148), e.g. x.'));
        self::assertSame('Content (e.g. a video).', Reference::withoutDecisions('Content (e.g. a video, decisions 0224 and 0225).'));
        self::assertSame('Routes (see the mounts).', Reference::withoutDecisions('Routes (decision 0110, see the mounts).'));
        self::assertSame('One line. Another.', Reference::summary("/**\n * One line.\n * Another (decision 0001).\n *\n * @param string \$x\n */"));
        self::assertSame("A list (of entries):\n\n```\nitems: { type: list }\n```\n\n`{{ field('x') }}` gives a `<p>` with it.", Reference::details("/**\n * A list (decision 0001)\n * (of entries):\n *\n *     items: { type: list }\n *\n * {{ field('x') }} gives a <p> with\n * it.\n *\n * @param string \$x\n */"));
    }

    public function testWritesTheFolderAndTellsWhenItIsBehind(): void
    {
        $tester = new CommandTester($this->get(ReferenceCommand::class));
        $folder = $this->get(ProjectPaths::class)->root . '/documentation/reference';
        mkdir($folder, 0o777, true);
        file_put_contents($folder . '/old.md', Reference::MARKER . "\n\n# Gone\n");
        file_put_contents($folder . '/notes.md', "# Written by hand\n");

        self::assertSame(1, $tester->execute(['--check' => true]));
        self::assertStringContainsString('The reference is behind', $tester->getDisplay());
        self::assertFileDoesNotExist($folder . '/README.md', 'A check writes nothing.');

        self::assertSame(0, $tester->execute([]));
        self::assertFileExists($folder . '/actions.md');
        self::assertFileDoesNotExist($folder . '/old.md', 'A page that is gone goes.');
        self::assertFileExists($folder . '/notes.md', 'A page by hand stays.');

        self::assertSame(0, $tester->execute(['--check' => true]));
        self::assertStringContainsString('up to date', $tester->getDisplay());
    }
}
