<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Log;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\ProjectPaths;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Builds the PSR-3 logger of an installation (Monolog).
 *
 * Logs go to var/log/<environment>-<date>.log, one file per day, kept for
 * 14 days. If var/log is not writable, they go to the PHP error log.
 *
 * - dev: everything from log.level (default debug) is written.
 * - prod: everything from log.level (default warning) is written. When an
 *   error occurs, the lower messages of the same request are written too,
 *   so the log shows how it came to the error.
 */
final class LoggerFactory
{
    public const string CHANNEL = 'frootbox';

    private const int MAX_FILES = 14;

    private const int BUFFER_SIZE = 100;

    public static function create(Config $config, ProjectPaths $paths): Logger
    {
        $development = $config->environment->isDevelopment();
        $level = self::level($config->string('log.level', $development ? 'debug' : 'warning'));

        $directory = $paths->var('log');
        $handler = self::isWritable($directory)
            ? new RotatingFileHandler($directory . '/' . $config->environment->value . '.log', self::MAX_FILES)
            : new ErrorLogHandler();
        self::format($handler);

        if ($development) {
            $handler->setLevel($level);
        } else {
            $handler = new FingersCrossedHandler(
                $handler,
                new ErrorLevelActivationStrategy(Level::Error),
                bufferSize: self::BUFFER_SIZE,
                passthruLevel: $level,
            );
        }

        return new Logger(self::CHANNEL, [$handler], [new PsrLogMessageProcessor()]);
    }

    private static function level(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if (0 === strcasecmp($level->name, $name)) {
                return $level;
            }
        }

        throw new ConfigException(\sprintf('Unknown log.level "%s". Use debug, info, notice, warning, error, critical, alert or emergency.', $name));
    }

    private static function isWritable(string $directory): bool
    {
        return (is_dir($directory) || @mkdir($directory, 0o775, true)) && is_writable($directory);
    }

    /**
     * One line per message, with the stack trace of exceptions.
     */
    private static function format(HandlerInterface $handler): void
    {
        if ($handler instanceof FormattableHandlerInterface) {
            $handler->setFormatter(new LineFormatter(ignoreEmptyContextAndExtra: true, includeStacktraces: true));
        }
    }
}
