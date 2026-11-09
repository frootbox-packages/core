<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Database\Ulid;

/**
 * Protects a page with a password or sets a new one, right away and also
 * before it is published (decision 0358). A new password locks out every
 * browser let in before. Not undoable: the hash is not recorded.
 *
 * @implements Action<PageDetails>
 */
#[AsAction('page.protect', 'Protects a page, maybe with its subpages, with a password right away, or sets a new one; visitors then need it, signed-in users and preview links do not', undoable: false)]
final readonly class ProtectPage implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('The password visitors enter, at least 8 characters')]
        #[Sensitive]
        #[Length(min: 8, max: 4096)]
        public string $password,
        #[Description('Also protect all pages below it with this password')]
        public bool $branch = false,
    ) {}

    /**
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [Scope::page($this->id)];
    }

    public function handle(PageReader $reader, PageProtections $protections, PagePasswords $passwords): PageDetails
    {
        $reader->details($this->id);
        $values = [
            'reach' => $this->branch ? Reach::Branch : Reach::Page,
            'password_hash' => $passwords->hash($this->password),
            'generation' => bin2hex(random_bytes(16)),
        ];
        $current = $protections->of($this->id);

        if (null === $current) {
            $protections->insert(['page_id' => $this->id, ...$values]);
        } else {
            $protections->update($current->id, $values);
        }

        $passwords->clear();

        return $reader->details($this->id);
    }
}
