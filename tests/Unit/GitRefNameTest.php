<?php

declare(strict_types = 1);

namespace Tests\Unit;

use ContribMergeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use GitRefName;

final class GitRefNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validBranches(): array
    {
        return [
            'simple' => ['main'],
            'maintenance' => ['5.11.x'],
            'with slash' => ['fix/662-cte-nonreserved-keyword-name'],
            'with dots and dashes' => ['issue-671-syntax-check-5.11.x'],
            'nested' => ['feature/a/b'],
        ];
    }

    #[DataProvider('validBranches')]
    public function testValidBranchesPass(string $branch): void
    {
        GitRefName::assertValidBranch($branch);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBranches(): array
    {
        return [
            'empty' => [''],
            'leading dash' => ['--upload-pack=evil'],
            'leading slash' => ['/main'],
            'space' => ['my branch'],
            'semicolon' => ['main; rm -rf /'],
            'double dot' => ['main..evil'],
            'at brace' => ['main@{1}'],
            'double slash' => ['a//b'],
            'trailing slash' => ['main/'],
            'trailing dot' => ['main.'],
            'lock suffix' => ['main.lock'],
            'dollar' => ['main$(id)'],
        ];
    }

    #[DataProvider('invalidBranches')]
    public function testShellLikeBranchesAreRejected(string $branch): void
    {
        $this->expectException(ContribMergeException::class);

        GitRefName::assertValidBranch($branch);
    }

    public function testRemotesRejectSlashes(): void
    {
        $this->expectException(ContribMergeException::class);

        GitRefName::assertValidRemote('origin/evil');
    }
}
