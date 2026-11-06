<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\History\Failing;

use Doctrine\DBAL\Schema\Schema;
use Frootbox\Database\Migration;
use RuntimeException;

/**
 * Test migration that fails.
 */
final class Version20991231000001 extends Migration
{
    public function getDescription(): string
    {
        return 'Fails on purpose';
    }

    public function up(Schema $schema): void
    {
        throw new RuntimeException('Broken on purpose.');
    }

    public function down(Schema $schema): void {}
}
