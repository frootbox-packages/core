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
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Preview\PreviewAccess;
use Frootbox\Core\Theme\Frame;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\View\ComponentRenderer;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\FileLoader;
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

    /** Own parts of the themes, partials/design/<Name>.twig (decision 0208). */
    private const string PARTS = 'design';

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
        .fb-design-view{display:grid;gap:.5rem;margin:0;font-size:.875rem}
        .fb-design-view div{display:flex;flex-wrap:wrap;gap:.25rem .75rem;align-items:baseline}
        .fb-design-view dt{font-weight:600}
        .fb-design-view dd{margin:0}
        .fb-design-view a[aria-current]{font-weight:600;text-decoration:none}
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
        $view = $this->view($request->getQueryParams(), $language, $t);
        $assets = new AssetList();
        $assets->addStyle(self::STYLE);
        $title = $t('Design');

        $html = $this->renderer->layout(Instance::fromArray(['layout' => $layout->name, 'fields' => $this->layoutFields($layout, $title, $t('Everything this website is made of, in the draft.'))], ComponentKind::Layout), $assets, $title);
        $body = $this->twig->render(self::TEMPLATE, [
            't' => $t,
            'tokens' => $this->tokenGroups($t),
            'richText' => $this->types->get('richtext')->output(RichTextSample::DOCUMENT, new Field('sample', 'richtext', 'Text')),
            'view' => $view,
            'parts' => $this->parts($language),
            'groups' => $this->blocks($assets, $language, $t, $view['frames']),
        ]);
        $html = self::intoMain($html, $body);

        $head = new Head($language->code, $title . ' – ' . $this->config->string('app.name', 'Frootbox'), direction: $view['direction'] ?? $language->direction->value);
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
     * @param list<Frame> $frames The block stands in the first, each in the next
     *
     * @return list<array{name: string, title: string, blocks: list<array{caption: string, html: Markup}>, missing: list<string>, elsewhere: list<string>}>
     */
    private function blocks(AssetList $assets, Language $language, \Closure $t, array $frames): array
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
            $elsewhere = [];

            foreach ($members as $block) {
                // Texts in the language of the page, like the rest of it.
                $label = fn(string $text): string => '' === $text ? '' : $this->translator->in($language->code, $text, [$block->package()]);
                $title = $label($block->title);

                if ([] === $block->examples) {
                    $missing[] = $title;

                    continue;
                }

                if ([] !== $frames && !$this->fits($block->name, $block->widget, $frames[0])) {
                    $elsewhere[] = $title;

                    continue;
                }

                foreach ($block->variants as $variant) {
                    foreach ($block->examples as $index => $example) {
                        if (isset($example['variant']) && $example['variant'] !== $variant->name) {
                            continue;
                        }

                        $options = \is_array($example['options'] ?? null) ? $example['options'] : [];
                        $caption = implode(' · ', array_unique([$groupTitle, $title, $label($variant->title), ...self::chosen($variant, $options, $label)]));
                        $html = $this->render($block, $variant->name, $example, $language, $assets, $t, $frames);
                        $shown[] = [
                            'caption' => $caption,
                            'html' => new Markup(self::mark($html, $block->name . '/' . $variant->name . '/' . $index, $caption), 'UTF-8'),
                        ];
                    }
                }
            }

            $groups[] = ['name' => $group->name ?? 'other', 'title' => $groupTitle, 'blocks' => $shown, 'missing' => $missing, 'elsewhere' => $elsewhere];
        }

        return $groups;
    }

    /**
     * Marks the outer element of an example as a place for comments
     * (decision 0210), without an element of its own around it.
     */
    private static function mark(string $html, string $key, string $title): string
    {
        $attributes = \sprintf(' data-fb-design="%s" data-fb-title="%s"', htmlspecialchars($key), htmlspecialchars($title));

        return (string) preg_replace('/^(\s*(?:<!--.*?-->\s*)*<[a-zA-Z][a-zA-Z0-9-]*)(?=[\s>\/])/s', '$1' . $attributes, $html, 1);
    }

    /**
     * The options an example sets, by their labels, e.g. "Size: Large".
     *
     * @param array<mixed>             $options
     * @param \Closure(string): string $label Translates a text of the block
     *
     * @return list<string>
     */
    private static function chosen(Variant $variant, array $options, \Closure $label): array
    {
        $chosen = [];

        foreach ($options as $name => $value) {
            $option = $variant->options[$name] ?? null;

            if (null === $option) {
                continue;
            }

            $text = match (true) {
                \is_bool($value) => $value ? '✓' : '–',
                (\is_string($value) || \is_int($value)) && isset($option->choices[$value]) => $label($option->choices[$value]),
                \is_scalar($value) => (string) $value,
                default => '',
            };
            $chosen[] = $label($option->label) . ': ' . $text;
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed>     $example
     * @param \Closure(string): string $t
     * @param list<Frame>              $frames
     */
    private function render(Component $block, string $variant, array $example, Language $language, AssetList $assets, \Closure $t, array $frames = []): string
    {
        try {
            $data = $this->examples->block($block, $variant, $example, $language->code);

            foreach ($frames as $frame) {
                $data = ['block' => $frame->block, 'variant' => $frame->variant, 'options' => $frame->options, 'fields' => [], 'areas' => [$frame->area => [$data]]];
            }

            $instance = Instance::fromArray($data);

            return $this->renderer->block($instance, $assets, new Headings(new Outline(), self::BLOCK_LEVEL, floor: self::BLOCK_LEVEL));
        } catch (Throwable $exception) {
            // One broken example must not hide the rest of the page.
            $this->logger->error('The example of {block} cannot be shown: {message}', ['block' => $block->name, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return '<p class="fb-design-caption">' . htmlspecialchars(strtr($t('This block cannot be shown: {message}'), ['{message}' => $exception->getMessage()])) . '</p>';
        }
    }

    /**
     * The view the address asks for (decision 0208): language, direction,
     * place and surface, with links to every other choice.
     *
     * @param array<mixed>             $query
     * @param \Closure(string): string $t
     *
     * @return array{frames: list<Frame>, direction: ?string, nav: list<array{label: string, links: list<array{label: string, url: string, current: bool}>}>, current: string, errors: list<string>}
     */
    private function view(array $query, Language $language, \Closure $t): array
    {
        $chosen = [
            'language' => $language->code === $this->languages->default()->code ? null : $language->code,
            'direction' => 'rtl' === ($query['direction'] ?? null) ? 'rtl' : null,
            'place' => null,
            'surface' => null,
        ];
        $lists = ['place' => $this->themes->frames(Frame::PLACES), 'surface' => $this->themes->frames(Frame::SURFACES)];
        $errors = [];

        foreach ($lists as $key => $frames) {
            $name = $query[$key] ?? null;

            if (\is_string($name) && isset($frames[$name])) {
                $error = $this->check($frames[$name]);
                $chosen[$key] = $name;

                if (null !== $error) {
                    $errors[] = $error;
                    $chosen[$key] = null;
                }
            }
        }

        $place = null === $chosen['place'] ? null : $lists['place'][$chosen['place']];
        $surface = null === $chosen['surface'] ? null : $lists['surface'][$chosen['surface']];
        $frames = self::frames($place, $surface);

        // A place in a surface of another block must be allowed there.
        if (2 === \count($frames) && !$this->fits($frames[0]->block, true === $this->components->block($frames[0]->block)?->widget, $frames[1])) {
            $errors[] = strtr($t('The place "{place}" cannot stand on the surface "{surface}".'), ['{place}' => $this->frameLabel($frames[0], $language), '{surface}' => $this->frameLabel($frames[1], $language)]);
            $frames = [$frames[0]];
        }

        $link = static function (array $changes) use ($chosen): string {
            $query = http_build_query(array_filter([...$chosen, ...$changes], static fn(mixed $value): bool => null !== $value));

            return self::ROUTE . ('' === $query ? '' : '?' . $query);
        };
        $nav = [];

        if (\count($this->languages->all()) > 1) {
            $nav[] = ['label' => $t('Language'), 'links' => array_map(
                fn(Language $other): array => ['label' => $other->title, 'url' => $link(['language' => $other->code === $this->languages->default()->code ? null : $other->code]), 'current' => $other->code === $language->code],
                $this->languages->all(),
            )];
        }

        $nav[] = ['label' => $t('Direction'), 'links' => [
            ['label' => $t('As the language'), 'url' => $link(['direction' => null]), 'current' => null === $chosen['direction']],
            ['label' => $t('Right to left'), 'url' => $link(['direction' => 'rtl']), 'current' => 'rtl' === $chosen['direction']],
        ]];

        foreach (['place' => [$t('Place'), $t('Main area')], 'surface' => [$t('Surface'), $t('None')]] as $key => [$label, $none]) {
            if ([] === $lists[$key]) {
                continue;
            }

            $links = [['label' => $none, 'url' => $link([$key => null]), 'current' => null === $chosen[$key]]];

            foreach ($lists[$key] as $name => $frame) {
                $links[] = ['label' => $this->frameLabel($frame, $language), 'url' => $link([$key => $name]), 'current' => $name === $chosen[$key]];
            }

            $nav[] = ['label' => $label, 'links' => $links];
        }

        $current = array_map(fn(Frame $frame): string => $this->frameLabel($frame, $language), array_values(array_filter([$place, $surface])));

        return [
            'frames' => $frames,
            'direction' => $chosen['direction'],
            'nav' => $nav,
            'current' => implode(' · ', [$t('Blocks'), ...([] === $current ? [$t('Main area')] : $current)]),
            'errors' => $errors,
        ];
    }

    /**
     * The frames from the inside out: one if place and surface are the same
     * block, the place in the surface else.
     *
     * @return list<Frame>
     */
    private static function frames(?Frame $place, ?Frame $surface): array
    {
        if (null !== $place && null !== $surface && $place->block === $surface->block) {
            return [new Frame($place->name, $place->label, $place->block, $place->area, $place->variant ?? $surface->variant, [...$surface->options, ...$place->options], $place->theme)];
        }

        return array_values(array_filter([$place, $surface]));
    }

    /**
     * What is wrong with the names of a frame, null if nothing.
     */
    private function check(Frame $frame): ?string
    {
        $block = $this->components->block($frame->block);

        return match (true) {
            null === $block => \sprintf('The frame "%s" of %s names the block %s, which does not exist.', $frame->name, $frame->theme, $frame->block),
            null !== $frame->variant && !isset($block->variants[$frame->variant]) => \sprintf('The frame "%s" of %s names the variant "%s", which %s does not have.', $frame->name, $frame->theme, $frame->variant, $frame->block),
            !isset($block->areas[$frame->area]) => \sprintf('The frame "%s" of %s names the area "%s", which %s does not have.', $frame->name, $frame->theme, $frame->area, $frame->block),
            default => null,
        };
    }

    private function fits(string $block, bool $widget, Frame $frame): bool
    {
        return $this->components->block($frame->block)?->areas[$frame->area]?->allows($block, $widget) ?? false;
    }

    private function frameLabel(Frame $frame, Language $language): string
    {
        return $this->translator->in($language->code, $frame->label, $this->themeNames());
    }

    /**
     * Own parts of the themes, the child replaces a file of its parent.
     *
     * @return list<array{name: string, title: string, template: string}>
     */
    private function parts(Language $language): array
    {
        $names = [];

        foreach ($this->themes->themes as $theme) {
            $files = glob($theme->path . '/partials/' . self::PARTS . '/*.twig');

            foreach (false === $files ? [] : $files as $file) {
                $names[basename($file, '.twig')] ??= true;
            }
        }

        ksort($names, \SORT_STRING);

        return array_map(
            fn(string $name): array => ['name' => $name, 'title' => $this->translator->in($language->code, $name, $this->themeNames()), 'template' => FileLoader::NAMESPACE . self::PARTS . '/' . $name . '.twig'],
            array_map(strval(...), array_keys($names)),
        );
    }

    /**
     * @return list<string>
     */
    private function themeNames(): array
    {
        return array_map(static fn($theme): string => $theme->name, $this->themes->themes);
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
