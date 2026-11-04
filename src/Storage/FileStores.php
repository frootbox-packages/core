<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use Psr\Container\ContainerInterface;

/**
 * The two stores of the installation (decision 0268), chosen in the .env:
 *
 *     STORAGE_DSN="s3://<key>:<secret>@<host>/<bucket>?region=…"
 *     PUBLIC_STORAGE_DSN="s3://<key>:<secret>@<host>/<bucket>?region=…&url=https://media.example.com"
 *
 * Without a value they are the folders storage/ and public/ (decision
 * 0075). A store is made on first use.
 */
final class FileStores
{
    /** @var array<string, FileStore> */
    private array $stores = [];

    public function __construct(
        private readonly Config $config,
        private readonly ProjectPaths $paths,
        private readonly Registry $registry,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * Originals of media and files from forms, never public.
     */
    public function storage(): FileStore
    {
        return $this->stores[StoreRole::Storage->value] ??= $this->create(StoreRole::Storage);
    }

    /**
     * Sizes and copies of public media, loaded by browsers.
     */
    public function public(): FileStore
    {
        return $this->stores[StoreRole::Public->value] ??= $this->create(StoreRole::Public);
    }

    public function of(StoreRole $role): FileStore
    {
        return StoreRole::Storage === $role ? $this->storage() : $this->public();
    }

    /**
     * Whether a store is not the folder of the installation.
     */
    public function isRemote(StoreRole $role): bool
    {
        return null !== $this->dsn($role);
    }

    /**
     * The folder of the installation for a store, whatever the .env says,
     * e.g. to move files into a bucket.
     */
    public function local(StoreRole $role): LocalStore
    {
        return StoreRole::Storage === $role
            ? new LocalStore($this->paths->storage(), 'storage/')
            : new LocalStore($this->paths->public(), 'public/');
    }

    /**
     * The folder for copies on disk, below var/storage/.
     */
    public function cache(StoreRole $role): string
    {
        return $this->paths->var('storage/' . $role->value);
    }

    private function create(StoreRole $role): FileStore
    {
        $dsn = $this->dsn($role);

        if (null === $dsn) {
            return $this->local($role);
        }

        foreach ($this->registry->items(StorageDriver::class) as $driver) {
            if ($driver->scheme === $dsn->scheme) {
                $factory = $this->container->get($driver->factory);

                if (!$factory instanceof StoreFactory) {
                    throw new StorageException(\sprintf('%s is no %s.', $driver->factory, StoreFactory::class));
                }

                return $factory->create($dsn, $role, $this->cache($role));
            }
        }

        throw new StorageException(\sprintf(
            '%s uses "%s://", which no extension knows. For S3: composer require frootbox/storage-s3.',
            $role->variable(),
            $dsn->scheme,
        ));
    }

    private function dsn(StoreRole $role): ?Dsn
    {
        $value = trim($this->config->env($role->variable()) ?? '');

        if ('' === $value) {
            return null;
        }

        $dsn = Dsn::parse($value);

        return \in_array($dsn->scheme, ['file', 'local'], true) ? null : $dsn;
    }
}
