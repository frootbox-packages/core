<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Navigation>
 */
final class Navigations extends Repository
{
    public const string TABLE = 'navigations';

    /**
     * The published languages of a navigation.
     *
     * @return array<string, Navigation> by language
     */
    public function of(string $name): array
    {
        $navigations = [];

        foreach ($this->fetch(['where' => ['name' => $name]]) as $navigation) {
            $navigations[$navigation->language] = $navigation;
        }

        return $navigations;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Navigation::class;
    }
}
