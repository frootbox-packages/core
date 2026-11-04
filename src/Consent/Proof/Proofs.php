<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Consent\ConsentSettings;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Database\Ulid;
use Frootbox\Database\Values;

/**
 * The kept proofs of consent (decision 0264). On every run of the
 * scheduler the logged ones move to the table and old ones go.
 * Bookkeeping like the statistics, not an action: nothing to undo.
 */
final readonly class Proofs
{
    public const string TABLE = 'consent_proofs';

    public function __construct(
        private ProofLog $log,
        private Connection $connection,
        private ConsentSettings $settings,
    ) {}

    public function __invoke(ScheduleRan $event): void
    {
        $this->run();
    }

    public function run(DateTimeImmutable $now = new DateTimeImmutable()): void
    {
        $this->log->collect($this->keep(...));
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE created_at < ?',
            [$now->modify(\sprintf('-%d days', $this->settings->proofDays))->format(Values::DATE_FORMAT)],
        );
    }

    /**
     * @return list<Proof> The proofs of one choice, the newest first
     */
    public function of(string $consentId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT consent_id, version, choices, created_at FROM ' . self::TABLE . ' WHERE consent_id = ? ORDER BY created_at DESC', [strtolower(trim($consentId))]);
        $proofs = [];

        foreach ($rows as $row) {
            $choices = \is_string($row['choices']) ? json_decode($row['choices'], true) : null;
            $proof = \is_string($row['created_at']) ? Proof::fromBrowser(['id' => $row['consent_id'], 'version' => $row['version'], 'services' => $choices], $row['created_at']) : null;

            if (null !== $proof) {
                $proofs[] = $proof;
            }
        }

        return $proofs;
    }

    /**
     * @param iterable<Proof> $proofs
     */
    private function keep(iterable $proofs): void
    {
        foreach ($proofs as $proof) {
            $this->connection->insert(self::TABLE, [
                'id' => Ulid::generate(),
                'consent_id' => $proof->consentId,
                'version' => $proof->version,
                'choices' => json_encode($proof->choices, \JSON_UNESCAPED_SLASHES),
                'created_at' => $proof->at,
            ]);
        }
    }
}
