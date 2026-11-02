<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Heading\Headings;
use Frootbox\Core\Heading\Outline;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\I18n\Labels;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\Head;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment as Twig;
use Twig\Markup;

/**
 * The design page (decision 0205): everything the active theme brings, in
 * the frame of the website and in the draft, for agencies to approve. Not
 * a page of the tree, never cached, never indexed. Only for those who may
 * see the design and guests with a preview link; for all others it does
 * not exist.
 */
final readonly class DesignPage implements RequestHandlerInterface
{
    public const string ROUTE = '/_design';

    // The head of the page gets the request with the attribute DesignPage::class set to true,
    // e.g. for the editor bar (decision 0207).

    private const string TEMPLATE = __DIR__ . '/../../resources/views/design.twig';

    /** Headings of the examples sit below "Blocks" and the group, none goes higher. */
    private const int BLOCK_LEVEL = 4;

    /** The groups of tokens by the start of their names, in order; the others follow by name. */
    private const array TOKEN_GROUPS = [
        'color' => 'Colours',
        'font' => 'Fonts',
        'leading' => 'Line heights',
        'size' => 'Font sizes',
        'space' => 'Spacing',
        'gutter' => 'Spacing',
        'width' => 'Widths',
        'radius' => 'Corners',
        'border' => 'Lines',
        'shadow' => 'Shadows',
    ];

    /** Plain and only for this page, so it does not lean on the theme. */
    private const string STYLE = <<<'CSS'
        .fb-design-section{display:grid;gap:1.5rem;padding-block:3rem}
        .fb-design-caption{margin:2.5rem 0 0;padding-block:.375rem;border-block-start:1px dashed currentColor;font:600 .75rem/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.02em;opacity:.7}
        .fb-design-swatches{display:grid;grid-template-columns:repeat(auto-fill,minmax(10rem,1fr));gap:1rem;margin:0;padding:0;list-style:none}
        .fb-design-swatch{display:grid;gap:.25rem;font-size:.8125rem}
        .fb-design-swatch span{display:block;block-size:3.5rem;border:1px solid #8888;border-radius:.375rem}
        .fb-design-swatch code,.fb-design-tokens code{overflow-wrap:anywhere}
        .fb-design-tokens{inline-size:100%;border-collapse:collapse;font-size:.875rem}
        .fb-design-tokens :is(th,td){padding:.375rem .75rem .375rem 0;border-block-end:1px solid #8884;text-align:start;vertical-align:top}
        .fb-design-missing{margin:0;padding-inline-start:1.25rem}
        CSS;

    public function __construct(
        private Components $components,
        private BlockGroups $groups,
        private ComponentRenderer $renderer,
        private FieldTypes $types,
        private ThemeChain $themes,
        private DesignTokens $tokens,
        private Languages $languages,
        private Translator $translator,
        private Labels $labels,
        private ExampleContent $examples,
        private EditMode $editMode,
        private Delivery $delivery,
        private Document $document,
        private Twig $twig,
        private Gate $gate,
        private Catalog $catalog,
        private CurrentContext $context,
        private Config $config,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $layout = $this->layout();

        if (!$this->allowed($request) || null === $layout) {
            throw HttpException::notFound($path);
        }

        $language = $this->language($request);
        // The draft like the editing mode, without marks to change it.
        $this->editMode->set(true);
        $this->delivery->set($language);

        $t = fn(string $english): string => $this->translator->in($language->code, $english);
        $assets = new AssetList();
        $assets->addStyle(self::STYLE);
        $title = $t('Design');

        $html = $this->renderer->layout(Instance::fromArray(['layout' => $layout->name, 'fields' => $this->layoutFields($layout, $title, $t('Everything this website is made of, in the draft.'))], ComponentKind::Layout), $assets, $title);
        $body = $this->twig->render(self::TEMPLATE, [
            't' => $t,
            'tokens' => $this->tokenGroups($t),
            'richText' => $this->types->get('richtext')->output(RichTextSample::DOCUMENT, new Field('sample', 'richtext', 'Text')),
            'groups' => $this->blocks($assets, $language, $t),
        ]);
        $html = self::intoMain($html, $body);

        $head = new Head($language->code, $title . ' – ' . $this->config->string('app.name', 'Frootbox'), direction: $language->direction->value);
        $head->add('<meta name="robots" content="noindex">');

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withBody($this->streams->createStream($this->document->render($head, $html, $assets, $request->withAttribute(self::class, true))));
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        if ($request->getAttribute(PreviewAccess::class) instanceof PreviewAccess) {
            return true;
        }

        return $this->catalog->has('design.show') && $this->gate->allows($this->context->get()->actor, $this->catalog->get('design.show'));
    }

    private function language(ServerRequestInterface $request): Language
    {
        $code = $request->getQueryParams()['language'] ?? null;

        return \is_string($code) && $this->languages->has($code) ? $this->languages->get($code) : $this->languages->default();
    }

    /**
     * The layout "Page" of the active theme, else the first it offers.
     */
    private function layout(): ?Component
    {
        $offered = array_values(array_filter(
            $this->components->all(ComponentKind::Layout),
            fn(Component $layout): bool => $this->components->offers(ComponentKind::Layout, $layout->name),
        ));
        $pages = array_values(array_filter($offered, static fn(Component $layout): bool => str_ends_with($layout->name, '/Page')));
        $chain = array_flip(array_map(static fn($theme): string => $theme->name, $this->themes->themes));

        // The most specific theme first.
        usort($pages, static fn(Component $a, Component $b): int => ($chain[$a->package()] ?? \PHP_INT_MAX) <=> ($chain[$b->package()] ?? \PHP_INT_MAX));

        return $pages[0] ?? $offered[0] ?? null;
    }

    /**
     * Title and introduction where the layout has such fields.
     *
     * @return array<string, string>
     */
    private function layoutFields(Component $layout, string $title, string $intro): array
    {
        $fields = [];

        if (isset($layout->fields['title']) && \in_array($layout->fields['title']->type, ['headline', 'text'], true)) {
            $fields['title'] = $title;
        }

        if (isset($layout->fields['intro']) && 'text' === $layout->fields['intro']->type) {
            $fields['intro'] = $intro;
        }

        return $fields;
    }

    /**
     * The tokens of the theme with the values of the draft, by group.
     *
     * @param \Closure(string): string $t
     *
     * @return list<array{title: string, colors: bool, values: array<string, string>}>
     */
    private function tokenGroups(\Closure $t): array
    {
        $values = [...$this->themes->tokens(), ...$this->tokens->overrides(true)];
        $order = array_flip(array_unique(array_values(self::TOKEN_GROUPS)));
        $groups = [];

        foreach ($values as $name => $value) {
            $start = explode('-', $name, 2)[0];
            $groups[self::TOKEN_GROUPS[$start] ?? $start][$name] = $value;
        }

        uksort($groups, static fn(string $a, string $b): int => [$order[$a] ?? \count($order), $a] <=> [$order[$b] ?? \count($order), $b]);
        $list = [];

        foreach ($groups as $title => $members) {
            $list[] = ['title' => isset($order[$title]) ? $t($title) : $title, 'colors' => 'Colours' === $title, 'values' => $members];
        }

        return $list;
    }

    /**
     * Each offered block in each variant with its example, by group of the
     * picker (decision 0148); blocks without an example come last.
     *
     * @param \Closure(string): string $t
     *
     * @return list<array{title: string, blocks: list<array{caption: string, html: Markup}>, missing: list<string>}>
     */
    private function blocks(AssetList $assets, Language $language, \Closure $t): array
    {
        $blocks = array_filter(
            $this->components->all(ComponentKind::Block),
            fn(Component $block): bool => $this->components->offers(ComponentKind::Block, $block->name),
        );
        $known = array_map(static fn($group): string => $group->name, $this->groups->all());
        $groups = [];

        foreach ([...$this->groups->all(), null] as $group) {
            $members = array_filter($blocks, static fn(Component $block): bool => null === $group ? !\in_array($block->group, $known, true) : $block->group === $group->name);

            if ([] === $members) {
                continue;
            }

            $groupTitle = null === $group ? $t('Other') : $t($group->title);
            $shown = [];
            $missing = [];

            foreach ($members as $block) {
                $labelled = $this->labels->component($block);

                if ([] === $block->examples) {
                    $missing[] = $labelled->title;

                    continue;
                }

                foreach ($labelled->variants as $variant) {
                    foreach ($block->examples as $example) {
                        if (isset($example['variant']) && $example['variant'] !== $variant->name) {
                            continue;
                        }

                        $options = \is_array($example['options'] ?? null) ? $example['options'] : [];
                        $shown[] = [
                            'caption' => implode(' · ', array_unique([$groupTitle, $labelled->title, $variant->title, ...self::chosen($variant, $options)])),
                            'html' => new Markup($this->render($block, $variant->name, $example, $language, $assets, $t), 'UTF-8'),
                        ];
                    }
                }
            }

            $groups[] = ['title' => $groupTitle, 'blocks' => $shown, 'missing' => $missing];
        }

        return $groups;
    }

    /**
     * @param \Closure(string): string $t
     */
    /**
     * The options an example sets, by their labels, e.g. "Size: Large".
     *
     * @param array<mixed> $options
     *
     * @return list<string>
     */
    private static function chosen(Variant $variant, array $options): array
    {
        $chosen = [];

        foreach ($options as $name => $value) {
            $option = $variant->options[$name] ?? null;

            if (null === $option) {
                continue;
            }

            $text = match (true) {
                \is_bool($value) => $value ? '✓' : '–',
                (\is_string($value) || \is_int($value)) && isset($option->choices[$value]) => $option->choices[$value],
                \is_scalar($value) => (string) $value,
                default => '',
            };
            $chosen[] = $option->label . ': ' . $text;
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed>     $example
     * @param \Closure(string): string $t
     */
    private function render(Component $block, string $variant, array $example, Language $language, AssetList $assets, \Closure $t): string
    {
        try {
            $instance = Instance::fromArray($this->examples->block($block, $variant, $example, $language->code));

            return $this->renderer->block($instance, $assets, new Headings(new Outline(), self::BLOCK_LEVEL, floor: self::BLOCK_LEVEL));
        } catch (Throwable $exception) {
            // One broken example must not hide the rest of the page.
            $this->logger->error('The example of {block} cannot be shown: {message}', ['block' => $block->name, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return '<p class="fb-design-caption">' . htmlspecialchars(strtr($t('This block cannot be shown: {message}'), ['{message}' => $exception->getMessage()])) . '</p>';
        }
    }

    /**
     * The content goes at the end of <main>, so blocks stand in the grid
     * of the page like on any other.
     */
    private static function intoMain(string $html, string $body): string
    {
        $end = strripos($html, '</main>');

        return false === $end ? $html . $body : substr($html, 0, $end) . $body . substr($html, $end);
    }
}
