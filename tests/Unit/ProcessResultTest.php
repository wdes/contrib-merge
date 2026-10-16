<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProcessResult;

final class ProcessResultTest extends TestCase
{
    public function testCommandLineLeavesPlainArgumentsAlone(): void
    {
        $result = new ProcessResult(['git', 'fetch', 'origin'], 0, '', '');

        $this->assertSame('git fetch origin', $result->commandLine());
    }

    public function testCommandLineQuotesArgumentsWithSpaces(): void
    {
        $result = new ProcessResult(['git', 'checkout', '--theirs', '--', 'my file.neon'], 0, '', '');

        $this->assertSame("git checkout --theirs -- 'my file.neon'", $result->commandLine());
    }

    public function testCommandLineQuotesLeaseAndRefSpec(): void
    {
        $result = new ProcessResult(
            ['git', 'push', '--force-with-lease=refs/heads/fix:abc123', 'fork', 'HEAD:refs/heads/fix'],
            0,
            '',
            ''
        );

        $this->assertSame(
            'git push --force-with-lease=refs/heads/fix:abc123 fork HEAD:refs/heads/fix',
            $result->commandLine()
        );
    }

    public function testFailureDetailsPrefersStderr(): void
    {
        $result = new ProcessResult(['git', 'cherry-pick', 'abc'], 1, 'stdout noise', 'CONFLICT in file');

        $details = $result->failureDetails();

        $this->assertStringContainsString('exit code 1', $details);
        $this->assertStringContainsString('CONFLICT in file', $details);
        $this->assertStringNotContainsString('stdout noise', $details);
        $this->assertFalse($result->isOk());
    }

    public function testFailureDetailsFallsBackToStdout(): void
    {
        $result = new ProcessResult(['git', 'merge', 'x'], 1, 'only stdout', '');

        $this->assertStringContainsString('only stdout', $result->failureDetails());
    }
}
