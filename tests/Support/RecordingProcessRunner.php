<?php

declare(strict_types = 1);

namespace Tests\Support;

use ContribMergeException;
use ProcessResult;
use ProcessRunner;

/**
 * A ProcessRunner that records every call and replays the exit code and the
 * output of a real command, so the orchestration can be driven without a git
 * binary. Argument lists are kept verbatim -- the tests assert on them.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<array{command: string[], cwd: string|null}> */
    public array $calls = [];

    /** @var array<string, ProcessResult> */
    private array $responses = [];

    /**
     * @param string[] $arguments
     */
    public function queueResult(array $arguments, int $exitCode, string $stdout = '', string $stderr = ''): void
    {
        $this->responses[$this->key($arguments)] = new ProcessResult($arguments, $exitCode, $stdout, $stderr);
    }

    public function run(array $command, ?string $cwd = null): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd];
        $key = $this->key($command);
        if (isset($this->responses[$key])) {
            return $this->responses[$key];
        }

        return new ProcessResult($command, 0, '', '');
    }

    /**
     * @return list<string[]>
     */
    public function commands(): array
    {
        return array_map(static fn (array $call): array => $call['command'], $this->calls);
    }

    /**
     * @param string[] $needle
     */
    public function hasCommand(array $needle): bool
    {
        foreach ($this->calls as $call) {
            if (array_slice($call['command'], -count($needle)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]|null
     */
    public function commandContaining(string $fragment): ?array
    {
        foreach ($this->calls as $call) {
            if (str_contains(implode(' ', $call['command']), $fragment)) {
                return $call['command'];
            }
        }

        return null;
    }

    public function assertNothingRan(): void
    {
        if ($this->calls !== []) {
            $first = $this->calls[0]['command'];

            throw new ContribMergeException('Expected no command, but ran: ' . implode(' ', $first));
        }
    }

    /**
     * @param string[] $arguments
     */
    private function key(array $arguments): string
    {
        return implode("\0", $arguments);
    }
}
