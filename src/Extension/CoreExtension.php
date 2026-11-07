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
use Frootbox\Core\Action\ListActionLog;
use Frootbox\Core\Action\PruneOnSchedule;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Ai\AiSettings;
use Frootbox\Core\Ai\PricesOnSchedule;
use Frootbox\Core\Ai\ShowAiStatus;
use Frootbox\Core\Ai\ShowAiUsage;
use Frootbox\Core\Ai\TestAi;
use Frootbox\Core\Ai\Writing\WriteAltText;
use Frootbox\Core\Ai\Writing\WriteField;
use Frootbox\Core\Ai\Writing\WriteSearchSnippet;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\Api\OpenApiHandler;
use Frootbox\Core\Auth\EndSessions;
use Frootbox\Core\Auth\SessionsEndCommand;
use Frootbox\Core\Backup\BackupCreateCommand;
use Frootbox\Core\Backup\BackupListCommand;
use Frootbox\Core\Backup\BackupPruneCommand;
use Frootbox\Core\Backup\BackupRestoreCommand;
use Frootbox\Core\Backup\BackupsOnSchedule;
use Frootbox\Core\Backup\CreateBackup;
use Frootbox\Core\Backup\DeleteBackup;
use Frootbox\Core\Backup\ListBackups;
use Frootbox\Core\Cache\ClearCaches;
use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Category\ApplyPresets;
use Frootbox\Core\Category\CreateCategory;
use Frootbox\Core\Category\CreateGroup;
use Frootbox\Core\Category\DeleteCategory;
use Frootbox\Core\Category\DeleteGroup;
use Frootbox\Core\Category\ListCategories;
use Frootbox\Core\Category\ListGroups;
use Frootbox\Core\Category\ListGroupTypes;
use Frootbox\Core\Category\MoveCategory;
use Frootbox\Core\Category\PresetsOnSchedule;
use Frootbox\Core\Category\UpdateCategory;
use Frootbox\Core\Category\UpdateGroup;
use Frootbox\Core\Comment\CountComments;
use Frootbox\Core\Comment\CreateComment;
use Frootbox\Core\Comment\DeleteComment;
use Frootbox\Core\Comment\ListComments;
use Frootbox\Core\Comment\ReopenComment;
use Frootbox\Core\Comment\ReplyToComment;
use Frootbox\Core\Comment\ResolveComment;
use Frootbox\Core\Component\ListBlockGroups;
use Frootbox\Core\Component\ListBlocks;
use Frootbox\Core\Component\ListComponentUsages;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Component\PreviewHandler;
use Frootbox\Core\Component\Previews;
use Frootbox\Core\Consent\ConsentSettings;
use Frootbox\Core\Consent\Embeds;
use Frootbox\Core\Consent\ExternalService;
use Frootbox\Core\Consent\ListServices;
use Frootbox\Core\Consent\Proof\ListProofs;
use Frootbox\Core\Consent\Proof\ProofHandler;
use Frootbox\Core\Consent\Proof\Proofs;
use Frootbox\Core\Console\AboutCommand;
use Frootbox\Core\Console\ActionLogCommand;
use Frootbox\Core\Console\ActionsCommand;
use Frootbox\Core\Console\AssetsPublishCommand;
use Frootbox\Core\Console\BlocksCheckCommand;
use Frootbox\Core\Console\BlocksCommand;
use Frootbox\Core\Console\CacheClearCommand;
use Frootbox\Core\Console\CallCommand;
use Frootbox\Core\Console\ExtensionCatalogCommand;
use Frootbox\Core\Console\ExtensionCheckCommand;
use Frootbox\Core\Console\ExtensionsCommand;
use Frootbox\Core\Console\MakeExtensionCommand;
use Frootbox\Core\Console\MigrateCommand;
use Frootbox\Core\Console\PruneActionLogCommand;
use Frootbox\Core\Console\QueueRunCommand;
use Frootbox\Core\Console\ScheduleRunCommand;
use Frootbox\Core\Console\SeedCommand;
use Frootbox\Core\Console\UserCreateCommand;
use Frootbox\Core\Console\UserTokenCommand;
use Frootbox\Core\Database\ListMigrations;
use Frootbox\Core\Database\RunMigrations;
use Frootbox\Core\Design\DesignPage;
use Frootbox\Core\Design\DiscardDesign;
use Frootbox\Core\Design\ListDesignVersions;
use Frootbox\Core\Design\PublishDesign;
use Frootbox\Core\Design\RestoreDesign;
use Frootbox\Core\Design\ShowDesign;
use Frootbox\Core\Design\UpdateDesign;
use Frootbox\Core\Field\Types\BooleanType;
use Frootbox\Core\Field\Types\ChoiceType;
use Frootbox\Core\Field\Types\DateType;
use Frootbox\Core\Field\Types\FileType;
use Frootbox\Core\Field\Types\HeadlineType;
use Frootbox\Core\Field\Types\IconType;
use Frootbox\Core\Field\Types\ImageType;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Field\Types\NumberType;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Field\Types\TableType;
use Frootbox\Core\Field\Types\TextType;
use Frootbox\Core\Field\Types\VideoType;
use Frootbox\Core\Heading\ShowOutline;
use Frootbox\Core\Help\ListHelp;
use Frootbox\Core\Help\ShowHelp;
use Frootbox\Core\Hub\CompleteHub;
use Frootbox\Core\Hub\ConnectHub;
use Frootbox\Core\Hub\DisconnectHub;
use Frootbox\Core\Hub\HubCallbackHandler;
use Frootbox\Core\Hub\HubConnectCommand;
use Frootbox\Core\Hub\HubConnections;
use Frootbox\Core\Hub\HubDisconnectCommand;
use Frootbox\Core\Hub\ShowHub;
use Frootbox\Core\Hub\SwitchHub;
use Frootbox\Core\Hub\UpdateHubKeys;
use Frootbox\Core\I18n\FormalitySettings;
use Frootbox\Core\Install\InstallCommand;
use Frootbox\Core\Mail\MailSettings;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Mail\SendTestMail;
use Frootbox\Core\Mail\ShowMailStatus;
use Frootbox\Core\Mcp\McpHandler;
use Frootbox\Core\Media\AddMediaSource;
use Frootbox\Core\Media\CreateMediaFolder;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\DeleteMediaFolder;
use Frootbox\Core\Media\DeleteMediaSource;
use Frootbox\Core\Media\DeleteMedium;
use Frootbox\Core\Media\DiscardMedium;
use Frootbox\Core\Media\ListCredits;
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
use Frootbox\Core\ObjectField\ShowObjectFields;
use Frootbox\Core\ObjectField\UpdateObjectField;
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
use Frootbox\Core\Page\ShowPageHistory;
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
use Frootbox\Core\Plugin\ListAllSpaces;
use Frootbox\Core\Plugin\ListPlugins;
use Frootbox\Core\Plugin\ListSpaces;
use Frootbox\Core\Plugin\SpaceScopes;
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
use Frootbox\Core\Reference\ReferenceCommand;
use Frootbox\Core\Schedule\ScheduleRan;
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
use Frootbox\Core\Status\ShowStatus;
use Frootbox\Core\Storage\StorageCache;
use Frootbox\Core\Storage\StorageCheckCommand;
use Frootbox\Core\Storage\StoragePullCommand;
use Frootbox\Core\Storage\StoragePushCommand;
use Frootbox\Core\Tag\ChooseTags;
use Frootbox\Core\Tag\CreateTag;
use Frootbox\Core\Tag\DeleteTag;
use Frootbox\Core\Tag\ListTags;
use Frootbox\Core\Tag\MergeTags;
use Frootbox\Core\Tag\TagOptions;
use Frootbox\Core\Tag\UpdateTag;
use Frootbox\Core\Theme\ListIcons;
use Frootbox\Core\Theme\SiteIcon;
use Frootbox\Core\Theme\SiteIconHandler;
use Frootbox\Core\Url\ClearNotFound;
use Frootbox\Core\Url\CreateRedirect;
use Frootbox\Core\Url\DeleteRedirect;
use Frootbox\Core\Url\ListNotFound;
use Frootbox\Core\Url\ListRedirects;
use Frootbox\Core\Url\SyncUrls;
use Frootbox\Core\Url\UpdateRedirect;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateServiceToken;
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
use Frootbox\Core\Visitor\PruneVisitorSessions;

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
        $registry->post(McpHandler::PATH, McpHandler::class, 'api.mcp');
        $registry->route(['GET', 'POST'], ApiHandler::PREFIX . '/' . ApiHandler::NAME, ApiHandler::class, 'api');

        $registry->command(AboutCommand::class);
        $registry->command(ReferenceCommand::class);
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
        $registry->command(ExtensionCheckCommand::class);
        $registry->command(ExtensionCatalogCommand::class);
        $registry->command(CacheClearCommand::class);
        $registry->command(ScheduleRunCommand::class);
        $registry->command(QueueRunCommand::class);
        $registry->command(SeedCommand::class);
        $registry->command(MediaPruneCommand::class);
        $registry->command(MediaUsagesCommand::class);
        $registry->listen(ScheduleRan::class, StorageCache::class);
        $registry->command(StorageCheckCommand::class);
        $registry->command(StoragePushCommand::class);
        $registry->command(StoragePullCommand::class);

        foreach ([HeadlineType::class, TextType::class, RichTextType::class, LinkType::class, ListType::class, ImageType::class, VideoType::class, IconType::class, FileType::class, TableType::class, BooleanType::class, NumberType::class, DateType::class, ChoiceType::class] as $fieldType) {
            $registry->fieldType($fieldType);
        }

        // Videos and maps of well-known providers, only after consent (decision 0225).
        foreach (Embeds::services() as $service) {
            $registry->add(ExternalService::class, $service);
        }

        // Banner, privacy settings and consent tools (decision 0264).
        $registry->settings(ConsentSettings::class);
        $registry->settings(FormalitySettings::class);
        $registry->query(ListServices::class);
        $registry->query(ListProofs::class);
        $registry->post(ProofHandler::ROUTE, ProofHandler::class, 'consent.proof');
        $registry->listen(ScheduleRan::class, Proofs::class);

        $registry->action(UndoAction::class);
        $registry->query(ListActionLog::class);

        foreach ([CreateUser::class, UpdateUser::class, SetPassword::class, CreateServiceToken::class, DeleteUser::class, AssignRole::class, RemoveRole::class, SetRoles::class, CreateRole::class, UpdateRole::class, DeleteRole::class] as $action) {
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

        foreach ([ShowPageTree::class, ShowPage::class, ShowOutline::class, ListPageVersions::class, ShowPageHistory::class, SuggestSlug::class, ListLayouts::class, ListBlocks::class, ListBlockGroups::class, ListIcons::class] as $query) {
            $registry->query($query);
        }

        $registry->undoCheck(PageUndoCheck::class);
        $registry->scope('page', PageScopes::class);
        $registry->scope(SpaceScopes::KIND, SpaceScopes::class);

        foreach ([SyncUrls::class, CreateRedirect::class, UpdateRedirect::class, DeleteRedirect::class, ClearNotFound::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListRedirects::class);

        // Spaces of plugins and the pages that show them (decision 0232).
        foreach ([CreateSpace::class, UpdateSpace::class, DeleteSpace::class, AssignPlugin::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListSpaces::class);
        $registry->query(ListAllSpaces::class);

        // Categories in groups for all objects (decision 0233).
        foreach ([CreateCategory::class, UpdateCategory::class, MoveCategory::class, DeleteCategory::class, ApplyPresets::class, CreateGroup::class, UpdateGroup::class, DeleteGroup::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListCategories::class);
        $registry->query(ListGroups::class);
        $registry->query(ListGroupTypes::class);
        $registry->listen(ScheduleRan::class, PresetsOnSchedule::class);

        // One stock of tags for all objects (decisions 0233, 0239).
        foreach ([CreateTag::class, UpdateTag::class, MergeTags::class, DeleteTag::class] as $action) {
            $registry->action($action);
        }

        $registry->query(ListTags::class);
        $registry->query(ChooseTags::class);
        $registry->query(TagOptions::class);
        // Further fields at objects of modules (decisions 0234, 0240).
        $registry->query(ShowObjectFields::class);
        $registry->action(UpdateObjectField::class);
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

        foreach ([ListMedia::class, ShowMedium::class, ListMediumUsages::class, ListMediaFolders::class, ListCredits::class] as $query) {
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

        // The icon of the website from the theme (decision 0318).
        foreach ([...array_keys(SiteIcon::FILES), SiteIcon::MANIFEST] as $address) {
            $registry->get($address, SiteIconHandler::class, 'icon.' . basename($address));
        }

        // Settings of extensions and the queue (decision 0221).
        $registry->action(UpdateSettings::class);
        $registry->action(SetSecret::class);
        $registry->query(ListSettings::class);
        $registry->query(ShowSettings::class);
        $registry->sensitive(StoredSettings::TABLE, 'secret');
        $registry->query(ListJobs::class);
        $registry->query(ListExtensions::class);
        $registry->query(ListComponentUsages::class);
        $registry->query(ShowQueueStatus::class);
        $registry->query(ShowStatus::class);
        $registry->action(RetryJob::class);
        $registry->action(DeleteJob::class);
        // Mails leave through the queue (decision 0222).
        $registry->job(SendMail::class);
        // SMTP access and a test mail (decision 0269).
        $registry->settings(MailSettings::class);
        $registry->query(ShowMailStatus::class);
        $registry->action(SendTestMail::class);

        // The AI interface; providers come as modules (decision 0294).
        $registry->settings(AiSettings::class);
        $registry->query(ShowAiStatus::class);
        $registry->query(ShowAiUsage::class);
        $registry->action(TestAi::class);
        $registry->listen(ScheduleRan::class, PricesOnSchedule::class);

        // Writing at a field, each a suggestion without saving (decision 0296).
        $registry->action(WriteField::class);
        $registry->action(WriteAltText::class);
        $registry->action(WriteSearchSnippet::class);

        // The connection to the hub, frootbox.de (decision 0284).
        $registry->post(HubCallbackHandler::ROUTE, HubCallbackHandler::class, 'hub.callback');
        $registry->action(ConnectHub::class);
        $registry->action(CompleteHub::class);
        $registry->action(SwitchHub::class);
        $registry->action(DisconnectHub::class);
        $registry->action(UpdateHubKeys::class);
        $registry->query(ShowHub::class);
        $registry->command(HubConnectCommand::class);
        $registry->command(HubDisconnectCommand::class);
        $registry->sensitive(HubConnections::TABLE, 'secret_key');

        // Backups of the database (decision 0183).
        $registry->command(BackupCreateCommand::class);
        $registry->command(BackupListCommand::class);
        $registry->command(BackupPruneCommand::class);
        $registry->command(BackupRestoreCommand::class);
        $registry->action(CreateBackup::class);
        $registry->action(DeleteBackup::class);
        $registry->query(ListBackups::class);
        $registry->query(ListMigrations::class);
        $registry->action(RunMigrations::class);
        $registry->action(ClearCaches::class);
        $registry->listen(ScheduleRan::class, BackupsOnSchedule::class);
        $registry->listen(ScheduleRan::class, PruneOnSchedule::class);
        $registry->listen(ScheduleRan::class, PruneVisitorSessions::class);
        $registry->action(EndSessions::class);
        $registry->command(SessionsEndCommand::class);

        $registry->query(ShowAccount::class);
        $registry->query(ListLanguages::class);
        $registry->query(ListTokens::class);
        $registry->sensitive(Users::TABLE, 'password');

        $registry->migrations('Frootbox\Core\Migrations', \dirname(__DIR__, 2) . '/migrations', 'frootbox/core');
    }
}
