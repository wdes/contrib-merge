<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ExecutableFinder;

final class ExecutableFinderTest extends TestCase
{
    private string $directory = '';

    private string $originalPath = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/contrib-merge-bin-' . bin2hex(random_bytes(4));
        if (! mkdir($directory, 0o700, true)) {
            $this->fail('Unable to create ' . $directory);
        }
        $this->directory = $directory;
        $this->originalPath = (string) getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testFindsAnExecutableOnPath(): void
    {
        $binary = $this->directory . '/composer-php7.2';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0o755);
        putenv('PATH=' . $this->directory);

        $this->assertSame($binary, ExecutableFinder::find('composer-php7.2'));
    }

    public function testIgnoresANonExecutableFile(): void
    {
        $binary = $this->directory . '/composer-php7.2';
        file_put_contents($binary, 'not executable');
        chmod($binary, 0o644);
        putenv('PATH=' . $this->directory);

        $this->assertNull(ExecutableFinder::find('composer-php7.2'));
    }

    public function testReturnsNullWhenMissing(): void
    {
        putenv('PATH=' . $this->directory);

        $this->assertNull(ExecutableFinder::find('composer-php9.9'));
    }
}
