<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

use DateTimeImmutable;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Auth\ActorType;
use LogicException;

/**
 * Saves drafts and publishes them as versions.
 * Writing the published state into the tables of the entity is up to the
 * caller; this class keeps the snapshots. Everything goes through the
 * repository, so actions using it stay undoable.
 */
final readonly class RevisionStore
{
    public function __construct(
        private Revisions $revisions,
        private CurrentContext $context,
        private Journal $journal,
    ) {}

    /**
     * Creates or replaces the draft.
     *
     * @param array<mixed> $data
     */
    public function saveDraft(string $type, string $entityId, array $data): Revision
    {
        $draft = $this->revisions->draft($type, $entityId);

        if (null !== $draft) {
            return $this->revisions->update($draft->id, ['data' => $data, ...$this->origin()]);
        }

        return $this->revisions->insert([
            'type' => $type,
            'entityId' => $entityId,
            'status' => RevisionStatus::Draft,
            'data' => $data,
            ...$this->origin(),
        ]);
    }

    /**
     * Removes the draft.
     *
     * @return bool false if there was none
     */
    public function discard(string $type, string $entityId): bool
    {
        $draft = $this->revisions->draft($type, $entityId);

        return null !== $draft && $this->revisions->delete($draft->id);
    }

    /**
     * Turns the draft into the new published version; the one before
     * becomes archived.
     */
    public function publish(string $type, string $entityId): Revision
    {
        $draft = $this->revisions->draft($type, $entityId) ?? throw new LogicException(\sprintf('%s %s has no draft to publish.', $type, $entityId));
        $previous = $this->revisions->published($type, $entityId);
        $this->archive($type, $entityId);

        return $this->revisions->update($draft->id, [
            'status' => RevisionStatus::Published,
            'version' => $this->revisions->lastVersion($type, $entityId) + 1,
            'publishedAt' => new DateTimeImmutable(),
            'publishAt' => null,
            // Taking it offline at a time stays planned for the new version.
            'unpublishAt' => $draft->unpublishAt ?? $previous?->unpublishAt,
            ...$this->origin(),
        ]);
    }

    /**
     * Plans when the draft is published, null for no time.
     *
     * @return bool false if there is no draft
     */
    public function publishAt(string $type, string $entityId, ?DateTimeImmutable $at): bool
    {
        $draft = $this->revisions->draft($type, $entityId);

        if (null !== $draft && $draft->publishAt?->getTimestamp() !== $at?->getTimestamp()) {
            $this->revisions->update($draft->id, ['publishAt' => $at]);
        }

        return null !== $draft;
    }

    /**
     * Plans when the published version is taken offline, null for no time.
     *
     * @return bool false if nothing is published
     */
    public function unpublishAt(string $type, string $entityId, ?DateTimeImmutable $at): bool
    {
        $published = $this->revisions->published($type, $entityId);

        if (null !== $published && $published->unpublishAt?->getTimestamp() !== $at?->getTimestamp()) {
            $this->revisions->update($published->id, ['unpublishAt' => $at]);
        }

        return null !== $published;
    }

    /**
     * Marks the published version as archived, e.g. when the entity is
     * deleted. Its data can still be restored.
     */
    public function archive(string $type, string $entityId): void
    {
        $published = $this->revisions->published($type, $entityId);

        if (null !== $published) {
            $this->revisions->update($published->id, ['status' => RevisionStatus::Archived]);
        }
    }

    /**
     * Who changed the revision, how and in which action.
     *
     * @return array{authorId: int|null, channel: string, actionEntryId: int|null}
     */
    private function origin(): array
    {
        $context = $this->context->get();

        return [
            'authorId' => ActorType::User === $context->actor->type ? $context->actor->id : null,
            'channel' => $context->channel->value,
            'actionEntryId' => $this->journal->rootEntryId(),
        ];
    }
}
