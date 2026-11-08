<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Support\Message;
use Frootbox\Database\EntityNotFoundException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Runs the test of an extension's settings against its service (decision
 * 0353). The test only reads, so nothing needs undoing; it needs the right
 * to change settings, because it uses their secrets. Whatever the test
 * throws is its answer with ok false, e.g. a certificate that is not
 * trusted.
 *
 * @implements Action<ConnectionResult>
 */
#[AsAction('settings.test', 'Tests the settings of an extension against the service they reach, e.g. an API, and says whether it answers (ok) and what it found or what is wrong; only for settings that offer a test (testable in settings.list)', permission: 'settings.update', undoable: false)]
final readonly class TestConnection implements Action
{
    public function __construct(
        #[Description('The Composer package of the extension, e.g. "acme/shop", with a section "acme/shop.mail"')]
        public string $package,
    ) {}

    public function handle(SettingsClasses $classes, ContainerInterface $container, Translator $translator): ConnectionResult
    {
        $class = $classes->of($this->package) ?? throw new EntityNotFoundException(\sprintf('The extension "%s" has no settings.', $this->package));
        $test = SettingsClasses::attribute($class)->test ?? throw new InvalidInputException('settings.test', ['/package' => 'These settings offer no test.']);
        $started = hrtime(true);

        // A test that fails is an answer, not an error of the action.
        try {
            $runner = $container->get($test);
            \assert($runner instanceof ConnectionTest);
            $message = $runner->run();
            $ok = true;
        } catch (ConnectionFailed $error) {
            $message = $error->text;
            $ok = false;
        } catch (Throwable $error) {
            $message = new Message($error->getMessage());
            $ok = false;
        }

        return new ConnectionResult($this->package, $ok, $translator->get($message->text, $message->parameters), round((hrtime(true) - $started) / 1e9, 2));
    }
}
