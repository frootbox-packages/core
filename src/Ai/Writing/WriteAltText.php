<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai\Writing;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Ai\Image;
use Frootbox\Core\Media\Images;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Page\PageEditor;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Database\Ulid;
use Throwable;

/**
 * An alternative text for an image (decision 0296): the AI sees a
 * smaller copy of it. Nothing is saved; media.update takes the text.
 *
 * @implements Action<Suggestion>
 */
#[AsAction('ai.alt-text', 'Suggests an alternative text for an image with the AI, which sees a smaller copy of it. Changes nothing; media.update saves the text', permission: 'ai.use', undoable: false)]
final readonly class WriteAltText implements Action
{
    public const string NAME = 'ai.alt-text';

    /** The longest side the model gets; larger images cost more and help no further. */
    public const int SIZE = 1568;

    /** Short enough for screen readers, the field takes 500. */
    public const int MAX_LENGTH = 250;

    public function __construct(
        #[Description('Id of the medium')]
        public string $id,
        #[Description('Language of the text, e.g. "de"')]
        public string $language,
        #[Description('The page the image is on, for its title as context')]
        #[Pattern(Ulid::PATTERN)]
        public ?string $page = null,
        #[Description('What to keep in mind, in the words of the person')]
        #[Length(max: 1000)]
        public ?string $instruction = null,
    ) {}

    public function handle(Media $media, MediaFiles $files, Images $images, PageEditor $pages, Wording $wording): Suggestion
    {
        $medium = $media->fetchById($this->id) ?? throw EntityNotFoundException::for(Media::TABLE, $this->id);

        if (!$medium->isImage() || $medium->isVector()) {
            throw new InvalidInputException(self::NAME, ['/id' => 'The AI describes only photos and other raster images.']);
        }

        try {
            $bytes = $images->render($files->original($medium->original()), min(self::SIZE, max(1, $medium->width >= $medium->height ? $medium->width : (int) round(self::SIZE * $medium->width / max(1, $medium->height)))), 0, 500, 500, 'jpg');
        } catch (Throwable) {
            throw new InvalidInputException(self::NAME, ['/id' => 'The image cannot be read.']);
        }

        $title = null === $this->page ? '' : ($pages->current($this->page)->translations[$this->language]->title ?? '');
        $instruction = trim((string) $this->instruction);
        $lines = [
            'Website: ' . $wording->site(),
            ...'' === $title ? [] : ['Page: ' . $title],
            'Language of the result: ' . $wording->language($this->language),
            '',
            'Task: Write the alternative text for the image above, for people who cannot see it: what it shows and what matters about it on this page, in one or two short sentences, at most ' . self::MAX_LENGTH . ' characters. Do not start with "Image of" or "Photo of". Name text in the image when it matters. Guess no names of people.',
        ];

        if ('' !== $instruction) {
            $lines[] = '';
            $lines[] = Wording::block('instruction', $instruction);
        }

        $answer = trim((string) preg_replace('~\s+~u', ' ', $wording->ask(self::NAME, 'media.alt-text', implode("\n", $lines), [new Image('image/jpeg', $bytes)], 1024)));

        if ('' === $answer) {
            throw new InvalidInputException(self::NAME, ['' => 'The suggestion of the AI does not fit the field. Try again.']);
        }

        $answer = mb_strlen($answer) > 500 ? mb_substr($answer, 0, 500) : $answer;

        return new Suggestion($answer, $answer);
    }
}
