<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Database\Repository;

/**
 * @extends Repository<Address>
 */
final class Addresses extends Repository
{
    public const string TABLE = 'urls';

    /**
     * The current address at a page path in a language.
     */
    public function at(string $language, string $path): ?Address
    {
        return $this->fetchOne(['where' => ['language' => $language, 'basePageId' => null, 'path' => $path, 'current' => true]]);
    }

    /**
     * An earlier address at a page path in a language.
     */
    public function earlier(string $language, string $path): ?Address
    {
        return $this->fetchOne(['where' => ['language' => $language, 'basePageId' => null, 'path' => $path, 'current' => false], 'order' => 'id desc']);
    }

    /**
     * The current addresses of a target by language.
     *
     * @return array<string, Address>
     */
    public function of(Target $target): array
    {
        $addresses = [];

        foreach ($this->fetch(['where' => ['target' => (string) $target, 'current' => true]]) as $address) {
            $addresses[$address->language] = $address;
        }

        return $addresses;
    }

    /**
     * The current addresses of all pages.
     *
     * @return list<Address>
     */
    public function pages(): array
    {
        return $this->fetch(['where' => ['basePageId' => null, 'current' => true]])->all();
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return Address::class;
    }
}
