<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Comment;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Database\Ulid;

/**
 * Opens a thread on a page, a block or a field, also for guests with a
 * preview link (decision 0156), or on a landmark and at a point of the
 * place (decision 0211).
 *
 * @implements Action<CommentThread>
 */
#[AsAction('comment.create', 'Comments on a page, a block, a field or a landmark such as the footer, or on an element of the design page, optionally at a point of it, and opens a thread; guests with a preview link give their name', preview: true)]
final readonly class CreateComment implements Action
{
    public function __construct(
        #[Description('The text, plain text without markup')]
        #[Length(min: 1, max: CommentEditor::MAX_BODY)]
        public string $body,
        #[Description('The page the comment is about; left out for the design page')]
        #[Pattern(Ulid::PATTERN)]
        public ?string $pageId = null,
        #[Description('The name of a guest with a preview link; users write under their own name')]
        #[Length(min: 1, max: CommentEditor::MAX_NAME)]
        public ?string $authorName = null,
        #[Description('The block the comment is about; left out for the page')]
        #[Pattern(self::BLOCK)]
        public ?string $blockId = null,
        #[Description('The field inside the block, e.g. "slides/0/title", or with site of the website')]
        #[Pattern(self::FIELD)]
        public ?string $field = null,
        #[Description('Whether the field is one of the website, e.g. the address in the footer')]
        public bool $site = false,
        #[Description('The language of the page as shown, e.g. "de"')]
        #[Pattern('^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$')]
        public ?string $language = null,
        #[Description('The address where it was written, e.g. "/kontakt"')]
        #[Length(max: 2048)]
        public ?string $path = null,
        #[Description('The text at the place, to find it again')]
        #[Length(max: CommentEditor::MAX_EXCERPT)]
        public ?string $excerpt = null,
        #[Description('The width of the window in pixels')]
        #[Range(min: 0, max: 100000)]
        public ?int $viewport = null,
        #[Description('The element of the design page /_design instead of a page, e.g. "tokens", "frootbox/theme-base/Hero/Split/1" or "page" for all of it')]
        #[Pattern(self::DESIGN)]
        public ?string $design = null,
        #[Description('The landmark of the page where no block or field is, e.g. "banner#1", "navigation#2:Footer" or "contentinfo#1"')]
        #[Pattern(self::LANDMARK)]
        public ?string $landmark = null,
        #[Description('Where in the place, in thousandths of its width from its start edge in the direction of reading')]
        #[Range(min: 0, max: 1000)]
        public ?int $pointX = null,
        #[Description('Where in the place, in thousandths of its height from the top')]
        #[Range(min: 0, max: 1000)]
        public ?int $pointY = null,
    ) {}

    public const string LANDMARK = '^(banner|contentinfo|navigation|complementary|main)#[1-9][0-9]{0,2}(:.{1,200})?$';

    public const string DESIGN = '^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+){0,7}$';

    public const string BLOCK = '^[0-9A-Za-z_-]{1,64}$';

    public const string FIELD = '^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$';

    public function handle(CommentEditor $editor): CommentThread
    {
        return $editor->create('comment.create', $this->pageId, [
            'language' => $this->language,
            'path' => $this->path,
            'blockId' => $this->blockId,
            'field' => $this->field,
            'site' => $this->site,
            'excerpt' => $this->excerpt,
            'viewport' => $this->viewport,
            'design' => $this->design,
            'landmark' => $this->landmark,
            'pointX' => $this->pointX,
            'pointY' => $this->pointY,
        ], $this->body, $this->authorName);
    }
}
