<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use DateTimeImmutable;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Auth\AccessToken;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Authentication;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Images;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\View\Scope;
use Frootbox\Core\View\ViewException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Markup;

/**
 * Caption and copyright of images (decision 0203).
 */
#[CoversClass(ImageType::class)]
#[CoversClass(Images::class)]
#[CoversClass(MediaEditor::class)]
#[CoversClass(Scope::class)]
#[CoversClass(UpdatePageField::class)]
final class CaptionAndCopyrightTest extends NotesTestCase
{
    private int $users = 0;

    public function testTheCopyrightComesFromTheFileUnlessGiven(): void
    {
        $fromFile = $this->medium('hafen.jpg', iptc: [116 => '© Anna Muster']);
        $byline = $this->medium('roesterei.jpg', iptc: [80 => 'Ben Beispiel', 110 => 'Bildagentur']);
        $given = $this->runner()->run(new CreateMedium($this->upload('bohnen.jpg', [116 => 'Aus der Datei']), copyright: '  Eigene   Angabe '));
        $none = $this->medium('ohne.jpg');

        self::assertSame('© Anna Muster', $fromFile->copyright);
        self::assertSame('Ben Beispiel / Bildagentur', $byline->copyright);
        self::assertSame('Eigene Angabe', $given->copyright, 'An input wins and is one clean line.');
        self::assertSame('', $none->copyright);
        self::assertSame('© Anna Muster', $this->get(Media::class)->getById($fromFile->id)->copyright);
    }

    public function testTheCopyrightOfAPublicMediumWaitsInTheDraft(): void
    {
        $medium = $this->medium('hafen.jpg');
        $changed = $this->runner()->run(new UpdateMedium($medium->id, copyright: 'Anna Muster'));
        self::assertSame('Anna Muster', $changed->copyright);
        self::assertFalse($changed->draft, 'Nobody sees a medium that is not public: no draft.');

        $page = $this->page(['media' => $medium->id]);
        $this->runner()->run(new PublishPages([$page]));
        self::assertStringContainsString('<small>Anna Muster</small>', $this->startPage(false));

        $draft = $this->runner()->run(new UpdateMedium($medium->id, copyright: 'Bildagentur'));
        self::assertTrue($draft->draft);
        self::assertSame('Bildagentur', $draft->copyright);
        self::assertSame('Anna Muster', $this->get(Media::class)->getById($medium->id)->copyright);
        self::assertStringContainsString('<small>Anna Muster</small>', $this->startPage(false), 'Visitors see the published copyright.');
        self::assertMatchesRegularExpression('#data-fb-part="copyright"[^>]*>Bildagentur<#', $this->startPage(true), 'The editing mode shows the draft.');

        $back = $this->runner()->run(new UpdateMedium($medium->id, copyright: 'Anna Muster'));
        self::assertFalse($back->draft, 'The same as published needs no draft.');

        $this->runner()->run(new UpdateMedium($medium->id, copyright: 'Bildagentur'));
        $this->runner()->run(new PublishMedia([$medium->id]));
        self::assertSame('Bildagentur', $this->get(Media::class)->getById($medium->id)->copyright);
    }

    public function testCaptionAndCopyrightArePartsOfTheField(): void
    {
        $medium = $this->runner()->run(new CreateMedium($this->upload('hafen.jpg'), copyright: 'Anna Muster'));
        $page = $this->page(['media' => $medium->id, 'caption' => 'Am Hafen']);
        $this->runner()->run(new PublishPages([$page]));

        self::assertMatchesRegularExpression('#<figure class="figure"><picture>.+</picture><figcaption><span>Am Hafen</span><small>Anna Muster</small></figcaption></figure>#', $this->startPage(false));

        // The copyright of this place wins over the one of the medium.
        $updated = $this->runner()->run(new UpdatePageField($page, 'de', 'image', ['media' => $medium->id, 'copyright' => 'Nur hier'], ['media' => $medium->id, 'caption' => 'Am Hafen'], $this->blockId($page)));
        self::assertSame(['caption' => '', 'copyright' => 'Nur hier'], $updated->parts, 'Saving gives the parts for the editing mode.');

        $editing = $this->startPage(true);
        self::assertMatchesRegularExpression('#<span data-fb-field="image" data-fb-type="image" data-fb-label="[^"]*" data-fb-optional data-fb-parts="caption copyright" data-fb-part="caption" tabindex="0" data-fb-value="[^"]+"></span>#', $editing, 'An empty part keeps its place while editing.');
        self::assertMatchesRegularExpression('#<small data-fb-field="image"[^>]* data-fb-part="copyright"[^>]*>Nur hier</small>#', $editing);

        // Without caption and copyright the figcaption goes for visitors.
        $plain = $this->runner()->run(new CreateMedium($this->upload('ohne.jpg')));
        $this->runner()->run(new UpdatePageField($page, 'de', 'image', ['media' => $plain->id], ['media' => $medium->id, 'copyright' => 'Nur hier'], $this->blockId($page)));
        $this->runner()->run(new PublishPages([$page]));
        self::assertMatchesRegularExpression('#<figure class="figure"><picture>.+</picture></figure>#', $this->startPage(false));
    }

    public function testAPartTheFieldDoesNotOfferIsAnErrorInTheTemplate(): void
    {
        $scope = new Scope(['plain' => new Field('plain', 'image', 'Plain'), 'title' => new Field('title', 'text', 'Title')], [], $this->get(FieldTypes::class));

        $empty = $scope->field('plain', 'copyright');
        self::assertInstanceOf(Markup::class, $empty);
        self::assertSame('<!--fb:empty-->', (string) $empty, 'Without a medium there is no copyright.');

        try {
            $scope->field('plain', 'caption');
            self::fail('A caption needs "caption: true".');
        } catch (ViewException $exception) {
            self::assertStringContainsString('has no part "caption". Parts: copyright (a caption needs "caption: true" in the field)', $exception->getMessage());
        }

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('The field "title" has no part "caption". Parts: none.');
        $scope->field('title', 'caption');
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
     * @param array<string, mixed> $image
     *
     * @return string The id of the page
     */
    private function page(array $image): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Bild'], 'areas' => ['main' => [
            ['block' => 'acme/media/Figure', 'fields' => ['image' => $image]],
        ]]]]]))->id;
    }

    /** The id of the first block; MySQL writes JSON with a space after the colon. */
    private function blockId(string $page): string
    {
        $content = $this->connection()->fetchOne('SELECT data FROM revisions WHERE entity_id = ? AND status = ?', [$page, 'draft']);
        $content = \is_string($content) ? $content : $this->connection()->fetchOne('SELECT content FROM page_translations WHERE page_id = ?', [$page]);
        self::assertIsString($content);
        self::assertSame(1, preg_match('/"id":\s*"([^"]+)"/', $content, $match));

        return $match[1];
    }

    /**
     * The start page as a guest or in the editing mode of an editor.
     */
    private function startPage(bool $editing): string
    {
        $this->get(Urls::class)->clear();
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org/');

        if ($editing) {
            $user = $this->runner()->run(new CreateUser('caption' . ++$this->users . '@example.com', 'User', roles: ['editor']), Context::system())->user;
            $token = new AccessToken(1, $user->id, TokenType::Session, null, null, null, new DateTimeImmutable());
            $request = $request->withCookieParams([EditMode::COOKIE => '1'])->withAttribute(Authentication::class, new Authentication(Actor::user($user->id, $user->name), $token));
        }

        try {
            return (string) $this->get(PageHandler::class)->handle($request)->getBody();
        } finally {
            $this->get(EditMode::class)->set(false);
        }
    }

    /**
     * @param array<int<0, 255>, string> $iptc
     */
    private function medium(string $name, array $iptc = []): MediumDetails
    {
        return $this->runner()->run(new CreateMedium($this->upload($name, $iptc)));
    }

    /**
     * A JPEG, with IPTC data if given, as an upload.
     *
     * @param array<int<0, 255>, string> $iptc Datasets of the application record, e.g. [116 => "© Anna Muster"] for the copyright notice
     */
    private function upload(string $name, array $iptc = []): string
    {
        $image = imagecreatetruecolor(400, 300);
        // Each image differs, or the library would find it again (decision 0146).
        imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.jpg');
        @mkdir(\dirname($file), 0o775, true);
        imagejpeg($image, $file, 90);

        if ([] !== $iptc) {
            $data = '';

            foreach ($iptc as $dataset => $value) {
                $data .= \chr(0x1C) . \chr(2) . \chr($dataset) . pack('n', \strlen($value)) . $value;
            }

            $embedded = iptcembed($data, $file);
            self::assertIsString($embedded);
            file_put_contents($file, $embedded);
        }

        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, (int) filesize($file), \UPLOAD_ERR_OK, $name)]);

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor)['upload'];
    }
}
