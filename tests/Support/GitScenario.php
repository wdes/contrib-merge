<?php

declare(strict_types = 1);

namespace Tests\Support;

use ProcessResult;
use RuntimeException;
use SystemProcessRunner;

/**
 * A throwaway three-repo playground built with the real git binary:
 *
 *   base/   the upstream repository, with `main` advanced past `origin/5.11.x`
 *   clone/  the operator's working clone of base/ (adds a remote per fork)
 *   fork/   a contributor's clone of base/, carrying their pull-request branch
 *
 * Everything lives under one temp directory keyed by a random suffix; call
 * cleanup() to remove it.
 */
final class GitScenario
{
    public const MAIN = 'main';

    public const LOWER = '5.11.x';

    public const DEFAULT_COMPOSER = '{"require":{"php":"^7.2"},"scripts":{"update:baselines":["@php phpstan analyse --generate-baseline"]}}';

    public string $root;

    public string $base;

    public string $clone;

    public string $fork;

    private SystemProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new SystemProcessRunner();
        $this->root = sys_get_temp_dir() . '/contrib-merge-scenario-' . bin2hex(random_bytes(5));
        $this->base = $this->root . '/base';
        $this->clone = $this->root . '/clone';
        $this->fork = $this->root . '/fork';

        foreach ([$this->root, $this->base, $this->fork] as $directory) {
            if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create ' . $directory);
            }
        }
    }

    /**
     * @param array<mixed, mixed>|null $lowerComposer the composer.json written on
     *                                                both branches, or null for
     *                                                the default one
     */
    public function seed(?array $lowerComposer = null): void
    {
        $composer = $lowerComposer === null
            ? self::DEFAULT_COMPOSER
            : (string) json_encode($lowerComposer);

        $this->mustGit($this->base, ['init', '-q', '-b', self::MAIN]);
        $this->configureIdentity($this->base);

        $this->write($this->base, 'src/parser.php', "main\n");
        $this->write($this->base, 'phpstan-baseline.neon', "baseline main\n");
        $this->write($this->base, 'composer.json', $composer . "\n");
        $this->write($this->base, 'README.md', "readme\n");
        $this->mustGit($this->base, ['add', '-A']);
        $this->mustGit($this->base, ['commit', '-q', '-m', 'Initial commit']);

        $this->mustGit($this->base, ['checkout', '-q', '-b', self::LOWER]);
        $this->write($this->base, 'src/parser.php', "511\n");
        $this->write($this->base, 'phpstan-baseline.neon', "baseline 511\n");
        $this->mustGit($this->base, ['commit', '-q', '-am', 'Maintenance branch divergence']);

        $this->mustGit($this->base, ['checkout', '-q', self::MAIN]);
        $this->write($this->base, 'src/parser.php', "main advanced\n");
        $this->mustGit($this->base, ['commit', '-q', '-am', 'Main advanced past the maintenance branch']);

        $this->clone = $this->root . '/clone';
        $this->mustRun(['git', 'clone', '-q', $this->base, $this->clone]);
        $this->configureIdentity($this->clone);

        $this->mustRun(['git', 'clone', '-q', $this->base, $this->fork]);
        $this->configureIdentity($this->fork);
    }

    public function contributorBranch(string $name, string $from = 'origin/' . self::MAIN): void
    {
        $this->mustGit($this->fork, ['checkout', '-q', '-b', $name, $from]);
    }

    public function contributorCommit(string $subject, string $path, string $contents): string
    {
        $this->write($this->fork, $path, $contents);
        $this->mustGit($this->fork, ['add', '--', $path]);
        $this->mustGit($this->fork, ['commit', '-q', '-m', $subject]);

        return $this->headSha($this->fork);
    }

    /**
     * Register the fork as a remote of the clone, the way the tool would.
     */
    public function addForkRemote(string $remoteName): void
    {
        $this->mustGit($this->clone, ['remote', 'add', $remoteName, $this->fork]);
        $this->mustGit($this->clone, ['fetch', '-q', $remoteName]);
    }

    public function headSha(string $repository, string $ref = 'HEAD'): string
    {
        return trim($this->mustGit($repository, ['rev-parse', $ref]));
    }

    public function forkSha(string $branch): string
    {
        return $this->headSha($this->fork, $branch);
    }

    /**
     * Leave the contributor's branch checked out in the fork, which makes the
     * fork refuse a push to it (`receive.denyCurrentBranch`).
     */
    public function checkOutBranchInFork(string $branch): void
    {
        $this->mustGit($this->fork, ['checkout', '-q', $branch]);
    }

    public function checkOutMainInFork(): void
    {
        $this->mustGit($this->fork, ['checkout', '-q', self::MAIN]);
    }

    public function fileInClone(string $revision, string $path): string
    {
        return $this->mustGit($this->clone, ['show', $revision . ':' . $path]);
    }

    /**
     * @param string[] $arguments
     */
    public function output(string $repository, array $arguments): string
    {
        return $this->mustGit($repository, $arguments);
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->run($this->clone, ['merge-base', '--is-ancestor', $ancestor, $descendant])->isOk();
    }

    public function countWorktrees(): int
    {
        $lines = array_filter(explode("\n", trim($this->mustGit($this->clone, ['worktree', 'list']))));

        return count($lines);
    }

    /**
     * @return string[]
     */
    public function subjects(string $repository, string $range): array
    {
        $output = trim($this->mustGit($repository, ['log', '--format=%s', $range]));

        return $output === '' ? [] : explode("\n", $output);
    }

    public function cleanup(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    private function configureIdentity(string $repository): void
    {
        $this->mustGit($repository, ['config', 'user.email', 'test@contrib-merge.local']);
        $this->mustGit($repository, ['config', 'user.name', 'Contrib Merge Test']);
        $this->mustGit($repository, ['config', 'commit.gpgsign', 'false']);
        $this->mustGit($repository, ['config', 'tag.gpgsign', 'false']);
    }

    private function write(string $repository, string $path, string $contents): void
    {
        $full = $repository . '/' . $path;
        $directory = dirname($full);
        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory);
        }
        file_put_contents($full, $contents);
    }

    /**
     * @param string[] $arguments
     */
    private function mustGit(string $repository, array $arguments): string
    {
        $result = $this->run($repository, $arguments);
        if (! $result->isOk()) {
            throw new RuntimeException($result->failureDetails());
        }

        return $result->stdout;
    }

    /**
     * @param string[] $arguments
     */
    private function run(string $repository, array $arguments): ProcessResult
    {
        return $this->runner->run(array_merge(['git', '-C', $repository], $arguments));
    }

    /**
     * @param string[] $command
     */
    private function mustRun(array $command): void
    {
        $result = $this->runner->run($command);
        if (! $result->isOk()) {
            throw new RuntimeException($result->failureDetails());
        }
    }

    private function removeDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } elseif ($item instanceof \SplFileInfo) {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
