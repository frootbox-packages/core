<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Support\Message;

/**
 * Documents of the media library (decision 0179): PDF, text and CSV,
 * the formats of Office and OpenDocument, and ZIP, kept as uploaded.
 *
 * The content decides, the name only tells apart what looks the same
 * inside: a PDF starts with "%PDF-", OpenDocument names its type in the
 * file "mimetype" at the start of the ZIP, Office files and ZIP archives
 * are ZIP files with the matching extension, text and CSV are UTF-8
 * without control characters. HTML, SVG, scripts and programs never
 * count as documents, whatever their name.
 */
final class Documents
{
    /** Media types that can be uploaded, with the extension of their files. */
    public const array TYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
    ];

    /** How much of a text file is checked: the start tells, the whole file costs. */
    private const int TEXT_SAMPLE = 65536;

    /**
     * The media type of a document, or null if the file is none.
     *
     * @param string $name The name of the uploaded file, e.g. "price-list.xlsx"
     */
    public static function type(string $path, string $name): ?string
    {
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            return null;
        }

        try {
            $head = (string) fread($handle, 256);
            $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

            if (str_starts_with($head, '%PDF-')) {
                return 'application/pdf';
            }

            if (str_starts_with($head, "PK\x03\x04")) {
                return self::zipType($head, $extension);
            }

            if ('txt' !== $extension && 'csv' !== $extension) {
                return null;
            }

            rewind($handle);

            return self::isText((string) fread($handle, self::TEXT_SAMPLE)) ? ('csv' === $extension ? 'text/csv' : 'text/plain') : null;
        } finally {
            fclose($handle);
        }
    }

    public static function isDocument(string $path, string $name): bool
    {
        return null !== self::type($path, $name);
    }

    public static function isDocumentType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * A short name of the format, e.g. "PDF" or "XLSX".
     */
    public static function format(string $type): string
    {
        return strtoupper(self::TYPES[$type] ?? '');
    }

    public static function notADocument(): Message
    {
        return new Message('The file is no document (PDF, Word, Excel, PowerPoint, OpenDocument, text, CSV or ZIP).');
    }

    /**
     * Copies a document as original.
     *
     * @return array{type: string, codec: string, width: int, height: int, size: int}|Message The facts or why not
     */
    public function prepare(string $source, string $target, string $name): array|Message
    {
        $type = self::type($source, $name);

        if (null === $type) {
            return self::notADocument();
        }

        $folder = \dirname($target);

        if ((!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) || !@copy($source, $target)) {
            return new Message('The document could not be saved.');
        }

        return ['type' => $type, 'codec' => '', 'width' => 0, 'height' => 0, 'size' => (int) filesize($target)];
    }

    /**
     * OpenDocument by the file "mimetype" it starts with, Office and ZIP
     * by the extension.
     */
    private static function zipType(string $head, string $extension): ?string
    {
        // The local header: lengths of name and extra field at 26 and 28, the name at 30, the data after both.
        $lengths = unpack('vname/vextra', substr($head, 26, 4));
        $nameLength = \is_array($lengths) && \is_int($lengths['name'] ?? null) ? $lengths['name'] : 0;
        $extraLength = \is_array($lengths) && \is_int($lengths['extra'] ?? null) ? $lengths['extra'] : 0;

        if ('mimetype' === substr($head, 30, $nameLength)) {
            $declared = substr($head, 30 + $nameLength + $extraLength, 60);

            foreach (['odt', 'ods', 'odp'] as $open) {
                $type = (string) array_search($open, self::TYPES, true);

                if (str_starts_with($declared, $type) && $open === $extension) {
                    return $type;
                }
            }

            return null;
        }

        return match ($extension) {
            'docx', 'xlsx', 'pptx', 'zip' => (string) array_search($extension, self::TYPES, true),
            default => null,
        };
    }

    /**
     * UTF-8 without control characters besides tab and line breaks; a
     * character cut at the end of the sample counts.
     */
    private static function isText(string $sample): bool
    {
        if ('' === $sample || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sample)) {
            return false;
        }

        for ($cut = 0; $cut < 4 && \strlen($sample) > $cut; ++$cut) {
            if (mb_check_encoding(substr($sample, 0, \strlen($sample) - $cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }
}
