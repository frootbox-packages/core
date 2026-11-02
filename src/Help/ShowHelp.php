<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Asset\PublicAssets;
use Frootbox\Core\I18n\Translator;
use Frootbox\Database\EntityNotFoundException;

/**
 * A page of the help as safe HTML (decisions 0215, 0218), in the
 * language of the backend or else in English. A page the user may not
 * read does not exist for them.
 *
 * @implements Query<HelpArticle>
 */
#[AsQuery('help.show', 'Shows a page of the help as HTML, links to other pages follow the given address', signedIn: true)]
final readonly class ShowHelp implements Query
{
    public function __construct(
        #[Length(1, 200)]
        #[Description('e.g. "frootbox/backend/pages"')]
        public string $id,
        #[Pattern('^/[^\s]*\{id\}[^\s]*$')]
        #[Length(max: 200)]
        #[Description('The address of a help page with {id}, for links between them')]
        public string $links = '/api/v1/help.show?id={id}',
    ) {}

    public function handle(HelpLibrary $library, HelpAccess $access, CurrentContext $context, Translator $translator, PublicAssets $assets): HelpArticle
    {
        $topic = $library->get($this->id);

        if (null === $topic || !$access->allows($context->get()->actor, $topic)) {
            throw EntityNotFoundException::for('help', $this->id);
        }

        $wanted = $translator->language();
        $language = $topic->language($wanted);
        $html = Markdown::toHtml(
            $topic->markdown($language),
            fn(string $href): ?string => $this->link($href, $topic, $library),
            static fn(string $src): ?string => self::image($src, $topic, $assets),
        );

        return new HelpArticle($topic->id, $topic->title($language), $html, $language, $language === $wanted || HelpLibrary::SOURCE === $wanted, $topic->package, $topic->about);
    }

    /**
     * Another page of the same package, e.g. "pages.md#move-a-page".
     */
    private function link(string $href, HelpTopic $topic, HelpLibrary $library): ?string
    {
        if (1 !== preg_match('/^([a-z0-9][a-z0-9-]*)\.md(#[^\s]*)?$/', $href, $match)) {
            return null;
        }

        $id = null === $topic->package ? $match[1] : $topic->package . '/' . $match[1];

        if (null === $library->get($id)) {
            return null;
        }

        return str_replace('{id}', $id, $this->links) . (isset($match[2]) ? '#' . Markdown::anchor(substr($match[2], 1)) : '');
    }

    /**
     * A file in help/assets/ of the package, published like all assets.
     */
    private static function image(string $src, HelpTopic $topic, PublicAssets $assets): ?string
    {
        $file = $topic->directory . '/' . $src;

        return '' === $topic->directory || !str_starts_with($src, PublicAssets::FOLDER . '/') || !is_file($file) ? null : $assets->url($file);
    }
}
