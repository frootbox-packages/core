<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

use Frootbox\Core\Account\ChangePassword;
use Frootbox\Core\Account\CreateToken;
use Frootbox\Core\Account\ListLanguages;
use Frootbox\Core\Account\ListTokens;
use Frootbox\Core\Account\RevokeToken;
use Frootbox\Core\Account\ShowAccount;
use Frootbox\Core\Account\SignIn;
use Frootbox\Core\Account\SignOut;
use Frootbox\Core\Account\UpdateLanguage;
use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Api\OpenApiHandler;
use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Comment\CountComments;
use Frootbox\Core\Comment\CreateComment;
use Frootbox\Core\Comment\DeleteComment;
use Frootbox\Core\Comment\ListComments;
use Frootbox\Core\Comment\ReopenComment;
use Frootbox\Core\Comment\ReplyToComment;
use Frootbox\Core\Comment\ResolveComment;
use Frootbox\Core\Component\ListBlockGroups;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Component\PreviewHandler;
use Frootbox\Core\Component\Previews;
use Frootbox\Core\Consent\Embeds;
use Frootbox\Core\Consent\ExternalService;
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
use Frootbox\Core\Console\QueueRunCommand;
use Frootbox\Core\Console\ScheduleRunCommand;
use Frootbox\Core\Console\SeedCommand;
use Frootbox\Core\Console\UserCreateCommand;
use Frootbox\Core\Console\UserTokenCommand;
use Frootbox\Core\Design\DesignPage;
use Frootbox\Core\Design\DiscardDesign;
use Frootbox\Core\Design\ListDesignVersions;
use Frootbox\Core\Design\PublishDesign;
use Frootbox\Core\Design\RestoreDesign;
use Frootbox\Core\Design\ShowDesign;
use Frootbox\Core\Design\UpdateDesign;
use Frootbox\Core\Field\Types\FileType;
use Frootbox\Core\Field\Types\HeadlineType;
use Frootbox\Core\Field\Types\IconType;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Field\Types\TableType;
use Frootbox\Core\Field\Types\TextType;
use Frootbox\Core\Field\Types\VideoType;
use Frootbox\Core\Heading\ShowOutline;
use Frootbox\Core\Help\ListHelp;
use Frootbox\Core\Help\ShowHelp;
use Frootbox\Core\Install\InstallCommand;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Media\AddMediaSource;
use Frootbox\Core\Media\CreateMediaFolder;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\DeleteMediaFolder;
use Frootbox\Core\Media\DeleteMediaSource;
use Frootbox\Core\Media\DeleteMedium;
use Frootbox\Core\Media\DiscardMedium;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\ListMediaFolders;
use Frootbox\Core\Media\ListMediumUsages;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediaPruneCommand;
use Frootbox\Core\Media\MediaUsagesCommand;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\RebuildMediaUsages;
use Frootbox\Core\Media\ReplaceMedium;
use Frootbox\Core\Media\ShowMedium;
use Frootbox\Core\Media\UpdateMediaFolder;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadHandler;
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
use Frootbox\Core\Page\SuggestSlug;
use Frootbox\Core\Page\UnlockPage;
use Frootbox\Core\Page\UnpublishPage;
use Frootbox\Core\Page\UpdateBlock;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Page\UpdatePageField;
use Frootbox\Core\Plugin\AssignPlugin;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\Plugin\DeleteSpace;
use Frootbox\Core\Plugin\ListPlugins;
use Frootbox\Core\Plugin\ListSpaces;
use Frootbox\Core\Plugin\UpdateSpace;
use Frootbox\Core\Preview\CreatePreview;
use Frootbox\Core\Preview\ListPreviews;
use Frootbox\Core\Preview\OpenPreview;
use Frootbox\Core\Preview\PreviewLinks;
use Frootbox\Core\Preview\PrunePreviews;
use Frootbox\Core\Preview\RevokePreview;
use Frootbox\Core\Preview\UpdatePreview;
use Frootbox\Core\Queue\DeleteJob;
use Frootbox\Core\Queue\ListJobs;
use Frootbox\Core\Queue\RetryJob;
use Frootbox\Core\Queue\ShowQueueStatus;
use Frootbox\Core\Search\Search;
use Frootbox\Core\Search\SearchRebuildCommand;
use Frootbox\Core\Search\Sources\HelpSource;
use Frootbox\Core\Search\Sources\MediumSource;
use Frootbox\Core\Search\Sources\NavigationSource;
use Frootbox\Core\Search\Sources\PageSource;
use Frootbox\Core\Search\Sources\RedirectSource;
use Frootbox\Core\Search\Sources\RoleSource;
use Frootbox\Core\Search\Sources\SiteSource;
use Frootbox\Core\Search\Sources\UserSource;
use Frootbox\Core\Search\UpdateSearchIndex;
use Frootbox\Core\Settings\ListSettings;
use Frootbox\Core\Settings\SetSecret;
use Frootbox\Core\Settings\ShowSettings;
use Frootbox\Core\Settings\StoredSettings;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Site\DiscardSite;
use Frootbox\Core\Site\EnterSite;
use Frootbox\Core\Site\Protections;
use Frootbox\Core\Site\ProtectSite;
use Frootbox\Core\Site\PublishSite;
use Frootbox\Core\Site\ShowProtection;
use Frootbox\Core\Site\ShowSite;
use Frootbox\Core\Site\UnprotectSite;
use Frootbox\Core\Site\UpdateSite;
use Frootbox\Core\Site\UpdateSiteField;
use Frootbox\Core\Theme\ListIcons;
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
        // The static routes have to come before the placeholder.
        $registry->get(ApiHandler::PREFIX . '/openapi.json', OpenApiHandler::class, 'api.openapi');
        $registry->post(UploadHandler::PATH, UploadHandler::class, 'api.uploads');
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
        $registry->command(QueueRunCommand::class);
        $registry->command(SeedCommand::class);
        $registry->command(MediaPruneCommand::class);
        $registry->command(MediaUsagesCommand::class);

        foreach ([HeadlineType::class, TextType::class, RichTextType::class, LinkType::class, ListType::class, ImageType::class, VideoType::class, IconType::class, FileType::class, TableType::class] as $fieldType) {
            $registry->fieldType($fieldType);
        }

        // Videos and maps of well-known providers, only after consent (decision 0225).
        foreach (Embeds::services() as $service) {
            $registry->add(ExternalService::class, $service);
        }

        $registry->action(UndoAction::class);

        foreach ([CreateUser::class, UpdateUser::class, SetPassword::class, DeleteUser::class, AssignRole::class, RemoveRole::class, SetRoles::class, CreateRole::class, UpdateRole::class, DeleteRole::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListUsers::class);
        $registry->query(ShowUser::class);
        $registry->query(ListRoles::class);
        $registry->query(ListPermissions::class);

        foreach ([SignIn::class, SignOut::class, ChangePassword::class, UpdateLanguage::class, CreateToken::class, RevokeToken::class] as $action) {
            $registry->action($action);
        }

        foreach ([CreatePage::class, UpdatePage::class, UpdatePageField::class, AddBlock::class, MoveBlock::class, CopyBlock::class, RemoveBlock::class, UpdateBlock::class, AddListEntry::class, MoveListEntry::class, RemoveListEntry::class, MovePage::class, PublishPages::class, DiscardPage::class, DeletePage::class, RestorePage::class, UnpublishPage::class, SchedulePage::class, LockPage::class, UnlockPage::class] as $action) {
            $registry->action($action);
        }

        foreach ([ShowPageTree::class, ShowPage::class, ShowOutline::class, ListPageVersions::class, SuggestSlug::class, ListLayouts::class, ListBlocks::class, ListBlockGroups::class, ListIcons::class] as $query) {
            $registry->query($query);
        }

        $registry->undoCheck(PageUndoCheck::class);
        $registry->scope('page', PageScopes::class);

        foreach ([SyncUrls::class, CreateRedirect::class, UpdateRedirect::class, DeleteRedirect::class, ClearNotFound::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListRedirects::class);

        // Spaces of plugins and the pages that show them (decision 0232).
        foreach ([CreateSpace::class, UpdateSpace::class, DeleteSpace::class, AssignPlugin::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListSpaces::class);
        $registry->query(ListPlugins::class);
        $registry->query(ListNotFound::class);

        foreach ([UpdateNavigation::class, PublishNavigations::class, DiscardNavigation::class, CopyNavigation::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListNavigations::class);
        $registry->query(ShowNavigation::class);
        $registry->undoCheck(NavigationUndoCheck::class);

        $registry->listen(ActionExecuted::class, ClearPageCache::class);

        // The global search of the CMS (decision 0214).
        $registry->query(Search::class);
        $registry->listen(ActionExecuted::class, UpdateSearchIndex::class);
        $registry->command(SearchRebuildCommand::class);

        foreach ([PageSource::class, MediumSource::class, NavigationSource::class, SiteSource::class, UserSource::class, RoleSource::class, RedirectSource::class, HelpSource::class] as $source) {
            $registry->searchSource($source);
        }

        // The help of the CMS from the folders help/ (decisions 0215, 0218).
        $registry->query(ListHelp::class);
        $registry->query(ShowHelp::class);

        foreach ([CreateMedium::class, UpdateMedium::class, ReplaceMedium::class, AddMediaSource::class, DeleteMediaSource::class, DeleteMedium::class, PublishMedia::class, DiscardMedium::class, CreateMediaFolder::class, UpdateMediaFolder::class, DeleteMediaFolder::class, RebuildMediaUsages::class] as $action) {
            $registry->action($action);
        }

        foreach ([ListMedia::class, ShowMedium::class, ListMediumUsages::class, ListMediaFolders::class] as $query) {
            $registry->query($query);
        }

        // Design tokens (decision 0127).
        foreach ([UpdateDesign::class, PublishDesign::class, DiscardDesign::class, RestoreDesign::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ShowDesign::class);
        $registry->query(ListDesignVersions::class);

        // Fields of the website (decision 0128).
        foreach ([UpdateSiteField::class, UpdateSite::class, PublishSite::class, DiscardSite::class, ProtectSite::class, UnprotectSite::class, EnterSite::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ShowSite::class);
        $registry->query(ShowProtection::class);
        $registry->sensitive(Protections::TABLE, 'password_hash');

        // Preview links for customers without an account (decisions 0152, 0154).
        foreach ([CreatePreview::class, UpdatePreview::class, RevokePreview::class, OpenPreview::class, PrunePreviews::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListPreviews::class);
        $registry->sensitive(PreviewLinks::TABLE, 'token');
        $registry->sensitive(PreviewLinks::TABLE, 'token_hash');

        // Comments in the preview and the editing mode (decision 0156).
        foreach ([CreateComment::class, ReplyToComment::class, ResolveComment::class, ReopenComment::class, DeleteComment::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListComments::class);
        $registry->query(CountComments::class);

        $registry->get(MediaHandler::ROUTE, MediaHandler::class, 'media');
        $registry->get(Previews::ROUTE, PreviewHandler::class, 'previews');
        // Everything the theme brings, for agencies to approve (decision 0205).
        $registry->get(DesignPage::ROUTE, DesignPage::class, 'design');

        // Settings of extensions and the queue (decision 0221).
        $registry->action(UpdateSettings::class);
        $registry->action(SetSecret::class);
        $registry->query(ListSettings::class);
        $registry->query(ShowSettings::class);
        $registry->sensitive(StoredSettings::TABLE, 'secret');
        $registry->query(ListJobs::class);
        $registry->query(ShowQueueStatus::class);
        $registry->action(RetryJob::class);
        $registry->action(DeleteJob::class);
        // Mails leave through the queue (decision 0222).
        $registry->job(SendMail::class);

        $registry->query(ShowAccount::class);
        $registry->query(ListLanguages::class);
        $registry->query(ListTokens::class);
        $registry->sensitive(Users::TABLE, 'password');

        $registry->migrations('Frootbox\Core\Migrations', \dirname(__DIR__, 2) . '/migrations');
    }
}
