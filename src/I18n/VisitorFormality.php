<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

use Frootbox\Core\Visitor\VisitorSession;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * The form of address for the current visitor (decision 0316): the one an
 * extension remembered in the visitor session, e.g. the shop for a
 * signed-in customer, else the setting of the website.
 */
final readonly class VisitorFormality
{
    private const string PACKAGE = 'frootbox/core';

    private const string KEY = 'formality';

    /** Services come from the container only when asked, the translator needs this early. */
    public function __construct(private ContainerInterface $container) {}

    public function current(): Formality
    {
        $session = $this->session();
        $value = $session->available() ? $session->get(self::PACKAGE, self::KEY) : null;
        $chosen = \is_string($value) ? Formality::tryFrom($value) : null;

        return $chosen ?? ($this->settings()->formal ? Formality::Formal : Formality::Informal);
    }

    /**
     * Keeps a form of address for this visitor, null forgets it. Without a
     * session (console, API) it does nothing.
     */
    public function remember(?Formality $formality): void
    {
        $session = $this->session();

        if (!$session->available()) {
            return;
        }

        if (null === $formality) {
            $session->remove(self::PACKAGE, self::KEY);
        } else {
            $session->set(self::PACKAGE, self::KEY, $formality->value);
        }
    }

    private function session(): VisitorSession
    {
        $session = $this->container->get(VisitorSession::class);

        return $session instanceof VisitorSession ? $session : throw new LogicException('The visitor session is not available.');
    }

    private function settings(): FormalitySettings
    {
        $settings = $this->container->get(FormalitySettings::class);

        return $settings instanceof FormalitySettings ? $settings : throw new LogicException('The settings of the form of address are not available.');
    }
}
