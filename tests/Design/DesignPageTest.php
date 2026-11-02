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
use Frootbox\Core\Design\ExampleContent;
use Frootbox\Core\Design\RichTextSample;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Sample;
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
#[CoversClass(ExampleContent::class)]
#[CoversClass(Sample::class)]
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

        self::assertStringContainsString('<section class="fb-design-section" id="fb-design-group-header" aria-label="Headers">', $html, 'Each group has an address of its own.');
        self::assertStringContainsString('<p class="fb-design-caption">Headers · Hero</p>', $html);
        self::assertStringContainsString('<section class="hero"><h4>Fresh coffee</h4></section>', $html, 'The example in each variant, its headings below the group.');
        self::assertStringContainsString('<p class="fb-design-caption">Headers · Hero · Split</p>', $html);
        self::assertStringContainsString('<section class="hero hero--split"><h4>Fresh coffee</h4></section>', $html);
        self::assertSame(1, substr_count($html, 'Roasted next door'), 'An example for one variant shows only there.');
        self::assertStringContainsString('<p class="fb-design-caption">Headers · Hero · Split · Image: At the end</p>', $html, 'The caption names the options it sets.');
        self::assertMatchesRegularExpression('#<img src="/assets/acme/look/assets/examples/beans\.png" width="1" height="1" alt="Beans"[^>]*>#', $html, 'A file of the child stands in for a medium.');
        self::assertMatchesRegularExpression('/Without an example yet:<\/p>\s*<ul class="fb-design-missing">\s*<li>Note<\/li>/', $html);
        self::assertStringNotContainsString('Secret', $html, 'Blocks the theme hides are not there.');
        self::assertStringContainsString('This block cannot be shown:', $html, 'A broken example does not hide the rest.');
        self::assertLessThan(strpos($html, '</main>'), strpos($html, 'Fresh coffee'), 'The content stands in <main>.');
    }

    public function testTranslatesTheExamplesThroughTheThemes(): void
    {
        $html = (string) $this->request('/_design?language=de')->getBody();

        self::assertStringContainsString('<h4>Frischer Kaffee</h4>', $html);
        self::assertStringContainsString('alt="Bohnen"', $html);
        self::assertStringContainsString('<h4>Roasted next door</h4>', $html, 'Without a translation it stays as written.');
    }

    public function testShowsTheBlocksAtAPlaceOnASurface(): void
    {
        // Decision 0208: a place and a surface of the same block are one frame.
        $html = (string) $this->request('/_design?place=column&surface=dark')->getBody();

        self::assertStringContainsString('<div class="columns columns--dark"><section class="hero"><h4>Fresh coffee</h4></section></div>', $html);
        self::assertStringContainsString('<p class="fb-design-caption">Blocks · In a column · On dark</p>', $html);
        self::assertMatchesRegularExpression('/Not at this place:<\/p>\s*<ul class="fb-design-missing">\s*<li>Broken<\/li>/', $html, 'The area does not allow it.');
        self::assertStringContainsString('<dd><a href="/_design?surface=dark">Main area</a></dd>', $html, 'Each link keeps the rest of the view.');
        self::assertStringContainsString('<dd><a href="/_design?place=column&amp;surface=dark" aria-current="page">On dark</a></dd>', $html);
        self::assertStringContainsString('<dd><a href="/_design?language=de&amp;place=column&amp;surface=dark">Deutsch</a></dd>', $html);
    }

    public function testSetsTheDirectionAndShowsTheOwnParts(): void
    {
        $html = (string) $this->request('/_design?direction=rtl')->getBody();

        self::assertStringContainsString('<html lang="en" dir="rtl">', $html, 'The mirror with known texts.');
        self::assertMatchesRegularExpression('/<h2>States<\/h2>\s*<div><p class="states">Hover and focus<\/p>/', $html, 'A part of the child theme.');
        self::assertLessThan(strpos($html, 'fb-design-blocks-title'), strpos($html, 'Hover and focus'), 'Before the blocks.');
    }

    public function testNamesAWrongFrame(): void
    {
        $html = (string) $this->request('/_design?place=lost')->getBody();

        self::assertStringContainsString('<p role="alert">The frame &quot;lost&quot; of acme/kit names the block acme/kit/Nothing, which does not exist.</p>', $html);
        self::assertStringContainsString('<section class="hero"><h4>Fresh coffee</h4></section>', $html, 'The blocks stay in the main area.');
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

    public function testChecksTheExamples(): void
    {
        file_put_contents($this->packages->root . '/kit/blocks/Note/views/Default.twig', "{# frootbox\ntitle: Note\nexamples:\n  - { text: Hi }\n  - { variant: Wide }\n  - { fields: { nope: x }, options: { size: l } }\n#}<p>Note</p>");

        try {
            $this->get(Components::class);
            self::fail('Wrong examples are errors of the block.');
        } catch (ComponentException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('examples.0.text: unknown key. Allowed: variant, fields, areas, options', $message);
            self::assertStringContainsString('examples.1.variant: "Wide" is no variant. Variants: Default', $message);
            self::assertStringContainsString('examples.2.fields.nope: unknown. Known: none', $message);
            self::assertStringContainsString('examples.2.options.size: unknown. Known: none', $message);
        }
    }

    protected function extensions(): Extensions
    {
        $this->packages = TemporaryProject::create();
        $kit = $this->packages->root . '/kit';
        $look = $this->packages->root . '/look';
        $files = [
            $kit . '/frootbox.yaml' => "title: Kit\ntheme:\n  tokens:\n    color:\n      primary: '#c00'\n  design:\n    places:\n      column: { label: In a column, block: Columns, area: first }\n      lost: { label: Lost, block: Nothing, area: first }\n    surfaces:\n      dark: { label: On dark, block: Columns, options: { background: dark }, area: first }\n",
            $kit . '/blocks/Columns/views/Default.twig' => "{# frootbox\ntitle: Columns\ngroup: structure\noptions:\n  background: { type: choice, label: Background, choices: { none: None, dark: Dark }, default: none }\nareas:\n  first: { label: First, allow: [Hero, Note] }\n#}<div class=\"columns columns--{{ options.background }}\">{{ area('first') }}</div>",
            $kit . '/layouts/Page/views/Default.twig' => "{# frootbox\ntitle: Page\nfields:\n  title: { type: headline }\nareas:\n  main: {}\n#}<header>Head</header><main>{{ heading('title', 0) }}{{ area('main') }}</main>",
            $kit . '/translations/de.yaml' => "Fresh coffee: Frischer Kaffee\nBeans: Bohnen\n",
            $kit . '/blocks/Hero/views/Default.twig' => "{# frootbox\ntitle: Hero\ngroup: header\nfields:\n  title: { type: headline }\n  image: { type: image, optional: true }\nexamples:\n  - fields: { title: 'Fresh coffee' }\n  - variant: Split\n    options: { side: end }\n    fields: { title: 'Roasted next door', image: { sample: examples/beans.png, alt: Beans } }\n#}<section class=\"hero\">{{ heading('title', 0) }}</section>",
            $kit . '/blocks/Hero/views/Split.twig' => "{# frootbox\ntitle: Split\noptions:\n  side: { type: choice, label: Image, choices: { start: At the start, end: At the end }, default: start }\n#}<section class=\"hero hero--split\">{{ heading('title', 0) }}{{ field('image') }}</section>",
            $look . '/assets/examples/beans.png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true),
            $kit . '/blocks/Note/views/Default.twig' => "{# frootbox\ntitle: Note\ngroup: text\n#}<p>Note</p>",
            $kit . '/blocks/Secret/views/Default.twig' => "{# frootbox\ntitle: Secret\nexamples: [{}]\n#}<p>Secret</p>",
            $kit . '/blocks/Broken/views/Default.twig' => "{# frootbox\ntitle: Broken\nexamples: [{}]\n#}{{ area('nowhere') }}",
            $look . '/partials/design/States.twig' => '<p class="states">Hover and focus</p>',
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
        return ['theme' => 'acme/look', 'languages' => ['en' => ['title' => 'English'], 'de' => ['title' => 'Deutsch']]];
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(DesignPage::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }
}
