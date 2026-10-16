<?php

declare(strict_types = 1);

namespace Tests\Unit;

use ContribMergeException;
use PHPUnit\Framework\TestCase;
use PullRequestLinks;

final class PullRequestLinksTest extends TestCase
{
    public function testExtractsFixesAndCloses(): void
    {
        $body = <<<MD
        This fixes the parser.
        Fixes: #123
        fixes #124
        Closes: #125
        closes #126
        MD;

        $links = PullRequestLinks::extract($body);

        $this->assertSame(['123', '124'], $links['fixes']);
        $this->assertSame(['125', '126'], $links['closes']);
        $this->assertSame([], $links['custom']);
    }

    public function testNoMatchYieldsEmptyLists(): void
    {
        $links = PullRequestLinks::extract('nothing to see here');

        $this->assertSame([], $links['fixes']);
        $this->assertSame([], $links['closes']);
        $this->assertSame([], $links['custom']);
    }

    public function testCustomFormatUsesNamedGroups(): void
    {
        $pattern = '/(?<Thanks>thanks: @[\w-]+)/i';
        $links = PullRequestLinks::extract('Thanks: @williamdes and thanks: @phpmyadmin', $pattern);

        $this->assertSame(['Thanks: @williamdes', 'thanks: @phpmyadmin'], $links['custom']['Thanks']);
    }

    public function testAnInvalidCustomFormatIsReported(): void
    {
        $this->expectException(ContribMergeException::class);
        $this->expectExceptionMessage('not a valid regular expression');

        PullRequestLinks::extract('body', '/(unclosed');
    }
}
