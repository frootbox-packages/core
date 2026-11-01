<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes uploads older than a day and originals of deleted media once
 * their deletion can no longer be undone (actions.undo_days, default 30).
 * For a daily cron job:
 *
 *     0 3 * * * php /path/to/project/bin/frootbox media:prune
 */
#[AsCommand(name: 'media:prune', description: 'Removes old uploads and the files of deleted media')]
final class MediaPruneCommand extends Command
{
    public function __construct(
        private readonly Uploads $uploads,
        private readonly MediaFiles $files,
        private readonly Media $media,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $this->config->int('actions.undo_days', 30);
        $uploads = $this->uploads->prune();
        $originals = $this->files->pruneOriginals(fn(string $id): bool => null !== $this->media->fetchById($id), ($days + 1) * 86400);

        new SymfonyStyle($input, $output)->success(\sprintf('Removed %d upload(s) and the files of %d deleted medium/media.', $uploads, $originals));

        return Command::SUCCESS;
    }
}
