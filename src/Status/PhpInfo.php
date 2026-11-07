<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

/**
 * How PHP is set up on this server, like phpinfo() without environment,
 * request and secrets (decision 0335).
 */
final readonly class PhpInfo
{
    /**
     * @param list<string> $scannedFiles further ini files from the scan directory
     * @param list<PhpSetting> $essentials the directives most often asked for
     * @param list<PhpExtension> $extensions by name, filtered by $search
     */
    public function __construct(
        public string $version,
        public string $zendVersion,
        public string $sapi,
        public string $os,
        public ?string $server,
        public ?string $iniFile,
        public array $scannedFiles,
        public PhpOpcache $opcache,
        public array $essentials,
        public array $extensions,
        public ?string $search = null,
    ) {}
}
