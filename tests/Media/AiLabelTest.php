<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Media\AiOrigin;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Images;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\ReplaceMedium;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Document;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Media made or changed with AI (decision 0251).
 */
#[CoversClass(AiOrigin::class)]
#[CoversClass(Images::class)]
#[CoversClass(ImageType::class)]
#[CoversClass(MediaEditor::class)]
#[CoversClass(ListMedia::class)]
#[CoversClass(Document::class)]
final class AiLabelTest extends NotesTestCase
{
    public function testTheLabelComesFromTheFileUnlessGiven(): void
    {
        $generated = $this->runner()->run(new CreateMedium($this->upload('trainedAlgorithmicMedia')));
        $edited = $this->runner()->run(new CreateMedium($this->upload('compositeWithTrainedAlgorithmicMedia')));
        $given = $this->runner()->run(new CreateMedium($this->upload('trainedAlgorithmicMedia'), ai: AiOrigin::None));
        $photo = $this->runner()->run(new CreateMedium($this->upload('digitalCapture')));

        self::assertSame('generated', $generated->ai);
        self::assertSame('edited', $edited->ai);
        self::assertSame('none', $given->ai, 'An input wins.');
        self::assertSame('none', $photo->ai, 'A photo from a camera has no label.');
        self::assertSame('generated', $this->get(Media::class)->getById($generated->id)->ai);

        $page = $this->runner()->ask(new ListMedia(ai: true));
        self::assertEqualsCanonicalizing([$generated->id, $edited->id], array_map(static fn($item): string => $item->id, $page->items));
    }

    public function testReplacingSetsTheLabelOfTheNewFileButTakesNoneAway(): void
    {
        $medium = $this->runner()->run(new CreateMedium($this->upload('digitalCapture')));

        $replaced = $this->runner()->run(new ReplaceMedium($medium->id, $this->upload('compositeWithTrainedAlgorithmicMedia')));
        self::assertSame('edited', $replaced->ai);

        $again = $this->runner()->run(new ReplaceMedium($medium->id, $this->upload('digitalCapture')));
        self::assertSame('edited', $again->ai, 'A file without a label keeps the one of the medium.');
    }

    public function testVisitorsSeeABadgeAndTheLabelOfAPublicMediumWaitsInTheDraft(): void
    {
        $medium = $this->runner()->run(new CreateMedium($this->upload('digitalCapture')));
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Bild'], 'areas' => ['main' => [
            ['block' => 'acme/media/Figure', 'fields' => ['image' => ['media' => $medium->id]]],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));

        $plain = $this->startPage();
        self::assertStringNotContainsString('ai-image', $plain);
        self::assertStringNotContainsString('ai.css', $plain, 'The style comes only with a badge.');

        $draft = $this->runner()->run(new UpdateMedium($medium->id, ai: AiOrigin::Generated));
        self::assertTrue($draft->draft, 'Visitors see the label: it waits in the draft.');
        self::assertSame('generated', $draft->ai);
        self::assertStringNotContainsString('ai-image', $this->startPage());

        $this->runner()->run(new PublishMedia([$medium->id]));
        $labelled = $this->startPage();
        self::assertMatchesRegularExpression('#<figure class="figure"><span class="ai-image" data-fb-ai="generated"><picture>.+</picture><span class="ai-image__badge"><span aria-hidden="true">AI</span><span class="ai-image__label">Generated with AI</span></span></span></figure>#', $labelled);
        self::assertStringContainsString('ai.css', $labelled);

        $back = $this->runner()->run(new UpdateMedium($medium->id, ai: AiOrigin::Generated));
        self::assertFalse($back->draft, 'The same as published needs no draft.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function startPage(): string
    {
        $this->get(Urls::class)->clear();

        return (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();
    }

    /**
     * A JPEG whose XMP names an IPTC DigitalSourceType, as an upload.
     */
    private function upload(string $source): string
    {
        $image = imagecreatetruecolor(400, 300);
        // Each image differs, or the library would find it again (decision 0146).
        imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $xmp = 'http://ns.adobe.com/xap/1.0/' . "\0"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" Iptc4xmpExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/' . $source . '"/>'
            . '</rdf:RDF></x:xmpmeta>';
        // An APP1 segment right after the start of the image.
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', \strlen($xmp) + 2) . $xmp . substr($jpeg, 2);

        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.jpg');
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, $jpeg);

        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, (int) filesize($file), \UPLOAD_ERR_OK, $source . '.jpg')]);

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor)['upload'];
    }
}
