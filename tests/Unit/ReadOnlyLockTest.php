<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\ReadOnlyLock;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ReadOnlyLockTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/driftguard_rolock_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // pode ter ficado travado se um teste falhar no meio — destrava tudo antes de limpar
        @chmod("{$this->tmpDir}", 0755);
        $this->limparRecursivo($this->tmpDir);
        parent::tearDown();
    }

    private function limparRecursivo(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            if (is_dir($path)) {
                @chmod($path, 0755);
                $this->limparRecursivo($path);
                @rmdir($path);
            } else {
                @chmod($path, 0644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_travar_blocks_writes_and_destravar_restores_them(): void
    {
        file_put_contents("{$this->tmpDir}/arquivo.txt", 'original');

        $lock  = new ReadOnlyLock(isWindows: false);
        $modos = $lock->travar($this->tmpDir);

        $this->assertFalse(@file_put_contents("{$this->tmpDir}/arquivo.txt", 'modificado'), 'escrita deveria estar bloqueada enquanto travado');
        $this->assertFalse(@file_put_contents("{$this->tmpDir}/novo.txt", 'x'), 'criar arquivo novo deveria estar bloqueado enquanto travado');

        $lock->destravar($modos);

        $this->assertNotFalse(file_put_contents("{$this->tmpDir}/arquivo.txt", 'modificado'), 'escrita deveria funcionar depois de destravar');
    }

    public function test_destravar_restores_exact_original_mode_per_file(): void
    {
        file_put_contents("{$this->tmpDir}/a.txt", 'a');
        file_put_contents("{$this->tmpDir}/b.txt", 'b');
        chmod("{$this->tmpDir}/a.txt", 0644);
        chmod("{$this->tmpDir}/b.txt", 0600);

        $lock  = new ReadOnlyLock(isWindows: false);
        $modos = $lock->travar($this->tmpDir);
        $lock->destravar($modos);

        $this->assertSame('644', substr(sprintf('%o', fileperms("{$this->tmpDir}/a.txt")), -3));
        $this->assertSame('600', substr(sprintf('%o', fileperms("{$this->tmpDir}/b.txt")), -3));
    }

    public function test_read_and_traversal_still_work_while_locked(): void
    {
        mkdir("{$this->tmpDir}/sub");
        file_put_contents("{$this->tmpDir}/sub/arquivo.txt", 'conteudo');

        $lock  = new ReadOnlyLock(isWindows: false);
        $modos = $lock->travar($this->tmpDir);

        $this->assertSame('conteudo', file_get_contents("{$this->tmpDir}/sub/arquivo.txt"));
        $this->assertIsArray(scandir("{$this->tmpDir}/sub"));

        $lock->destravar($modos);
    }

    public function test_excluded_directories_are_never_locked(): void
    {
        mkdir("{$this->tmpDir}/storage");
        file_put_contents("{$this->tmpDir}/storage/log.txt", 'log');
        mkdir("{$this->tmpDir}/vendor/pkg", 0755, true);
        file_put_contents("{$this->tmpDir}/vendor/pkg/file.php", 'dep');

        $lock  = new ReadOnlyLock(isWindows: false);
        $modos = $lock->travar($this->tmpDir);

        $this->assertNotFalse(@file_put_contents("{$this->tmpDir}/storage/log.txt", 'novo log'), 'storage/ nunca deveria ser travado');
        $this->assertNotFalse(@file_put_contents("{$this->tmpDir}/vendor/pkg/file.php", 'novo'), 'vendor/ nunca deveria ser travado');

        $lock->destravar($modos);
    }

    public function test_windows_is_a_no_op(): void
    {
        file_put_contents("{$this->tmpDir}/arquivo.txt", 'original');

        $lock  = new ReadOnlyLock(isWindows: true);
        $modos = $lock->travar($this->tmpDir);

        $this->assertSame([], $modos);
        $this->assertNotFalse(@file_put_contents("{$this->tmpDir}/arquivo.txt", 'ainda funciona'), 'nao deveria travar nada no Windows');
    }

    public function test_destravar_with_empty_array_is_a_safe_noop(): void
    {
        $lock = new ReadOnlyLock(isWindows: false);
        $lock->destravar([]);
        $this->assertTrue(true); // não lançou exceção
    }
}
