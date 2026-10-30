<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Console\AboutCommand;
use Frootbox\Core\Console\ActionLogCommand;
use Frootbox\Core\Console\ActionsCommand;
use Frootbox\Core\Console\CallCommand;
use Frootbox\Core\Console\ExtensionsCommand;
use Frootbox\Core\Console\MakeExtensionCommand;
use Frootbox\Core\Console\MigrateCommand;
use Frootbox\Core\Console\PruneActionLogCommand;
use Frootbox\Core\Http\WelcomeHandler;

/**
 * The core registers itself like any other extension, through the same
 * extension points. It always runs first.
 */
final class CoreExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->get('/', WelcomeHandler::class, 'welcome');

        $registry->command(AboutCommand::class);
        $registry->command(MigrateCommand::class);
        $registry->command(ExtensionsCommand::class);
        $registry->command(MakeExtensionCommand::class);
        $registry->command(ActionsCommand::class);
        $registry->command(CallCommand::class);
        $registry->command(ActionLogCommand::class);
        $registry->command(PruneActionLogCommand::class);

        $registry->action(UndoAction::class);

        $registry->migrations('Frootbox\Core\Migrations', \dirname(__DIR__, 2) . '/migrations');
    }
}
