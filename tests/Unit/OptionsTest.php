<?php

declare(strict_types = 1);

namespace Tests\Unit;

use ContribMergeUsageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Options;

final class OptionsTest extends TestCase
{
    public function testParsesPullRequestAndMode(): void
    {
        $options = Options::fromGetoptArray(['pr' => '123', 'merge' => false]);

        $this->assertSame(123, $options->pr);
        $this->assertTrue($options->merge);
        $this->assertFalse($options->changeLog);
        $this->assertNull($options->downBase);
        $this->assertFalse($options->dryRun);
    }

    public function testOrgWinsOverOwner(): void
    {
        $options = Options::fromGetoptArray(['pr' => '1', 'owner' => 'wdes', 'org' => 'phpmyadmin']);

        $this->assertSame('phpmyadmin', $options->owner);
    }

    public function testOwnerIsUsedWhenNoOrg(): void
    {
        $options = Options::fromGetoptArray(['pr' => '1', 'owner' => 'wdes']);

        $this->assertSame('wdes', $options->owner);
    }

    public function testDownbaseAndDryRun(): void
    {
        $options = Options::fromGetoptArray(['pr' => '667', 'downbase' => '5.11.x', 'dry-run' => false]);

        $this->assertSame('5.11.x', $options->downBase);
        $this->assertTrue($options->dryRun);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidOptions(): array
    {
        return [
            'no pr' => [[]],
            'empty pr' => [['pr' => '']],
            'zero pr' => [['pr' => '0']],
            'non numeric pr' => [['pr' => 'abc']],
            'negative pr' => [['pr' => '-3']],
            'dry run alone' => [['pr' => '1', 'dry-run' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $raw): void
    {
        $this->expectException(ContribMergeUsageException::class);

        Options::fromGetoptArray($raw);
    }
}
