<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Media\CreateMediaFolder;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\DeleteMediaFolder;
use Frootbox\Core\Media\DeleteMedium;
use Frootbox\Core\Media\DiscardMedium;
use Frootbox\Core\Media\Images;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\ListMediaFolders;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaDrafts;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediaPruneCommand;
use Frootbox\Core\Media\MediaReferences;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\ReplaceMedium;
use Frootbox\Core\Media\ShowMedium;
use Frootbox\Core\Media\UpdateMediaFolder;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadHandler;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateMedium::class)]
#[CoversClass(UpdateMedium::class)]
#[CoversClass(DeleteMedium::class)]
#[CoversClass(ShowMedium::class)]
#[CoversClass(ListMedia::class)]
#[CoversClass(CreateMediaFolder::class)]
#[CoversClass(UpdateMediaFolder::class)]
#[CoversClass(DeleteMediaFolder::class)]
#[CoversClass(ListMediaFolders::class)]
#[CoversClass(MediaEditor::class)]
#[CoversClass(MediaDrafts::class)]
#[CoversClass(PublishMedia::class)]
#[CoversClass(DiscardMedium::class)]
#[CoversClass(Images::class)]
#[CoversClass(MediaUrls::class)]
#[CoversClass(MediaFiles::class)]
#[CoversClass(Uploads::class)]
#[CoversClass(UploadReceiver::class)]
#[CoversClass(UploadHandler::class)]
#[CoversClass(MediaHandler::class)]
#[CoversClass(MediaReferences::class)]
#[CoversClass(MediaPruneCommand::class)]
#[CoversClass(ReplaceMedium::class)]
#[CoversClass(ImageType::class)]
final class MediaTest extends NotesTestCase
{
    private int $users = 0;

    public function testUploadsAndCreatesAMedium(): void
    {
        $token = $this->upload('Röster am Hafen.JPG', 1200, 800);
        $medium = $this->runner()->run(new CreateMedium($token, texts: ['de' => ['alt' => 'Der Röster', 'title' => 'Hafen']], tags: ['kaffee', 'hafen', 'kaffee']));

        self::assertSame('Röster am Hafen.JPG', $medium->fileName);
        self::assertSame('image/jpeg', $medium->type);
        self::assertSame([1200, 800], [$medium->width, $medium->height]);
        self::assertSame(['x' => 0.5, 'y' => 0.5], $medium->focus);
        self::assertFalse($medium->public);
        self::assertSame(['de' => ['alt' => 'Der Röster', 'title' => 'Hafen']], $medium->texts);
        self::assertSame(['hafen', 'kaffee'], $medium->tags);
        self::assertStringStartsWith('/media/' . $medium->id . '/1/roester-am-hafen-', $medium->preview);
        self::assertFileExists($this->get(MediaFiles::class)->original($medium->id));

        // The token is used up.
        $this->assertInvalid(fn() => $this->runner()->run(new CreateMedium($token)), '/upload');
    }

    public function testTheSameFileGivesTheMediumThatIsThereAlready(): void
    {
        $file = $this->image(300, 200);
        $copy = $file . '.copy';
        copy($file, $copy);
        $hash = hash_file('sha256', $file);
        $first = $this->runner()->run(new CreateMedium($this->receive($file, 'hafen.jpg')['upload'], texts: ['de' => ['alt' => 'Der Hafen', 'title' => '']]));
        $folder = $this->runner()->run(new CreateMediaFolder('Andere'));
        $token = $this->receive($copy, 'hafen-kopie.jpg')['upload'];

        $again = $this->runner()->run(new CreateMedium($token, $folder->id, ['de' => ['alt' => 'Anders', 'title' => '']], ['neu']));

        self::assertFalse($first->existing);
        self::assertTrue($again->existing);
        self::assertSame($first->id, $again->id);
        self::assertSame('hafen.jpg', $again->fileName, 'The medium keeps its name, folder, texts and tags.');
        self::assertNull($again->folderId);
        self::assertSame(['de' => ['alt' => 'Der Hafen', 'title' => '']], $again->texts);
        self::assertSame([], $again->tags);
        self::assertSame(1, $this->get(Media::class)->count());
        self::assertNull($this->get(Uploads::class)->path($token), 'The upload is used up.');
        self::assertSame($hash, $this->get(Media::class)->getById($first->id)->hash, 'The hash is the one of the uploaded bytes.');
    }

    public function testRefusesFilesThatAreNoImages(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fb');
        file_put_contents($file, '<?php echo "hi";');

        $this->assertInvalid(fn() => $this->receive($file, 'shell.php'), '/file');
        $this->assertInvalid(fn() => $this->receive(null, ''), '/file');

        $token = $this->get(Uploads::class)->store($file, 'shell.php', move: false);
        $this->assertInvalid(fn() => $this->runner()->run(new CreateMedium($token)), '/upload');
        @unlink($file);
    }

    public function testChangesFocusTextsTagsAndFolder(): void
    {
        $medium = $this->medium('bohnen.png', 600, 600, 'png');
        $folder = $this->runner()->run(new CreateMediaFolder('Kaffee'));
        $sub = $this->runner()->run(new CreateMediaFolder('Röstungen', $folder->id));

        $updated = $this->runner()->run(new UpdateMedium($medium->id, folderId: $sub->id, focusX: 0.25, focusY: 1, texts: ['de' => ['alt' => 'Bohnen']], tags: ['Bohne']));

        self::assertSame($sub->id, $updated->folderId);
        self::assertSame(['x' => 0.25, 'y' => 1.0], $updated->focus);
        self::assertSame(2, $updated->version, 'A new focus point gives new addresses.');
        self::assertFalse($updated->draft, 'Nobody sees a medium that is not public: it needs no draft.');
        self::assertSame(['de' => ['alt' => 'Bohnen', 'title' => '']], $updated->texts);
        self::assertSame(['Bohne'], $updated->tags);

        $this->assertInvalid(fn() => $this->runner()->run(new UpdateMedium($medium->id, texts: ['fr' => ['alt' => 'Non']])), '/texts/fr');
        $this->assertInvalid(fn() => $this->runner()->run(new UpdateMediaFolder($folder->id, parentId: $sub->id)), '/parentId');
        $this->assertInvalid(fn() => $this->runner()->run(new DeleteMediaFolder($sub->id)), '/id');

        $folders = $this->runner()->ask(new ListMediaFolders());
        self::assertSame(['Kaffee', 'Röstungen'], array_map(static fn($folder): string => $folder->name, $folders));
        self::assertSame([0, 1], array_map(static fn($folder): int => $folder->media, $folders));

        $top = $this->runner()->run(new UpdateMedium($medium->id, folderId: ''));
        self::assertNull($top->folderId);
        self::assertTrue($this->runner()->run(new DeleteMediaFolder($sub->id)));
    }

    public function testListsBySearchTagAndFolder(): void
    {
        $folder = $this->runner()->run(new CreateMediaFolder('Team'));
        $anna = $this->medium('anna.jpg', 300, 400, folderId: $folder->id, texts: ['de' => ['alt' => 'Anna an der Maschine']], tags: ['team']);
        $bohnen = $this->medium('bohnen.jpg', 300, 200, tags: ['kaffee']);
        $this->medium('hafen.jpg', 300, 200);

        $ids = static fn(ListMedia $query, NotesTestCase $test): array => array_map(static fn(MediumDetails $medium): string => $medium->id, $test->runner()->ask($query)->items);

        self::assertSame(3, $this->runner()->ask(new ListMedia())->total);
        self::assertSame([$anna->id], $ids(new ListMedia(search: 'Maschine'), $this));
        self::assertSame([$bohnen->id], $ids(new ListMedia(search: 'bohn'), $this));
        self::assertSame([$bohnen->id], $ids(new ListMedia(tag: 'kaffee'), $this));
        self::assertSame([], $ids(new ListMedia(tag: 'nichts'), $this));
        self::assertSame([$anna->id], $ids(new ListMedia(folderId: $folder->id), $this));
        self::assertCount(2, $ids(new ListMedia(folderId: ''), $this));
        self::assertCount(1, $ids(new ListMedia(page: 2, perPage: 2), $this));
    }

    public function testNeedsThePermission(): void
    {
        $editor = $this->context(['editor']);
        $medium = $this->runner()->run(new CreateMedium($this->upload('a.jpg', 20, 20)), $editor);
        self::assertSame($medium->id, $this->runner()->ask(new ShowMedium($medium->id), $editor)->id);

        $this->expectAccessDenied(fn() => $this->runner()->ask(new ListMedia(), Context::guest()));
        $this->expectAccessDenied(fn() => $this->runner()->run(new DeleteMedium($medium->id), $this->context([])));
    }

    public function testDeliversSignedSizesAndWritesThemOnceTheMediumIsPublic(): void
    {
        $medium = $this->medium('hafen.jpg', 1200, 800);
        $urls = $this->get(MediaUrls::class);
        $model = $this->get(Media::class)->getById($medium->id);
        $url = $urls->url($model, 640, 360, 'webp');

        // Not public yet: only for those who may see the media library.
        self::assertSame(404, $this->deliver($url)->getStatusCode());
        $response = $this->deliver($url, $this->token(['editor']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([640, 360], \array_slice((array) getimagesizefromstring((string) $response->getBody()), 0, 2));
        self::assertFileDoesNotExist($this->get(MediaFiles::class)->public(substr($url, \strlen(MediaUrls::PREFIX))));

        $this->publishWith($medium->id);
        $response = $this->deliver($url);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        self::assertFileExists($this->get(MediaFiles::class)->public(substr($url, \strlen(MediaUrls::PREFIX))));

        // Only signed addresses, widths of the ladder and not wider than the original.
        self::assertSame(404, $this->deliver(str_replace('640x360', '641x360', $url))->getStatusCode());
        self::assertSame(404, $this->deliver(preg_replace('/-[0-9a-f]{16}\./', '-0000000000000000.', $url) ?? '')->getStatusCode());
        self::assertSame(404, $this->deliver($urls->url($model, 1600, 0, 'webp'))->getStatusCode());
        self::assertSame(404, $this->deliver($urls->url($model, 640, 0, 'gif'))->getStatusCode());
        self::assertSame(200, $this->deliver($urls->url($model, 1200, 0, 'jpg'))->getStatusCode());

        // A new focus point of a public medium waits in its draft (decision 0147).
        $this->runner()->run(new UpdateMedium($medium->id, focusX: 0.1, focusY: 0.5));
        self::assertSame(200, $this->deliver($url)->getStatusCode(), 'Visitors still get the published version.');
        $this->runner()->run(new PublishMedia([$medium->id]));
        self::assertSame(404, $this->deliver($url)->getStatusCode(), 'Published, it gives a new version; the old files are gone.');
        self::assertDirectoryDoesNotExist($this->get(MediaFiles::class)->public($medium->id));
    }

    public function testChangesOfAPublicMediumWaitInTheDraft(): void
    {
        $folder = $this->runner()->run(new CreateMediaFolder('Kaffee'));
        $medium = $this->medium('hafen.jpg', 1200, 800, texts: ['de' => ['alt' => 'Alt', 'title' => '']]);
        $other = $this->medium('anderes.jpg', 400, 300);
        $page = $this->publishWith($medium->id);
        $id = $medium->id;

        $changed = $this->runner()->run(new UpdateMedium($id, folderId: $folder->id, focusX: 0.2, focusY: 0.5, texts: ['de' => ['alt' => 'Neu']], tags: ['hafen']));
        self::assertTrue($changed->draft);
        self::assertSame(2, $changed->version, 'The draft has new sizes.');
        self::assertSame(['x' => 0.2, 'y' => 0.5], $changed->focus);
        self::assertSame('Neu', $changed->texts['de']['alt']);
        self::assertSame($folder->id, $changed->folderId, 'Folder and tags count at once.');
        self::assertSame(['hafen'], $changed->tags);

        $stored = $this->get(Media::class)->getById($id);
        self::assertSame([1, 500, $folder->id], [$stored->version, $stored->focusX, $stored->folderId]);
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/1/[^>]+ alt="Alt"#', $this->startPage(false), 'Visitors see the published state.');
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/2/[^>]+ alt="Neu"#', $this->startPage(true), 'The editing mode shows the draft.');
        self::assertSame([$id], array_map(static fn(MediumDetails $item): string => $item->id, $this->runner()->ask(new ListMedia(changed: true))->items));

        // The sizes of the draft only for those who may see the library, never written.
        self::assertSame(404, $this->deliver($changed->preview)->getStatusCode());
        $response = $this->deliver($changed->preview, $this->token(['editor']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFileDoesNotExist($this->get(MediaFiles::class)->public(substr($changed->preview, \strlen(MediaUrls::PREFIX))));
        self::assertSame(200, $this->deliver($this->get(MediaUrls::class)->url($stored, 640, 0, 'webp'))->getStatusCode());

        // Back to the published state, the draft is gone.
        $back = $this->runner()->run(new UpdateMedium($id, focusX: 0.5, focusY: 0.5, texts: ['de' => ['alt' => 'Alt']]));
        self::assertFalse($back->draft);
        self::assertSame(1, $back->version);

        $this->runner()->run(new UpdateMedium($id, focusX: 0.2, focusY: 0.5, texts: ['de' => ['alt' => 'Neu']]));
        $this->assertInvalid(fn() => $this->runner()->run(new PublishMedia([$id, $other->id])), '/ids/1');

        $published = $this->runner()->run(new PublishMedia([$id]))[0];
        self::assertFalse($published->draft);
        self::assertSame(2, $published->version);
        self::assertSame(200, $this->get(Media::class)->getById($id)->focusX);
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/2/[^>]+ alt="Neu"#', $this->startPage(false));

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertSame(1, $this->get(Media::class)->getById($id)->version, 'Undo brings the published state back.');
        self::assertTrue($this->runner()->ask(new ShowMedium($id))->draft, 'And the draft.');

        $discarded = $this->runner()->run(new DiscardMedium($id));
        self::assertFalse($discarded->draft);
        self::assertSame(['x' => 0.5, 'y' => 0.5], $discarded->focus);
        self::assertSame('Alt', $discarded->texts['de']['alt']);
        $this->assertInvalid(fn() => $this->runner()->run(new DiscardMedium($id)), '/id');

        $this->runner()->run(new UpdateMedium($id, texts: ['de' => ['alt' => 'Weg']]));
        $this->expectConflict(fn() => $this->runner()->run(new DeleteMedium($id)), 'published place');
        $this->runner()->run(new DeletePage($page));
        $this->runner()->run(new DeleteMedium($id));
        self::assertNull($this->get(Revisions::class)->draft(MediaDrafts::TYPE, $id), 'Deleting takes the draft along.');
    }

    public function testReplacesTheFileOfAMediumThatIsNotPublicAtOnce(): void
    {
        $medium = $this->medium('hafen.jpg', 1200, 800);
        $other = $this->medium('anderes.jpg', 300, 200);
        $id = $medium->id;
        $this->runner()->run(new UpdateMedium($id, focusX: 0.2, focusY: 0.3));
        $first = $this->get(MediaFiles::class)->original($id);
        $file = $this->image(600, 900, 'png');
        copy($file, $file . '.copy');
        copy($file, $file . '.other');

        $replaced = $this->runner()->run(new ReplaceMedium($id, $this->receive($file, 'neu.png')['upload']));
        $entry = $this->lastEntry();
        self::assertSame($id, $replaced->id);
        self::assertFalse($replaced->draft, 'Nobody sees it yet: no draft needed.');
        self::assertSame(['neu.png', 'image/png', 600, 900, 3], [$replaced->fileName, $replaced->type, $replaced->width, $replaced->height, $replaced->version]);
        self::assertSame(['x' => 0.2, 'y' => 0.3], $replaced->focus, 'The focus point stays (decision 0147).');
        $stored = $this->get(Media::class)->getById($id);
        self::assertNotNull($stored->file);
        self::assertFileExists($this->get(MediaFiles::class)->original($stored->original()));
        self::assertFileExists($first, 'The old original stays for undo.');
        self::assertSame(200, $this->deliver($replaced->preview, $this->token(['editor']))->getStatusCode());

        $this->assertInvalid(fn() => $this->runner()->run(new ReplaceMedium($id, $this->receive($file . '.copy', 'neu.png')['upload'])), '/upload');
        $this->assertInvalid(fn() => $this->runner()->run(new ReplaceMedium($other->id, $this->receive($file . '.other', 'x.png')['upload'])), '/upload');

        $this->runner()->run(new UndoAction($entry->id));
        $back = $this->get(Media::class)->getById($id);
        self::assertSame(['hafen.jpg', 1200, null], [$back->fileName, $back->width, $back->file]);

        // The replacing file is used nowhere now; media:prune takes it once undo is over.
        $unused = $this->get(MediaFiles::class)->original($stored->file);
        touch($unused, time() - 32 * 86400);
        touch($first, time() - 32 * 86400);
        $tester = new CommandTester($this->get(MediaPruneCommand::class));
        $tester->execute([]);
        self::assertFileDoesNotExist($unused);
        self::assertFileExists($first);
        self::assertStringContainsString('1 original(s)', $tester->getDisplay());
    }

    public function testReplacingAPublicMediumWaitsInTheDraft(): void
    {
        $raw = $this->image(1200, 800);
        copy($raw, $raw . '.copy');
        $id = $this->runner()->run(new CreateMedium($this->receive($raw, 'hafen.jpg')['upload'], texts: ['de' => ['alt' => 'Hafen', 'title' => '']]))->id;
        $this->publishWith($id);
        $first = $this->get(MediaFiles::class)->original($id);
        $file = $this->image(800, 800);
        copy($file, $file . '.copy');

        $replaced = $this->runner()->run(new ReplaceMedium($id, $this->receive($file, 'neu.jpg')['upload']));
        self::assertTrue($replaced->draft);
        self::assertSame(['neu.jpg', 800, 2], [$replaced->fileName, $replaced->width, $replaced->version]);
        self::assertSame(['hafen.jpg', 1, null], [$this->get(Media::class)->getById($id)->fileName, $this->get(Media::class)->getById($id)->version, $this->get(Media::class)->getById($id)->file]);
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/1/hafen-#', $this->startPage(false), 'Visitors see the published file.');
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/2/neu-[^>]+ alt="Hafen"#', $this->startPage(true), 'The editing mode shows the new one.');
        self::assertSame(404, $this->deliver($replaced->preview)->getStatusCode());
        self::assertSame(200, $this->deliver($replaced->preview, $this->token(['editor']))->getStatusCode());

        // The file in the draft is found again as well.
        $again = $this->runner()->run(new CreateMedium($this->receive($file . '.copy', 'kopie.jpg')['upload']));
        self::assertTrue($again->existing);
        self::assertSame($id, $again->id);

        // The published file again takes the new one out of the draft.
        $draftFile = $this->get(MediaDrafts::class)->of($id)['file']['name'] ?? '';
        $undone = $this->runner()->run(new ReplaceMedium($id, $this->receive($raw . '.copy', 'hafen.jpg')['upload']));
        self::assertFalse($undone->draft);
        self::assertSame(['hafen.jpg', 1], [$undone->fileName, $undone->version]);
        self::assertFileExists($this->get(MediaFiles::class)->original($draftFile), 'It stays for undo.');

        $this->runner()->run(new ReplaceMedium($id, $this->receive($this->image(500, 400), 'zweites.jpg')['upload']));
        $this->runner()->run(new PublishMedia([$id]));
        $live = $this->get(Media::class)->getById($id);
        self::assertSame(['zweites.jpg', 500, 2], [$live->fileName, $live->width, $live->version]);
        self::assertNotNull($live->file);
        self::assertMatchesRegularExpression('#<img src="/media/' . $id . '/2/zweites-[^>]+ alt="Hafen"#', $this->startPage(false));
        self::assertFileExists($first, 'The replaced original stays for undo.');

        $this->runner()->run(new ReplaceMedium($id, $this->receive($this->image(300, 300), 'drittes.jpg')['upload']));
        $third = $this->get(MediaDrafts::class)->of($id)['file']['name'] ?? '';
        $this->runner()->run(new DiscardMedium($id));
        self::assertFileExists($this->get(MediaFiles::class)->original($third));

        foreach ([$first, $this->get(MediaFiles::class)->original($draftFile), $this->get(MediaFiles::class)->original($third), $this->get(MediaFiles::class)->original($live->original())] as $old) {
            touch($old, time() - 32 * 86400);
        }

        new CommandTester($this->get(MediaPruneCommand::class))->execute([]);
        self::assertFileDoesNotExist($first);
        self::assertFileDoesNotExist($this->get(MediaFiles::class)->original($third));
        self::assertFileExists($this->get(MediaFiles::class)->original($live->original()), 'The published file stays, however old.');
        self::assertSame(200, $this->deliver($this->get(MediaUrls::class)->url($live, 320, 0, 'webp'))->getStatusCode());
    }

    public function testCutsAroundTheFocusPoint(): void
    {
        self::assertSame([800, 800, 0, 0], Images::crop(1200, 800, 1.0, 0, 500));
        self::assertSame([800, 800, 400, 0], Images::crop(1200, 800, 1.0, 1000, 500));
        self::assertSame([800, 800, 200, 0], Images::crop(1200, 800, 1.0, 500, 500));
        self::assertSame([1200, 675, 0, 125], Images::crop(1200, 800, 16 / 9, 500, 1000));
    }

    public function testMediaBecomePublicWithThePageAndRenderAsPicture(): void
    {
        $cover = $this->medium('cover.jpg', 2000, 1000, texts: ['de' => ['alt' => 'Aus der Bibliothek']]);
        $inList = $this->medium('liste.png', 400, 300, 'png');
        $unused = $this->medium('unused.jpg', 100, 100);

        $hero = $this->medium('hero.jpg', 1600, 900);
        $this->runner()->run(new UpdateMedium($inList->id, focusX: 0.25, focusY: 0.8));

        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Bilder'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => ['cover' => ['media' => $cover->id], 'hero' => ['media' => $hero->id], 'pictures' => [['picture' => ['media' => $inList->id, 'alt' => 'Eigener Text']]]]],
        ]]]]]))->id;

        self::assertFalse($this->get(Media::class)->getById($cover->id)->public, 'A draft does not make media public.');

        $this->runner()->run(new PublishPages([$page]));

        $media = $this->get(Media::class);
        self::assertTrue($media->getById($cover->id)->public);
        self::assertTrue($media->getById($inList->id)->public);
        self::assertFalse($media->getById($unused->id)->public);

        $html = (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();

        // AVIF only where the image driver writes it (decision 0126), then WebP.
        $formats = $this->get(MediaUrls::class)->modernFormats();
        self::assertContains('webp', $formats);
        $sources = array_map(
            static fn(string $format): string => '<source type="image/' . $format . '" srcset="/media/' . $cover->id . '/1/cover-160x90-[0-9a-f]{16}\.' . $format . ' 160w, [^"]+" sizes="50vw">',
            $formats,
        );
        self::assertMatchesRegularExpression('#<picture>' . implode('', $sources) . '<img #', $html);
        self::assertStringContainsString(' 1920w" sizes="50vw">', $html);
        self::assertMatchesRegularExpression('#<img src="/media/' . $cover->id . '/1/cover-960x540-[0-9a-f]{16}\.jpg" srcset="[^"]+" sizes="50vw" width="960" height="540" alt="Aus der Bibliothek" loading="lazy" decoding="async">#', $html);
        self::assertMatchesRegularExpression('#<img src="/media/' . $inList->id . '/2/liste-320x0-[0-9a-f]{16}\.png" srcset="[^"]+" sizes="100vw" width="320" height="240" alt="Eigener Text" loading="lazy" decoding="async" style="object-position: 25% 80%">#', $html, 'Without a ratio the focus point keeps its place under object-fit: cover.');
        self::assertMatchesRegularExpression('#<img src="/media/' . $hero->id . '/1/hero-[^>]+ loading="eager" fetchpriority="high" decoding="async">#', $html, 'The first image of a page loads at once.');

        // A published page keeps its media (decision 0147); one gone anyway shows nothing.
        $this->expectConflict(fn() => $this->runner()->run(new DeleteMedium($cover->id)), '1 published place');
        $this->connection()->executeStatement('DELETE FROM media WHERE id = ?', [$cover->id]);
        $html = (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();
        self::assertStringNotContainsString($cover->id, $html);
        self::assertStringContainsString($inList->id, $html);
    }

    public function testAPageWithADeletedMediumCanStillBePublished(): void
    {
        $medium = $this->medium('weg.jpg', 100, 100);
        $this->runner()->run(new DeleteMedium($medium->id));

        $this->publishWith($medium->id);

        self::assertNull($this->get(Media::class)->fetchById($medium->id));
    }

    public function testDeletingCanBeUndoneUntilTheFilesArePruned(): void
    {
        $medium = $this->medium('weg.jpg', 100, 100, texts: ['de' => ['alt' => 'Weg']], tags: ['alt']);
        $original = $this->get(MediaFiles::class)->original($medium->id);

        $this->runner()->run(new DeleteMedium($medium->id));
        self::assertNull($this->get(Media::class)->fetchById($medium->id));
        self::assertFileExists($original, 'The original stays for undo.');

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        $restored = $this->runner()->ask(new ShowMedium($medium->id));
        self::assertSame(['de' => ['alt' => 'Weg', 'title' => '']], $restored->texts);
        self::assertSame(['alt'], $restored->tags);

        $this->runner()->run(new DeleteMedium($medium->id));
        $tester = new CommandTester($this->get(MediaPruneCommand::class));
        $tester->execute([]);
        self::assertFileExists($original, 'Too young to be pruned.');

        touch($original, time() - 32 * 86400);
        $kept = $this->medium('bleibt.jpg', 10, 10);
        touch($this->get(MediaFiles::class)->original($kept->id), time() - 32 * 86400);
        $tester->execute([]);
        self::assertFileDoesNotExist($original);
        self::assertFileExists($this->get(MediaFiles::class)->original($kept->id));
        self::assertStringContainsString('1 original(s)', $tester->getDisplay());
    }

    public function testUploadsThroughTheApi(): void
    {
        $file = $this->image(50, 40);
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost' . UploadHandler::PATH)
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, (int) filesize($file), \UPLOAD_ERR_OK, 'api.jpg', 'image/jpeg')]);

        $response = $this->send($request->withHeader('Authorization', 'Bearer ' . $this->token(['editor'])));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertSame('api.jpg', $data['data']['name']);
        self::assertIsString($data['data']['upload']);
        self::assertSame('api.jpg', $this->runner()->run(new CreateMedium($data['data']['upload']))->fileName);

        self::assertSame(401, $this->send($factory->createServerRequest('POST', 'http://localhost' . UploadHandler::PATH))->getStatusCode());
        self::assertSame(403, $this->send($factory->createServerRequest('POST', 'http://localhost' . UploadHandler::PATH)->withHeader('Authorization', 'Bearer ' . $this->token([])))->getStatusCode());
    }

    /**
     * Publishes a page that uses the medium.
     *
     * @return string The id of the page
     */
    private function publishWith(string $id): string
    {
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Bild'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => ['cover' => ['media' => $id]]],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));

        return $page;
    }

    /**
     * The start page as a guest or in the editing mode of an editor.
     */
    private function startPage(bool $editing): string
    {
        $this->get(Urls::class)->clear();
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org/');

        if ($editing) {
            $user = $this->runner()->run(new CreateUser('media' . ++$this->users . '@example.com', 'User', roles: ['editor']), Context::system())->user;
            $token = new AccessToken(1, $user->id, TokenType::Session, null, null, null, new DateTimeImmutable());
            $request = $request->withCookieParams([EditMode::COOKIE => '1'])->withAttribute(Authentication::class, new Authentication(Actor::user($user->id, $user->name), $token));
        }

        try {
            return (string) $this->get(PageHandler::class)->handle($request)->getBody();
        } finally {
            $this->get(EditMode::class)->set(false);
        }
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     * @param array<mixed> $texts
     * @param list<string> $tags
     */
    private function medium(string $name, int $width, int $height, string $type = 'jpeg', ?string $folderId = null, array $texts = [], array $tags = []): MediumDetails
    {
        return $this->runner()->run(new CreateMedium($this->upload($name, $width, $height, $type), $folderId, $texts, $tags));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function upload(string $name, int $width, int $height, string $type = 'jpeg'): string
    {
        return $this->receive($this->image($width, $height, $type), $name)['upload'];
    }

    /**
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     */
    private function receive(?string $file, string $name): array
    {
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/');

        if (null !== $file) {
            $request = $request->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, (int) filesize($file), \UPLOAD_ERR_OK, $name)]);
        }

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(int $width, int $height, string $type = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 120, 40));
        // Each image differs, or the library would find it again (decision 0146).
        imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagefilledrectangle($image, $width - 4, $height - 4, $width - 1, $height - 1, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.' . $type);
        @mkdir(\dirname($file), 0o775, true);
        'png' === $type ? imagepng($image, $file) : imagejpeg($image, $file, 90);

        return $file;
    }

    /**
     * @param list<string> $roles
     */
    private function context(array $roles): Context
    {
        $user = $this->runner()->run(new CreateUser('media' . ++$this->users . '@example.com', 'User', roles: $roles), Context::system())->user;

        return new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    /**
     * @param list<string> $roles
     */
    private function token(array $roles): string
    {
        $user = $this->runner()->run(new CreateUser('media' . ++$this->users . '@example.com', 'User', roles: $roles), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);

        return $this->send(null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
    }

    private function send(ServerRequestInterface $request): ResponseInterface
    {
        // Each request starts as a guest, like in production.
        $this->get(CurrentContext::class)->set(Context::guest());
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }

    private function expectConflict(callable $callback, string $text): void
    {
        try {
            $callback();
        } catch (ConflictException $exception) {
            self::assertStringContainsString($text, $exception->getMessage());

            return;
        }

        self::fail('Expected a conflict.');
    }

    private function assertInvalid(callable $callback, string $pointer): void
    {
        try {
            $callback();
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey($pointer, $error->messages, implode(', ', array_keys($error->messages)));

            return;
        }

        self::fail('Expected invalid input at ' . $pointer);
    }

    private function expectAccessDenied(callable $callback): void
    {
        try {
            $callback();
        } catch (AccessDeniedException) {
            return;
        }

        self::fail('Expected access to be denied.');
    }
}
