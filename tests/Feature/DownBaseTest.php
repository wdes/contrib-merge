<?php

declare(strict_types = 1);

namespace Tests\Feature;

use BaselineRegenerator;
use ContribMerge;
use ContribMergeException;
use GitRepository;
use GitHubClient;
use Options;
use PHPUnit\Framework\TestCase;
use RepositorySlug;
use SystemProcessRunner;
use Tests\Support\CapturedOutput;
use Tests\Support\FakeHttpTransport;
use Tests\Support\GitScenario;

final class DownBaseTest extends TestCase
{
    private GitScenario $scenario;

    private string $originalPath = '';

    private string $fakeBin = '';

    protected function setUp(): void
    {
        $this->scenario = new GitScenario();
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        if ($this->fakeBin !== '' && is_dir($this->fakeBin)) {
            @unlink($this->fakeBin . '/composer-php7.2');
            @rmdir($this->fakeBin);
        }
        $this->scenario->cleanup();
    }

    public function testReplaysEveryCommitOnTheLowerBranchAndRetargetsThePullRequest(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('issue-671-syntax-check');
        $first = $this->scenario->contributorCommit('Add the syntax check', 'src/check.php', "check\n");
        $second = $this->scenario->contributorCommit('Add the syntax check tests', 'tests/check.php', "tests\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('mobtitude');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 672, 'issue-671-syntax-check', $second);
        $transport->queueJson('GET', '/pulls/672/commits?per_page=250', 200, [
            ['sha' => $first],
            ['sha' => $second],
        ]);
        $this->queueBasePatch($transport, 672);

        $this->buildTool($transport, $out)->run($this->options(672, GitScenario::LOWER));

        $this->assertSame([GitScenario::LOWER], $transport->patchedBases());
        $this->assertSame(
            [['method' => 'PATCH', 'path' => '/repos/phpmyadmin/sql-parser/pulls/672']],
            $transport->mutations()
        );

        $pushed = $this->scenario->headSha($this->scenario->clone, 'mobtitude/issue-671-syntax-check');
        $this->assertNotSame($second, $pushed);
        $this->assertTrue(
            $this->scenario->isAncestor('origin/' . GitScenario::LOWER, $pushed),
            'The pushed branch must be based on the lower branch'
        );
        $this->assertSame("check\n", $this->scenario->fileInClone($pushed, 'src/check.php'));
        $this->assertSame("tests\n", $this->scenario->fileInClone($pushed, 'tests/check.php'));
        $this->assertSame(
            ['Add the syntax check tests', 'Add the syntax check'],
            array_slice($this->scenario->subjects($this->scenario->clone, 'origin/' . GitScenario::LOWER . '..' . $pushed), 0, 2)
        );
        $this->assertSame(1, $this->scenario->countWorktrees(), 'The worktree must be dropped');
        $this->assertStringContainsString('now targets 5.11.x', $out->all());
    }

    public function testANonBaselineConflictAbortsWithoutTouchingAnything(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/parser');
        $sha = $this->scenario->contributorCommit('Change the parser', 'src/parser.php', "contributor\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('contributor');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 900, 'fix/parser', $sha);
        $transport->queueJson('GET', '/pulls/900/commits?per_page=250', 200, [['sha' => $sha]]);

        try {
            $this->buildTool($transport, $out)->run($this->options(900, GitScenario::LOWER));
            $this->fail('A conflict on a real file must abort');
        } catch (ContribMergeException $exception) {
            $this->assertStringContainsString('conflicts on src/parser.php', $exception->getMessage());
        }

        $this->assertSame([], $transport->mutations(), 'Nothing may be pushed or retargeted');
        $this->assertSame($sha, $this->scenario->forkSha('fix/parser'), 'The fork branch must be untouched');
        $this->assertSame(1, $this->scenario->countWorktrees(), 'The worktree must be dropped');
    }

    public function testABaselineConflictIsAutoResolvedAndRegeneratedInItsOwnCommit(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/baseline');
        $sha = $this->scenario->contributorCommit(
            'Fix the parser and its baseline',
            'phpstan-baseline.neon',
            "baseline contributor\n"
        );
        $tip = $this->scenario->contributorCommit('Add the test', 'tests/parser.php', "test\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('contributor');

        $this->installFakeComposer('7.2', "regenerated\n");

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 901, 'fix/baseline', $tip);
        $transport->queueJson('GET', '/pulls/901/commits?per_page=250', 200, [
            ['sha' => $sha],
            ['sha' => $tip],
        ]);
        $this->queueBasePatch($transport, 901);

        $this->buildTool($transport, $out)->run($this->options(901, GitScenario::LOWER));

        $this->assertSame([GitScenario::LOWER], $transport->patchedBases());
        $pushed = $this->scenario->headSha($this->scenario->clone, 'contributor/fix/baseline');
        $this->assertSame("regenerated\n", $this->scenario->fileInClone($pushed, 'phpstan-baseline.neon'));
        $this->assertContains(
            'Regenerate the baselines',
            $this->scenario->subjects($this->scenario->clone, 'origin/' . GitScenario::LOWER . '..' . $pushed)
        );
        $body = $this->scenario->output($this->scenario->clone, ['log', '-1', '--format=%B', $pushed]);
        $this->assertStringContainsString('Signed-off-by:', $body, 'The regenerated baseline commit is signed off');
        $this->assertStringContainsString('Regenerating the baselines with composer-php7.2', $out->all());
    }

    public function testTheBaselinesAreLeftAloneWhenTheComposerBinaryIsMissing(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/baseline');
        $sha = $this->scenario->contributorCommit('Touch the baseline', 'phpstan-baseline.neon', "x\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('contributor');

        // A PATH without the composer wrappers: the binary the branch needs
        // (composer-php7.2 for a "^7.2" requirement) is genuinely absent.
        putenv('PATH=/usr/bin:/bin');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 902, 'fix/baseline', $sha);
        $transport->queueJson('GET', '/pulls/902/commits?per_page=250', 200, [['sha' => $sha]]);

        try {
            $this->buildTool($transport, $out)->run($this->options(902, GitScenario::LOWER));
            $this->fail('A missing composer-php7.2 must abort');
        } catch (ContribMergeException $exception) {
            $this->assertStringContainsString('must be regenerated by hand', $exception->getMessage());
        }

        $this->assertSame([], $transport->mutations());
    }

    public function testAFailedPushRestoresThePullRequestBase(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/push');
        $sha = $this->scenario->contributorCommit('Add a file', 'src/new.php', "new\n");
        // The branch stays checked out in the fork, so the fork refuses the
        // push (receive.denyCurrentBranch) after the base has been retargeted.
        $this->scenario->checkOutBranchInFork('fix/push');
        $this->scenario->addForkRemote('contributor');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 903, 'fix/push', $sha);
        $transport->queueJson('GET', '/pulls/903/commits?per_page=250', 200, [['sha' => $sha]]);
        $this->queueBasePatch($transport, 903, 2);

        try {
            $this->buildTool($transport, $out)->run($this->options(903, GitScenario::LOWER));
            $this->fail('A refused push must abort');
        } catch (ContribMergeException $exception) {
            $this->assertStringContainsString('base was restored to main', $exception->getMessage());
        }

        $this->assertSame(
            [GitScenario::LOWER, GitScenario::MAIN],
            $transport->patchedBases(),
            'The base is retargeted first, then put back when the push fails'
        );
        $this->assertSame($sha, $this->scenario->forkSha('fix/push'), 'The fork branch must be untouched');
    }

    public function testDryRunDoesNotRetargetOrPush(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/dry');
        $sha = $this->scenario->contributorCommit('Add a file', 'src/new.php', "new\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('contributor');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 904, 'fix/dry', $sha);
        $transport->queueJson('GET', '/pulls/904/commits?per_page=250', 200, [['sha' => $sha]]);

        $this->buildTool($transport, $out)->run($this->options(904, GitScenario::LOWER, dryRun: true));

        $this->assertSame([], $transport->mutations(), 'A dry-run must not write to GitHub');
        $this->assertSame($sha, $this->scenario->forkSha('fix/dry'), 'A dry-run must not push');
        $this->assertSame(1, $this->scenario->countWorktrees());
        $this->assertStringContainsString(
            'Would run: PATCH /repos/phpmyadmin/sql-parser/pulls/904 {"base":"5.11.x"}',
            $out->all()
        );
        $this->assertStringContainsString('Would run: git push', $out->all());
    }

    public function testAPullRequestAlreadyOnTheLowerBranchIsRefused(): void
    {
        $this->scenario->seed();
        $this->scenario->contributorBranch('fix/already');
        $sha = $this->scenario->contributorCommit('Add a file', 'src/new.php', "new\n");
        $this->scenario->checkOutMainInFork();
        $this->scenario->addForkRemote('contributor');

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 905, 'fix/already', $sha, baseRef: GitScenario::LOWER);

        try {
            $this->buildTool($transport, $out)->run($this->options(905, GitScenario::LOWER));
            $this->fail('A pull-request already on the lower branch must be refused');
        } catch (ContribMergeException $exception) {
            $this->assertStringContainsString('already targets 5.11.x', $exception->getMessage());
        }

        $this->assertSame([], $transport->mutations(), 'No write to GitHub may be made');
    }

    public function testAMergedPullRequestIsRefused(): void
    {
        $this->scenario->seed();

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 906, 'fix/merged', 'abc123', merged: true);

        $this->expectException(ContribMergeException::class);
        $this->expectExceptionMessage('Already merged');

        $this->buildTool($transport, $out)->run($this->options(906, GitScenario::LOWER));
    }

    public function testAForkWithoutMaintainerEditsIsRefused(): void
    {
        $this->scenario->seed();

        $transport = new FakeHttpTransport();
        $out = new CapturedOutput();
        $this->queuePullRequest($transport, 907, 'issue-671', 'abc123', maintainerCanModify: false);

        try {
            $this->buildTool($transport, $out)->run($this->options(907, GitScenario::LOWER));
            $this->fail('A fork that refuses maintainer edits must be refused');
        } catch (ContribMergeException $exception) {
            $this->assertStringContainsString('does not allow maintainers to push', $exception->getMessage());
        }

        $this->assertSame([], $transport->mutations());
    }

    private function options(int $pr, ?string $lower, bool $dryRun = false): Options
    {
        return new Options($pr, 'phpmyadmin', 'sql-parser', false, false, $lower, $dryRun);
    }

    private function buildTool(FakeHttpTransport $transport, CapturedOutput $out): ContribMerge
    {
        $runner = new SystemProcessRunner();
        $git = new GitRepository($runner, $this->scenario->clone);
        $api = new GitHubClient($transport, new RepositorySlug('phpmyadmin', 'sql-parser'), 'token');

        return new ContribMerge($git, $api, $out->output, new BaselineRegenerator($runner, $out->output));
    }

    private function queuePullRequest(
        FakeHttpTransport $transport,
        int $pr,
        string $headRef,
        string $headSha,
        bool $maintainerCanModify = true,
        string $baseRef = GitScenario::MAIN,
        bool $merged = false,
    ): void {
        $transport->queueJson('GET', '/pulls/' . $pr, 200, [
            'number' => $pr,
            'title' => 'A pull-request',
            'body' => 'Fixes: #' . $pr,
            'merged' => $merged,
            'author_association' => 'CONTRIBUTOR',
            'commits' => 1,
            'additions' => 1,
            'deletions' => 0,
            'changed_files' => 1,
            'maintainer_can_modify' => $maintainerCanModify,
            'base' => [
                'ref' => $baseRef,
                'repo' => [
                    'full_name' => 'phpmyadmin/sql-parser',
                    'ssh_url' => 'git@github.com:phpmyadmin/sql-parser.git',
                ],
            ],
            'head' => [
                'ref' => $headRef,
                'sha' => $headSha,
                'user' => ['login' => 'contributor'],
                'repo' => [
                    'full_name' => 'contributor/sql-parser',
                    'ssh_url' => $this->scenario->fork,
                    'clone_url' => $this->scenario->fork,
                ],
            ],
        ]);
    }

    private function queueBasePatch(FakeHttpTransport $transport, int $pr, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $transport->queueJson('PATCH', '/pulls/' . $pr, 200, ['number' => $pr]);
        }
    }

    private function installFakeComposer(string $phpVersion, string $baselineContents): void
    {
        $this->fakeBin = sys_get_temp_dir() . '/contrib-merge-bin-' . bin2hex(random_bytes(4));
        if (! mkdir($this->fakeBin, 0o700, true) && ! is_dir($this->fakeBin)) {
            $this->fail('Unable to create ' . $this->fakeBin);
        }
        $binary = $this->fakeBin . '/composer-php' . $phpVersion;
        $script = "#!/bin/sh\ncat > phpstan-baseline.neon <<'CONTRIB_MERGE_EOF'\n"
            . $baselineContents
            . "CONTRIB_MERGE_EOF\n";
        file_put_contents($binary, $script);
        chmod($binary, 0o755);
        putenv('PATH=' . $this->fakeBin . ':' . $this->originalPath);
    }
}
