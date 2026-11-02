<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A part of a file as body of a response, read in pieces while it is
 * sent, e.g. the bytes a video player asks for with Range (decision 0177).
 * The file is never loaded into memory as a whole.
 */
final class FileRangeStream implements StreamInterface
{
    /** @var resource|null */
    private $handle;

    private int $position = 0;

    public function __construct(string $path, private readonly int $start, private readonly int $length)
    {
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            throw new RuntimeException(\sprintf('Could not open %s.', $path));
        }

        $this->handle = $handle;
        fseek($handle, $start);
    }

    public function __toString(): string
    {
        $this->rewind();

        return $this->getContents();
    }

    public function close(): void
    {
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return null === $this->handle || $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return null !== $this->handle;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $target = match ($whence) {
            \SEEK_CUR => $this->position + $offset,
            \SEEK_END => $this->length + $offset,
            default => $offset,
        };

        if (null === $this->handle || $target < 0 || $target > $this->length) {
            throw new RuntimeException('Cannot seek there.');
        }

        fseek($this->handle, $this->start + $target);
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is read only.');
    }

    public function isReadable(): bool
    {
        return null !== $this->handle;
    }

    public function read(int $length): string
    {
        $wanted = min($length, $this->length - $this->position);

        if (null === $this->handle || $wanted < 1) {
            return '';
        }

        $data = fread($this->handle, $wanted);
        $data = \is_string($data) ? $data : '';
        $this->position += \strlen($data);

        // A file that got shorter must not keep the loop of the sender going.
        if ('' === $data) {
            $this->position = $this->length;
        }

        return $data;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null === $key ? [] : null;
    }
}
