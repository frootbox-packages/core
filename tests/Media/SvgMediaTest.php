<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SVG graphics in the media library (decision 0182).
 */
#[CoversClass(ImageType::class)]
#[CoversClass(MediaHandler::class)]
#[CoversClass(UploadReceiver::class)]
final class SvgMediaTest extends NotesTestCase
{
    private const string LOGO = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 100" onload="alert(1)"><script>alert(2)</script><rect width="400" height="100" fill="#1d4ed8"/></svg>';

    public function testStoresOnlyTheCleanGraphic(): void
    {
        $logo = $this->upload(self::LOGO, 'Logo Rösterei.svg', ['de' => ['alt' => 'Bohnenglück', 'title' => '']]);

        self::assertSame('image', $logo->kind);
        self::assertSame('image/svg+xml', $logo->type);
        self::assertSame([400, 100], [$logo->width, $logo->height]);
        self::assertMatchesRegularExpression('#^/media/' . $logo->id . '/1/logo-roesterei-main-[0-9a-f]{16}\.svg$#', $logo->preview);

        $stored = (string) file_get_contents($this->get(MediaFiles::class)->original($this->get(Media::class)->getById($logo->id)->original()));
        self::assertStringNotContainsString('alert', $stored);
        self::assertStringContainsString('<rect width="400" height="100" fill="#1d4ed8"/>', $stored);
        self::assertContains($logo->id, array_map(static fn(MediumDetails $item): string => $item->id, $this->runner()->ask(new ListMedia(kind: 'image'))->items));
    }

    public function testRefusesAGraphicWithNothingToShow(): void
    {
        try {
            $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'leer.svg');
            self::fail('Expected invalid input.');
        } catch (InvalidInputException $error) {
            self::assertSame('The SVG cannot be read or holds nothing that can be shown.', $error->messages['/upload']->text ?? null);
        }
    }

    public function testShowsAnSvgAsOneImageAndCutsItWithCss(): void
    {
        $logo = $this->upload(self::LOGO, 'logo.svg', ['de' => ['alt' => 'Bohnenglück', 'title' => '']]);
        $html = $this->publishWith(['cover' => ['media' => $logo->id], 'hero' => ['media' => $logo->id]]);

        // The cover has the ratio 16:9, the hero none.
        self::assertStringContainsString('<img src="' . $logo->preview . '" width="400" height="225" alt="Bohnenglück" loading="lazy" decoding="async" style="aspect-ratio: 400 / 225; object-fit: cover">', $html);
        self::assertStringContainsString('<img src="' . $logo->preview . '" width="400" height="100" alt="Bohnenglück" loading="eager" fetchpriority="high" decoding="async">', $html);
        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('srcset', $html);
    }

    public function testDeliversAnSvgThatCannotRun(): void
    {
        $logo = $this->upload(self::LOGO, 'logo.svg');
        $editor = $this->deliver($logo->preview, $this->token());

        self::assertSame(200, $editor->getStatusCode());
        self::assertSame('image/svg+xml', $editor->getHeaderLine('Content-Type'));
        self::assertSame(MediaHandler::SVG_POLICY, $editor->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $editor->getHeaderLine('X-Content-Type-Options'));
        self::assertStringNotContainsString('alert', (string) $editor->getBody());

        // No generated sizes of a graphic.
        $sized = (string) preg_replace('#-main-[0-9a-f]{16}\.svg$#', '-400x0-0000000000000000.png', $logo->preview);
        self::assertSame(404, $this->deliver($sized, $this->token())->getStatusCode());

        $this->publishWith(['hero' => ['media' => $logo->id]]);
        self::assertSame(200, $this->deliver($logo->preview)->getStatusCode());
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
     * @param array<mixed> $texts
     */
    private function upload(string $bytes, string $name, array $texts = []): MediumDetails
    {
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.svg');
        @mkdir(\dirname($file), 0o775, true);
        file_put_contents($file, $bytes);
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, \strlen($bytes), \UPLOAD_ERR_OK, $name)]);
        $upload = $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);

        return $this->runner()->run(new CreateMedium($upload['upload'], texts: $texts));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function publishWith(array $fields): string
    {
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Logo'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => $fields],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));
        $this->get(Urls::class)->clear();

        return (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();
    }

    private function token(): string
    {
        $user = $this->runner()->run(new CreateUser('svg' . bin2hex(random_bytes(4)) . '@example.com', 'User', roles: ['editor']), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);

        return $this->send(null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
    }

    private function send(ServerRequestInterface $request): ResponseInterface
    {
        $this->get(CurrentContext::class)->set(Context::guest());
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }
}
