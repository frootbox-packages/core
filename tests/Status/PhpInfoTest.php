<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Status;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Status\PhpExtension;
use Frootbox\Core\Status\PhpInfo;
use Frootbox\Core\Status\PhpOpcache;
use Frootbox\Core\Status\PhpSetting;
use Frootbox\Core\Status\ShowPhpInfo;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShowPhpInfo::class)]
#[CoversClass(PhpInfo::class)]
#[CoversClass(PhpExtension::class)]
#[CoversClass(PhpSetting::class)]
#[CoversClass(PhpOpcache::class)]
final class PhpInfoTest extends NotesTestCase
{
    public function testDescribesPhpWithItsExtensionsAndDirectives(): void
    {
        $info = $this->runner()->ask(new ShowPhpInfo());

        self::assertSame(\PHP_VERSION, $info->version);
        self::assertSame(\PHP_SAPI, $info->sapi);
        self::assertSame(zend_version(), $info->zendVersion);
        self::assertSame('memory_limit', $info->essentials[0]->name);
        self::assertSame(\ini_get('memory_limit'), $info->essentials[0]->value);

        $core = $this->extension($info, 'Core');
        self::assertSame(\PHP_VERSION, $core->version);
        self::assertContains('memory_limit', array_map(static fn(PhpSetting $setting): string => $setting->name, $core->settings));

        $json = $this->extension($info, 'json');
        self::assertSame([], $json->settings, 'Extensions without directives are listed too.');
    }

    public function testShowsWhatChangedBelowThePhpIni(): void
    {
        $before = \ini_get('precision');
        ini_set('precision', '10');

        try {
            $precision = $this->setting($this->runner()->ask(new ShowPhpInfo('precision')), 'Core', 'precision');
            self::assertSame('10', $precision->value);
            self::assertTrue($precision->changed);
        } finally {
            ini_set('precision', $before);
        }
    }

    public function testSearchKeepsMatchingExtensionsAndDirectives(): void
    {
        $info = $this->runner()->ask(new ShowPhpInfo('UPLOAD_MAX'));

        self::assertSame('UPLOAD_MAX', $info->search);
        self::assertSame(['Core'], array_map(static fn(PhpExtension $extension): string => $extension->name, $info->extensions));
        self::assertSame(['upload_max_filesize'], array_map(static fn(PhpSetting $setting): string => $setting->name, $info->extensions[0]->settings));
        self::assertNotEmpty($info->essentials, 'The essentials stay.');

        $date = $this->runner()->ask(new ShowPhpInfo('date'));
        self::assertGreaterThan(1, \count($this->extension($date, 'date')->settings), 'A matching extension keeps all its directives.');

        self::assertCount(\count($this->runner()->ask(new ShowPhpInfo())->extensions), $this->runner()->ask(new ShowPhpInfo('  '))->extensions);
    }

    public function testMasksSecrets(): void
    {
        self::assertSame(ShowPhpInfo::MASK, ShowPhpInfo::mask('mysqli.default_pw', 'kaffeebohne42'));
        self::assertSame(ShowPhpInfo::MASK, ShowPhpInfo::mask('acme.api_key', 'abc'));
        self::assertSame(ShowPhpInfo::MASK, ShowPhpInfo::mask('acme.secret', 'abc'));
        self::assertSame('', ShowPhpInfo::mask('mysqli.default_pw', ''), 'Nothing set stays visible as nothing.');
        self::assertSame('tcp://redis:6379?weight=1&auth=***', ShowPhpInfo::mask('session.save_path', 'tcp://redis:6379?weight=1&auth=kaffeebohne42'));
        self::assertSame('redis://***@redis:6379/0', ShowPhpInfo::mask('session.save_path', 'redis://user:kaffeebohne42@redis:6379/0'));
        self::assertSame('/var/lib/php/sessions', ShowPhpInfo::mask('session.save_path', '/var/lib/php/sessions'));
        self::assertSame('128M', ShowPhpInfo::mask('memory_limit', '128M'));
        self::assertSame('1', ShowPhpInfo::mask('session.use_only_cookies', '1'), 'Not every name with a dot is a secret.');
    }

    public function testShowsNoEnvironment(): void
    {
        putenv('FROOTBOX_TEST_SECRET=kaffeebohne42');

        try {
            self::assertStringNotContainsString('kaffeebohne42', (string) json_encode($this->runner()->ask(new ShowPhpInfo())));
        } finally {
            putenv('FROOTBOX_TEST_SECRET');
        }
    }

    public function testOnlySuperusersSeeIt(): void
    {
        $editor = $this->runner()->run(new CreateUser('ed@example.com', 'Ed', 'kaffeebohne42', ['admin']))->user;

        $this->expectException(AccessDeniedException::class);
        $this->runner()->ask(new ShowPhpInfo(), new Context(Actor::user($editor->id, $editor->name), Channel::Api));
    }

    private function extension(PhpInfo $info, string $name): PhpExtension
    {
        foreach ($info->extensions as $extension) {
            if ($extension->name === $name) {
                return $extension;
            }
        }

        self::fail('Missing extension ' . $name);
    }

    private function setting(PhpInfo $info, string $extension, string $name): PhpSetting
    {
        foreach ($this->extension($info, $extension)->settings as $setting) {
            if ($setting->name === $name) {
                return $setting;
            }
        }

        self::fail('Missing directive ' . $name);
    }
}
