<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Database\Ulid;

/**
 * @implements Action<AdminGroup>
 */
#[AsAction('category-group.update', 'Renames a group of categories created in the backend or changes its types')]
final readonly class UpdateGroup implements Action
{
    /**
     * @param list<string>|null $types
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Length(1, 100)]
        public ?string $title = null,
        #[Description('The types it applies to, replacing the ones before')]
        public ?array $types = null,
    ) {}

    public function handle(GroupEditor $editor, AddressSync $addresses): AdminGroup
    {
        $group = $editor->update('category-group.update', $this->id, $this->title, $this->types);
        // The pages of the categories follow (decision 0243).
        $addresses->sync();

        return $group;
    }
}
