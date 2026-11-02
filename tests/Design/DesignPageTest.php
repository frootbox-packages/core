<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Design;

use DateTimeImmutable;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Design\DesignPage;
use Frootbox\Core\Design\RichTextSample;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

/**
 * The design page shows what the theme brings, in the frame of the
 * website, only to those who may see the design (decision 0205).
 */
#[CoversClass(DesignPage::class)]
#[CoversClass(RichTextSample::class)]
#[CoversClass(DefinitionReader::class)]
#[CoversClass(ComponentLoader::class)]
final class DesignPageTest extends NotesTestCase
{
    private TemporaryProject $packages;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->packages->remove();
    }

    public function testShowsEverythingTheThemeBrings(): void
    {
        $response = $this->request('/_design');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('<header>Head</header>', $html, 'In the frame of the layout Page.');
        self::assertMatchesRegularExpression('/<main>\s*<h1>Design<\/h1>/', $html);

        self::assertStringContainsString('<span style="background: var(--color-primary)"></span><code>color-primary</code><code>#c00</code>', $html, 'Colours as swatches of the variable.');
        self::assertStringContainsString('<strong>bold</strong>', $html, 'Rich text through the renderer.');

        self::assertStringContainsString('<p class="fb-design-caption">Headers · Hero</p>', $html);
        self::assertStringContainsString('<section class="hero"><h4>Fresh coffee</h4></section>', $html, 'The example in each variant, its headings below the group.');
        self::assertStringContainsString('<p class="fb-design-caption">Headers · Hero · Split</p>', $html);
        self::assertStringContainsString('<section class="hero hero--split"><h4>Fresh coffee</h4></section>', $html);
        self::assertMatchesRegularExpression('/Without an example yet:<\/p>\s*<ul class="fb-design-missing">\s*<li>Note<\/li>/', $html);
        self::assertStringNotContainsString('Secret', $html, 'Blocks the theme hides are not there.');
        self::assertStringContainsString('This block cannot be shown:', $html, 'A broken example does not hide the rest.');
        self::assertLessThan(strpos($html, '</main>'), strpos($html, 'Fresh coffee'), 'The content stands in <main>.');
    }

    public function testDoesNotExistForGuests(): void
    {
        $this->get(CurrentContext::class)->set(Context::guest());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->request('/_design');
    }

    public function testOpensForAPreviewLink(): void
    {
        $this->get(CurrentContext::class)->set(Context::guest());
        $request = new ServerRequest('GET', 'https://example.org/_design')->withAttribute(PreviewAccess::class, new PreviewAccess('01JPREVIEW0000000000000000', new DateTimeImmutable('+1 day')));

        self::assertSame(200, $this->get(DesignPage::class)->handle($request)->getStatusCode());
    }

    public function testChecksTheShapeOfAnExample(): void
    {
        file_put_contents($this->packages->root . '/kit/blocks/Note/views/Default.twig', "{# frootbox\ntitle: Note\nexample: { text: Hi }\n#}<p>Note</p>");

        try {
            $this->get(Components::class);
            self::fail('A wrong example is an error of the block.');
        } catch (ComponentException $exception) {
            self::assertStringContainsString('example.text: unknown key. Allowed: fields, areas, options', $exception->getMessage());
        }
    }

    protected function extensions(): Extensions
    {
        $this->packages = TemporaryProject::create();
        $kit = $this->packages->root . '/kit';
        $look = $this->packages->root . '/look';
        $files = [
            $kit . '/frootbox.yaml' => "title: Kit\ntheme:\n  tokens:\n    color:\n      primary: '#c00'\n",
            $kit . '/layouts/Page/views/Default.twig' => "{# frootbox\ntitle: Page\nfields:\n  title: { type: headline }\nareas:\n  main: {}\n#}<header>Head</header><main>{{ heading('title', 0) }}{{ area('main') }}</main>",
            $kit . '/blocks/Hero/views/Default.twig' => "{# frootbox\ntitle: Hero\ngroup: header\nfields:\n  title: { type: headline }\nexample:\n  fields: { title: 'Fresh coffee' }\n#}<section class=\"hero\">{{ heading('title', 0) }}</section>",
            $kit . '/blocks/Hero/views/Split.twig' => "{# frootbox\ntitle: Split\n#}<section class=\"hero hero--split\">{{ heading('title', 0) }}</section>",
            $kit . '/blocks/Note/views/Default.twig' => "{# frootbox\ntitle: Note\ngroup: text\n#}<p>Note</p>",
            $kit . '/blocks/Secret/views/Default.twig' => "{# frootbox\ntitle: Secret\nexample: { fields: {} }\n#}<p>Secret</p>",
            $kit . '/blocks/Broken/views/Default.twig' => "{# frootbox\ntitle: Broken\nexample: { fields: {} }\n#}{{ area('nowhere') }}",
            $look . '/frootbox.yaml' => "title: Look\ntheme:\n  parent: acme/kit\n  blocks:\n    hide: [acme/kit/Secret]\n",
        ];

        foreach ($files as $file => $content) {
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, $content);
        }

        return new Extensions([
            ExtensionPackage::fromManifest('acme/kit', $kit),
            ExtensionPackage::fromManifest('acme/look', $look),
        ]);
    }

    protected function config(): array
    {
        return ['theme' => 'acme/look'];
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(DesignPage::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }
}
