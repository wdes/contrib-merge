<?php

declare(strict_types = 1);

namespace Tests\Support;

use ConsoleOutput;

/**
 * A ConsoleOutput writing to memory, so a test can read back what the tool
 * told the user.
 */
final class CapturedOutput
{
    public ConsoleOutput $output;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    public function __construct()
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        if ($stdout === false || $stderr === false) {
            throw new \RuntimeException('Unable to open the in-memory streams');
        }
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->output = new ConsoleOutput($this->stdout, $this->stderr);
    }

    public function stdout(): string
    {
        return $this->read($this->stdout);
    }

    public function stderr(): string
    {
        return $this->read($this->stderr);
    }

    public function all(): string
    {
        return $this->stdout() . $this->stderr();
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        return $contents === false ? '' : $contents;
    }
}
