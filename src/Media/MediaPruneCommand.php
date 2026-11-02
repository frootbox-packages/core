<?php

/*
 * SPDX-License-Identifier: MIT
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
 * Removes uploads older than a day and originals that no medium and no
 * draft uses any more once that can no longer be undone
 * (actions.undo_days, default 30): those of deleted media and replaced
 * files (decision 0147).
 * For a daily cron job:
 *
 *     0 3 * * * php /path/to/project/bin/frootbox media:prune
 */
#[AsCommand(name: 'media:prune', description: 'Removes old uploads and originals no longer used')]
final class MediaPruneCommand extends Command
{
    public function __construct(
        private readonly Uploads $uploads,
        private readonly MediaFiles $files,
        private readonly Media $media,
        private readonly MediaSources $sources,
        private readonly MediaDrafts $drafts,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $this->config->int('actions.undo_days', 30);
        $uploads = $this->uploads->prune();
        $ids = [];
        $used = array_fill_keys($this->drafts->originals(), true);

        foreach ($this->media->fetch() as $medium) {
            $ids[$medium->id] = true;
            $used[$medium->original()] = true;
        }

        foreach ($this->sources->fetch() as $source) {
            $used[$source->file] = true;
        }

        $originals = $this->files->pruneOriginals(
            static fn(string $name): bool => isset($used[$name]),
            static fn(string $id): bool => isset($ids[$id]),
            ($days + 1) * 86400,
        );

        new SymfonyStyle($input, $output)->success(\sprintf('Removed %d upload(s) and %d original(s) no longer used.', $uploads, $originals));

        return Command::SUCCESS;
    }
}
