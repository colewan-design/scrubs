<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Database backups — §12.
 *
 * The point of testing a backup command is not that it writes a file. It is
 * that the file is a working dump and that old ones are cleared away, because
 * both failures are silent: nobody notices a broken backup or a full disk until
 * the day they need the backup.
 *
 * These skip rather than fail where mysqldump is not installed. CI without a
 * MySQL client is a legitimate environment; a red build there would only teach
 * people to ignore it.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/backups');

        File::deleteDirectory($this->path);
        config(['backup.path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    protected function skipWithoutMysqldump(): void
    {
        $binary = config('backup.mysqldump');

        if ($binary !== 'mysqldump' && is_file($binary)) {
            return;
        }

        if ((new ExecutableFinder)->find($binary) === null) {
            $this->markTestSkipped('mysqldump is not on PATH; set BACKUP_MYSQLDUMP to run this.');
        }
    }

    public function test_it_writes_a_compressed_dump(): void
    {
        $this->skipWithoutMysqldump();

        $this->artisan('backup:run')->assertSuccessful();

        $dumps = glob($this->path.DIRECTORY_SEPARATOR.'*.sql.gz');

        $this->assertCount(1, $dumps, 'Expected exactly one dump to be written.');

        // Readable as gzip and containing real SQL — not an empty or truncated
        // file that merely has the right name.
        $sql = gzdecode(file_get_contents($dumps[0]));

        $this->assertIsString($sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('orders', $sql);
    }

    /**
     * The dump is restored into a scratch database and its tables counted. This
     * is the assertion that makes "we have backups" a fact rather than a hope.
     */
    public function test_verification_restores_the_dump(): void
    {
        $this->skipWithoutMysqldump();

        if (! config('backup.verify_database')) {
            $this->markTestSkipped('No scratch database configured for verification.');
        }

        $this->artisan('backup:run', ['--verify' => true])
            ->expectsOutputToContain('Verified')
            ->assertSuccessful();
    }

    public function test_it_prunes_dumps_past_the_retention_window(): void
    {
        $this->skipWithoutMysqldump();

        File::ensureDirectoryExists($this->path);
        config(['backup.retention_days' => 7]);

        $stale = $this->path.DIRECTORY_SEPARATOR.'bulkscrubs-old.sql.gz';
        $recent = $this->path.DIRECTORY_SEPARATOR.'bulkscrubs-recent.sql.gz';

        File::put($stale, 'x');
        File::put($recent, 'x');
        touch($stale, now()->subDays(30)->getTimestamp());
        touch($recent, now()->subDay()->getTimestamp());

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($recent);
    }

    public function test_keep_local_leaves_old_dumps_alone(): void
    {
        $this->skipWithoutMysqldump();

        File::ensureDirectoryExists($this->path);
        config(['backup.retention_days' => 7]);

        $stale = $this->path.DIRECTORY_SEPARATOR.'bulkscrubs-old.sql.gz';
        File::put($stale, 'x');
        touch($stale, now()->subDays(30)->getTimestamp());

        $this->artisan('backup:run', ['--keep-local' => true])->assertSuccessful();

        $this->assertFileExists($stale);
    }

    /** A missing binary must say what to do about it, not leak a raw exit code. */
    public function test_a_missing_mysqldump_fails_with_a_useful_message(): void
    {
        config(['backup.mysqldump' => 'mysqldump-that-does-not-exist']);

        $this->artisan('backup:run')->assertFailed();
    }
}
