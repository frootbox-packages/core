<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Seed;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Navigation\ShowNavigation;
use Frootbox\Core\Page\Pages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Seed\Seeder;
use Frootbox\Core\Seed\SeedException;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Seeder::class)]
#[CoversClass(SeedException::class)]
final class SeederTest extends NotesTestCase
{
    public function testRunsTheStepsOfAFile(): void
    {
        $seeder = $this->get(Seeder::class);
        $file = $seeder->file('acme/site:demo/seed.yaml', '/');
        $names = [];

        $results = $seeder->run($seeder->read($file), static function (int $number, string $name) use (&$names): void {
            $names[] = $number . ' ' . $name;
        });

        self::assertSame(['1 page.create', '2 page.create', '3 navigation.update', '4 page.publish', '5 navigation.publish', '6 page.tree'], $names);
        self::assertSame(['start', 'about', 'tree'], array_keys($results));
        self::assertSame(2, $this->get(Pages::class)->count());

        $items = $this->runner()->ask(new ShowNavigation('main'))->published['de'] ?? [];
        self::assertIsArray($results['about']);
        self::assertIsString($results['about']['id']);
        self::assertSame('page:' . $results['about']['id'], $items[0]['target'] ?? null);
        self::assertSame('Mehr über Über uns', $items[0]['label'] ?? null);
    }

    public function testUploadsFilesForTheMedia(): void
    {
        $folder = $this->get(ProjectPaths::class)->var('seed');
        @mkdir($folder . '/media', 0o775, true);
        $image = imagecreatetruecolor(320, 200);
        imagefilledrectangle($image, 0, 0, 160, 200, (int) imagecolorallocate($image, 200, 120, 40));
        imagejpeg($image, $folder . '/media/roastery.jpg', 90);
        file_put_contents($folder . '/seed.yaml', <<<'YAML'
            - upload: { file: media/roastery.jpg }
              as: roastery
            - media.create: { upload: '${roastery.token}', texts: { de: { alt: Die Rösterei } } }
              as: medium
            YAML);

        $seeder = $this->get(Seeder::class);
        $results = $seeder->run($seeder->read($folder . '/seed.yaml'));

        self::assertIsArray($results['medium']);
        self::assertIsString($results['medium']['id']);
        $medium = $this->get(Media::class)->fetchById($results['medium']['id']);
        self::assertNotNull($medium);
        self::assertSame(320, $medium->width);
        self::assertFileExists($folder . '/media/roastery.jpg', 'The seed keeps its file, the upload is a copy.');
    }

    public function testStopsAtTheFirstProblem(): void
    {
        $cases = [
            "page.create: {}\n" => 'must be a list of steps',
            "- 12\n" => 'Step 1 must be an object such as "page.create: { … }".',
            "- { page.tree: {}, page.show: {} }\n" => 'Step 1 must name exactly one action or query, besides "as". Found: page.tree, page.show.',
            "- { page.tree: [1] }\n" => 'Step 1: the input of page.tree must be an object.',
            "- { page.tree: {}, as: 'a b' }\n" => 'Step 1: "as" must be a name of letters, digits, "-" and "_".',
            "- page.tree: {}\n  as: tree\n- page.show: { id: '\${tree.x}' }\n" => 'Step 2: ${tree.x} is unknown. Remembered so far: tree.',
            "- page.tree: {}\n  as: tree\n- page.show: { id: 'x\${tree}' }\n" => 'Step 2: ${tree} is no text or number.',
            "- page.show: { id: 5 }\n" => "Step 1 (page.show) has invalid input:\n  /id: ",
            "- page.gone: {}\n" => 'Step 1 (page.gone) failed: ',
            "- page.show: { id: 01ARZ3NDEKTSV4RRFFQ69G5FAV }\n" => 'Step 1 (page.show) failed: ',
            "- [\n" => 'Malformed',
            "- upload: { file: gone.jpg }\n" => 'Step 1: There is no file',
            "- upload: { file: a.jpg, name: b }\n" => 'Step 1: "upload" takes only "file"',
        ];
        $seeder = $this->get(Seeder::class);
        $file = $this->get(ProjectPaths::class)->var('seed.yaml');

        foreach ($cases as $yaml => $message) {
            file_put_contents($file, $yaml);

            try {
                $seeder->run($seeder->read($file));
                self::fail('The file ran: ' . $yaml);
            } catch (SeedException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $yaml);
            }
        }

        foreach (['acme/none:x.yaml' => 'There is no extension acme/none.', 'gone.yaml' => 'There is no file /tmp/gone.yaml.'] as $path => $message) {
            try {
                $seeder->file($path, '/tmp');
                self::fail($path . ' was found.');
            } catch (SeedException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
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
