<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

/**
 * A kind of store of an extension (decision 0268):
 *
 *     $registry->add(StorageDriver::class, new StorageDriver('s3', S3Stores::class));
 *
 * It serves STORAGE_DSN and PUBLIC_STORAGE_DSN with this scheme.
 */
final readonly class StorageDriver
{
    /**
     * @param class-string<StoreFactory> $factory
     */
    public function __construct(
        public string $scheme,
        public string $factory,
    ) {}
}
