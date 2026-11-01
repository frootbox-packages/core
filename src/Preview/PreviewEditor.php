<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\Permissions;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Url\DraftAddresses;
use Frootbox\Core\Url\UrlPath;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\Users;
use Frootbox\Database\EntityNotFoundException;

/**
 * Creates, changes and checks preview links (decisions 0152, 0154).
 */
final readonly class PreviewEditor
{
    /** The query parameter that carries the token. */
    public const string PARAMETER = 'fb-preview';

    public const int MAX_DAYS = 90;

    public const int DEFAULT_DAYS = 14;

    /** Days after expiry or revocation until schedule:run deletes a link. */
    public const int KEEP_DAYS = 30;

    public function __construct(
        private PreviewLinks $links,
        private PreviewTokens $tokens,
        private Config $config,
        private Languages $languages,
        private DraftAddresses $addresses,
        private Urls $urls,
        private PageReader $pages,
        private Users $users,
        private Permissions $permissions,
        private CurrentContext $context,
    ) {}

    /**
     * @throws InvalidInputException
     */
    public function create(string $action, ?string $note, ?DateTimeImmutable $expiresAt, ?string $pageId): PreviewSummary
    {
        $this->mustSeeAllPages($action);
        $now = new DateTimeImmutable();
        $expiresAt ??= $now->modify(\sprintf('+%d days', $this->config->int('preview.days', self::DEFAULT_DAYS)));
        $errors = [...$this->checkExpiry($expiresAt, $now), ...$this->checkNote($note)];

        if (null !== $pageId && null === $this->pages->draft($pageId) && null === $this->pages->published($pageId)) {
            $errors['/pageId'] = new Message('is not a page.');
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $token = $this->tokens->create();
        $link = $this->links->insert([
            'token_hash' => PreviewTokens::hash($token),
            'token' => $this->tokens->encrypt($token),
            'note' => self::clean($note),
            'page_id' => $pageId,
            'created_by' => $this->context->get()->actor->id,
            'expires_at' => $expiresAt,
            'opens' => 0,
        ]);

        return $this->summary($link);
    }

    /**
     * @throws InvalidInputException
     * @throws ConflictException
     * @throws EntityNotFoundException
     */
    public function update(string $action, string $id, ?string $note, ?DateTimeImmutable $expiresAt, bool $changeNote): PreviewSummary
    {
        $this->mustSeeAllPages($action);
        $link = $this->links->getById($id);

        if (null !== $link->revokedAt) {
            throw new ConflictException('The preview link was revoked and stays so. Create a new one.');
        }

        $errors = [...(null === $expiresAt ? [] : $this->checkExpiry($expiresAt, new DateTimeImmutable())), ...$this->checkNote($note)];

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $values = [];

        if (null !== $expiresAt) {
            $values['expires_at'] = $expiresAt;
        }

        if ($changeNote) {
            $values['note'] = self::clean($note);
        }

        return $this->summary([] === $values ? $link : $this->links->update($id, $values));
    }

    /**
     * @throws EntityNotFoundException
     */
    public function revoke(string $action, string $id): PreviewSummary
    {
        $this->mustSeeAllPages($action);
        $link = $this->links->getById($id);

        return $this->summary(null === $link->revokedAt ? $this->links->update($id, ['revoked_at' => new DateTimeImmutable()]) : $link);
    }

    /**
     * The valid link of a token, null for an unknown, expired or revoked one.
     */
    public function valid(string $token, DateTimeImmutable $now = new DateTimeImmutable()): ?PreviewLink
    {
        $link = '' === $token ? null : $this->links->byToken($token);

        return null !== $link && PreviewStatus::Valid === $link->status($now) ? $link : null;
    }

    /**
     * @return list<PreviewSummary>
     */
    public function all(): array
    {
        return array_map($this->summary(...), $this->links->fetch(['order' => ['created_at desc', 'id desc']])->all());
    }

    public function summary(PreviewLink $link): PreviewSummary
    {
        $token = $this->tokens->decrypt($link->token);
        $path = null === $token ? null : $this->path($link->pageId, $token);
        $site = $this->config->get('app.url');
        $base = \is_string($site) && '' !== $site ? rtrim($site, '/') : null;
        $language = $this->languages->default();
        $base = null === $base ? null : $this->urls->base($language, $base);
        $creator = null === $link->createdBy ? null : $this->users->fetchById($link->createdBy);

        return new PreviewSummary(
            $link->id,
            $link->note,
            $link->status(),
            $path,
            null === $path || null === $base ? null : $base . $path,
            $link->pageId,
            $creator?->name,
            $link->createdAt,
            $link->expiresAt,
            $link->revokedAt,
            $link->firstOpenedAt,
            $link->lastOpenedAt,
            $link->opens,
        );
    }

    /**
     * The link of a token in the first language: the page, or the start page.
     */
    private function path(?string $pageId, string $token): string
    {
        $language = $this->languages->default();
        $path = null === $pageId ? null : ($this->addresses->of($pageId)[$language->code] ?? null)?->path;

        return UrlPath::encode($language->path($path ?? '')) . '?' . self::PARAMETER . '=' . $token;
    }

    /**
     * The link shows the whole website, so a role limited to a branch of
     * pages may not create or change one (decision 0152).
     */
    private function mustSeeAllPages(string $action): void
    {
        $actor = $this->context->get()->actor;

        if (!$this->permissions->allows($actor, 'page.show')) {
            throw new AccessDeniedException(new Message('{actor} may not run "{action}": a preview link shows all pages, and the role does not see them all.', ['actor' => $actor->name, 'action' => $action]));
        }
    }

    /**
     * @return array<string, Message>
     */
    private function checkExpiry(DateTimeImmutable $expiresAt, DateTimeImmutable $now): array
    {
        if ($expiresAt <= $now) {
            return ['/expiresAt' => new Message('must be in the future.')];
        }

        if ($expiresAt > $now->modify(\sprintf('+%d days', self::MAX_DAYS))) {
            return ['/expiresAt' => new Message('may be at most {days} days from now.', ['days' => self::MAX_DAYS])];
        }

        return [];
    }

    /**
     * @return array<string, Message>
     */
    private function checkNote(?string $note): array
    {
        return null !== $note && mb_strlen(trim($note)) > 200 ? ['/note' => new Message('may have at most {max} characters.', ['max' => 200])] : [];
    }

    private static function clean(?string $note): ?string
    {
        $note = null === $note ? '' : trim($note);

        return '' === $note ? null : $note;
    }
}
