<?php

declare(strict_types = 1);

namespace Tests\Unit;

use ContribMergeException;
use PHPUnit\Framework\TestCase;
use GitHubClient;
use RepositorySlug;
use Tests\Support\CapturedOutput;
use Tests\Support\FakeHttpTransport;

final class GitHubClientTest extends TestCase
{
    private FakeHttpTransport $transport;

    private CapturedOutput $captured;

    private RepositorySlug $slug;

    protected function setUp(): void
    {
        $this->transport = new FakeHttpTransport();
        $this->captured = new CapturedOutput();
        $this->slug = new RepositorySlug('phpmyadmin', 'sql-parser');
    }

    public function testPullRequestIsParsed(): void
    {
        $this->transport->queueJson('GET', '/pulls/667', 200, [
            'number' => 667,
            'title' => 'Accept non-reserved keyword',
            'body' => 'Fixes: #662',
            'merged' => false,
            'author_association' => 'CONTRIBUTOR',
            'commits' => 1,
            'additions' => 12,
            'deletions' => 3,
            'changed_files' => 2,
            'maintainer_can_modify' => true,
            'base' => ['ref' => 'master', 'repo' => ['full_name' => 'phpmyadmin/sql-parser', 'ssh_url' => 'git@github.com:phpmyadmin/sql-parser.git']],
            'head' => [
                'ref' => 'fix/662-cte',
                'sha' => 'abc123',
                'user' => ['login' => 'youdie006'],
                'repo' => [
                    'full_name' => 'youdie006/phpmyadmin-sql-parser',
                    'ssh_url' => 'git@github.com:youdie006/phpmyadmin-sql-parser.git',
                    'clone_url' => 'https://github.com/youdie006/phpmyadmin-sql-parser.git',
                ],
            ],
        ]);

        $pr = $this->client()->pullRequest(667);

        $this->assertSame('master', $pr->baseRef);
        $this->assertSame('fix/662-cte', $pr->headRef);
        $this->assertSame('abc123', $pr->headSha);
        $this->assertSame('youdie006', $pr->headUserName);
        $this->assertTrue($pr->maintainerCanModify);
        $this->assertTrue($pr->isFromFork());
        $this->assertSame('git@github.com:youdie006/phpmyadmin-sql-parser.git', $pr->headSshUrl);
    }

    public function testPullRequestCommitShasAreReadInOrder(): void
    {
        $this->transport->queueJson('GET', '/pulls/657/commits', 200, [
            ['sha' => 'aaa111'],
            ['sha' => 'bbb222'],
            ['not' => 'a commit'],
        ]);

        $this->assertSame(['aaa111', 'bbb222'], $this->client()->pullRequestCommitShas(657));
    }

    public function testUpdatePullRequestBaseSendsAPatch(): void
    {
        $this->transport->queueJson('PATCH', '/pulls/667', 200, ['number' => 667]);

        $this->client()->updatePullRequestBase(667, '5.11.x');

        $this->assertSame(['5.11.x'], $this->transport->patchedBases());
        $this->assertSame(
            [['method' => 'PATCH', 'path' => '/repos/phpmyadmin/sql-parser/pulls/667']],
            $this->transport->mutations()
        );
    }

    public function testANon2xxResponseIsReported(): void
    {
        $this->transport->queueJson('GET', '/pulls/672', 404, [
            'message' => 'Not Found',
            'documentation_url' => 'https://docs.github.com/rest/pulls/pulls#get-a-pull-request',
        ]);

        $this->expectException(ContribMergeException::class);
        $this->expectExceptionMessage('GET /repos/phpmyadmin/sql-parser/pulls/672 failed with HTTP 404: Not Found');

        $this->client()->pullRequest(672);
    }

    public function testTheTokenIsSentButNeverPrinted(): void
    {
        $this->transport->queueJson('GET', '/pulls/1', 500, ['message' => 'boom']);

        try {
            $this->client('ghp_secret')->pullRequest(1);
            $this->fail('A 500 must throw');
        } catch (ContribMergeException $exception) {
            $this->assertStringNotContainsString('ghp_secret', $exception->getMessage());
            $this->assertStringNotContainsString('ghp_secret', $this->captured->all());
        }
    }

    public function testAnAnonymousClientSendsNoAuthorizationHeader(): void
    {
        $transport = new class () implements \HttpTransport {
            /** @var array<string, string> */
            public array $headers = [];

            public function send(string $method, string $url, array $headers, ?string $body = null): \HttpResponse
            {
                $this->headers = $headers;

                return new \HttpResponse(200, '{}');
            }
        };

        $client = new GitHubClient($transport, $this->slug, null);
        $client->pullRequest(1);

        $this->assertArrayNotHasKey('Authorization', $transport->headers);
        $this->assertArrayHasKey(
            'User-Agent',
            $transport->headers,
            'GitHub answers 403 to any request without a User-Agent'
        );
    }

    private function client(string $token = ''): GitHubClient
    {
        return new GitHubClient($this->transport, $this->slug, $token);
    }
}
