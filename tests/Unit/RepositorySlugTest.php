<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RepositorySlug;

final class RepositorySlugTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function remoteUrls(): array
    {
        return [
            'ssh scp form' => ['git@github.com:phpmyadmin/sql-parser.git', 'phpmyadmin', 'sql-parser'],
            'ssh scp without git' => ['git@github.com:phpmyadmin/sql-parser', 'phpmyadmin', 'sql-parser'],
            'ssh url' => ['ssh://git@github.com/phpmyadmin/sql-parser.git', 'phpmyadmin', 'sql-parser'],
            'https' => ['https://github.com/phpmyadmin/sql-parser.git', 'phpmyadmin', 'sql-parser'],
            'https without git' => ['https://github.com/phpmyadmin/sql-parser', 'phpmyadmin', 'sql-parser'],
            'https with credentials' => ['https://user:token@github.com/owner/repo.git', 'owner', 'repo'],
            'trailing newline' => ["git@github.com:owner/repo.git\n", 'owner', 'repo'],
            'nested group path' => ['https://gitlab.com/group/subgroup/repo.git', 'subgroup', 'repo'],
            'empty' => ['', null, null],
            'no path' => ['https://github.com/', null, null],
        ];
    }

    #[DataProvider('remoteUrls')]
    public function testFromRemoteUrl(string $url, ?string $owner, ?string $repository): void
    {
        $slug = RepositorySlug::fromRemoteUrl($url);

        if ($owner === null) {
            $this->assertNull($slug);

            return;
        }

        $this->assertNotNull($slug);
        $this->assertSame($owner, $slug->owner);
        $this->assertSame($repository, $slug->repository);
    }

    public function testToStringJoinsOwnerAndRepository(): void
    {
        $this->assertSame('phpmyadmin/sql-parser', (string) new RepositorySlug('phpmyadmin', 'sql-parser'));
    }
}
