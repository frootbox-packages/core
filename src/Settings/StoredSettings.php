<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Database\Repository;

/**
 * @extends Repository<StoredSetting>
 */
final class StoredSettings extends Repository
{
    public const string TABLE = 'settings';

    /**
     * @return array<string, StoredSetting> by name
     */
    public function ofPackage(string $package): array
    {
        $settings = [];

        foreach ($this->fetch(['where' => ['package' => $package]]) as $setting) {
            $settings[$setting->name] = $setting;
        }

        return $settings;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function entity(): string
    {
        return StoredSetting::class;
    }
}
