<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Account\ChangePassword;
use Frootbox\Core\Account\CreateToken;
use Frootbox\Core\Account\ListTokens;
use Frootbox\Core\Account\RevokeToken;
use Frootbox\Core\Account\ShowAccount;
use Frootbox\Core\Account\SignIn;
use Frootbox\Core\Account\SignOut;
use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Api\OpenApiHandler;
use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Console\AboutCommand;
use Frootbox\Core\Console\ActionLogCommand;
use Frootbox\Core\Console\ActionsCommand;
use Frootbox\Core\Console\AssetsPublishCommand;
use Frootbox\Core\Console\BlocksCheckCommand;
use Frootbox\Core\Console\BlocksCommand;
use Frootbox\Core\Console\CacheClearCommand;
use Frootbox\Core\Console\CallCommand;
use Frootbox\Core\Console\ExtensionsCommand;
use Frootbox\Core\Console\MakeExtensionCommand;
use Frootbox\Core\Console\MigrateCommand;
use Frootbox\Core\Console\PruneActionLogCommand;
use Frootbox\Core\Console\ScheduleRunCommand;
use Frootbox\Core\Console\SeedCommand;
use Frootbox\Core\Console\UserCreateCommand;
use Frootbox\Core\Console\UserTokenCommand;
use Frootbox\Core\Field\Types\HeadlineType;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Field\Types\TextType;
use Frootbox\Core\Install\InstallCommand;
use Frootbox\Core\Navigation\CopyNavigation;
use Frootbox\Core\Navigation\DiscardNavigation;
use Frootbox\Core\Navigation\ListNavigations;
use Frootbox\Core\Navigation\NavigationUndoCheck;
use Frootbox\Core\Navigation\PublishNavigations;
use Frootbox\Core\Navigation\ShowNavigation;
use Frootbox\Core\Navigation\UpdateNavigation;
use Frootbox\Core\Page\AddBlock;
use Frootbox\Core\Page\AddListEntry;
use Frootbox\Core\Page\CopyBlock;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\DiscardPage;
use Frootbox\Core\Page\ListPageVersions;
use Frootbox\Core\Page\LockPage;
use Frootbox\Core\Page\MoveBlock;
use Frootbox\Core\Page\MoveListEntry;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\PageScopes;
use Frootbox\Core\Page\PageUndoCheck;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\RemoveBlock;
use Frootbox\Core\Page\RemoveListEntry;
use Frootbox\Core\Page\RestorePage;
use Frootbox\Core\Page\SchedulePage;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\ShowPageTree;
use Frootbox\Core\Page\UnlockPage;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Page\UpdateBlock;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\Url\ClearNotFound;
use Frootbox\Core\Url\CreateRedirect;
use Frootbox\Core\Url\DeleteRedirect;
use Frootbox\Core\Url\ListNotFound;
use Frootbox\Core\Url\ListRedirects;
use Frootbox\Core\Url\SyncUrls;
use Frootbox\Core\Url\UpdateRedirect;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\DeleteRole;
use Frootbox\Core\User\DeleteUser;
use Frootbox\Core\User\ListPermissions;
use Frootbox\Core\User\ListRoles;
use Frootbox\Core\User\ListUsers;
use Frootbox\Core\User\RemoveRole;
use Frootbox\Core\User\SetPassword;
use Frootbox\Core\User\SetRoles;
use Frootbox\Core\User\ShowUser;
use Frootbox\Core\User\UpdateRole;
use Frootbox\Core\User\UpdateUser;
use Frootbox\Core\User\Users;

/**
 * The core registers itself like any other extension, through the same
 * extension points. It always runs first.
 */
final class CoreExtension implements Extension
{
    public function register(Registry $registry): void
    {
        // The static route has to come before the placeholder.
        $registry->get(ApiHandler::PREFIX . '/openapi.json', OpenApiHandler::class, 'api.openapi');
        $registry->route(['GET', 'POST'], ApiHandler::PREFIX . '/' . ApiHandler::NAME, ApiHandler::class, 'api');

        $registry->command(AboutCommand::class);
        $registry->command(AssetsPublishCommand::class);
        $registry->command(InstallCommand::class);
        $registry->command(MigrateCommand::class);
        $registry->command(ExtensionsCommand::class);
        $registry->command(MakeExtensionCommand::class);
        $registry->command(ActionsCommand::class);
        $registry->command(CallCommand::class);
        $registry->command(ActionLogCommand::class);
        $registry->command(PruneActionLogCommand::class);
        $registry->command(UserCreateCommand::class);
        $registry->command(UserTokenCommand::class);
        $registry->command(BlocksCommand::class);
        $registry->command(BlocksCheckCommand::class);
        $registry->command(CacheClearCommand::class);
        $registry->command(ScheduleRunCommand::class);
        $registry->command(SeedCommand::class);

        foreach ([HeadlineType::class, TextType::class, RichTextType::class, LinkType::class, ListType::class] as $fieldType) {
            $registry->fieldType($fieldType);
        }

        $registry->action(UndoAction::class);

        foreach ([CreateUser::class, UpdateUser::class, SetPassword::class, DeleteUser::class, AssignRole::class, RemoveRole::class, SetRoles::class, CreateRole::class, UpdateRole::class, DeleteRole::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListUsers::class);
        $registry->query(ShowUser::class);
        $registry->query(ListRoles::class);
        $registry->query(ListPermissions::class);

        foreach ([SignIn::class, SignOut::class, ChangePassword::class, CreateToken::class, RevokeToken::class] as $action) {
            $registry->action($action);
        }

        foreach ([CreatePage::class, UpdatePage::class, UpdatePageField::class, AddBlock::class, MoveBlock::class, CopyBlock::class, RemoveBlock::class, UpdateBlock::class, AddListEntry::class, MoveListEntry::class, RemoveListEntry::class, MovePage::class, PublishPages::class, DiscardPage::class, DeletePage::class, RestorePage::class, UnpublishPage::class, SchedulePage::class, LockPage::class, UnlockPage::class] as $action) {
            $registry->action($action);
        }

        foreach ([ShowPageTree::class, ShowPage::class, ListPageVersions::class, ListLayouts::class, ListBlocks::class] as $query) {
            $registry->query($query);
        }

        $registry->undoCheck(PageUndoCheck::class);
        $registry->scope('page', PageScopes::class);

        foreach ([SyncUrls::class, CreateRedirect::class, UpdateRedirect::class, DeleteRedirect::class, ClearNotFound::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListRedirects::class);
        $registry->query(ListNotFound::class);

        foreach ([UpdateNavigation::class, PublishNavigations::class, DiscardNavigation::class, CopyNavigation::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListNavigations::class);
        $registry->query(ShowNavigation::class);
        $registry->undoCheck(NavigationUndoCheck::class);

        $registry->listen(ActionExecuted::class, ClearPageCache::class);

        $registry->query(ShowAccount::class);
        $registry->query(ListTokens::class);
        $registry->sensitive(Users::TABLE, 'password');

        $registry->migrations('Frootbox\Core\Migrations', \dirname(__DIR__, 2) . '/migrations');
    }
}
