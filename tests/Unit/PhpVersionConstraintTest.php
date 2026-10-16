<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpVersionConstraint;

final class PhpVersionConstraintTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function constraints(): array
    {
        return [
            // phpmyadmin/sql-parser 5.11.x composer.json: "php": "^7.2 || ^8.0".
            'sql-parser 5.11.x' => ['^7.2 || ^8.0', '7.2'],
            'sql-parser master' => ['^8.2', '8.2'],
            'caret three parts' => ['^7.2.5', '7.2'],
            'tilde' => ['~7.2.0', '7.2'],
            'greater or equal' => ['>=7.4', '7.4'],
            'no operator' => ['7.2', '7.2'],
            'wildcard minor' => ['7.2.*', '7.2'],
            'major only' => ['^8', '8.0'],
            'spread across two lines' => ["^7.2\n|| ^8.0", '7.2'],
            'lowest wins' => ['^8.0 || ^7.4', '7.4'],
            'exact lower bound' => ['=7.2.0', '7.2'],
            'no lower bound' => ['*', null],
            'garbage' => ['dev-main', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('constraints')]
    public function testMinimum(string $constraint, ?string $expected): void
    {
        $this->assertSame($expected, PhpVersionConstraint::minimum($constraint));
    }
}
