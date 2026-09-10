<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nightly database backup — §12.
 *
 * Three things this does that a bare `mysqldump > file.sql` in cron does not:
 *
 *  1. **Fails loudly.** A cron job that silently stops working is the classic
 *     way to discover, during an incident, that there are no backups. Every
 *     failure here is a non-zero exit and a logged error, so the scheduler's
 *     own failure handling can see it.
 *
 *  2. **Copies off the server.** A dump sitting on the disk it is backing up
 *     survives a bad migration and nothing else. `--verify` aside, this is the
 *     single most important line in the file.
 *
 *  3. **Can prove the dump restores.** `--verify` loads it into a scratch
 *     database and compares table counts against the live schema. An untested
 *     backup is not a backup; this is what makes the claim checkable rather
 *     than aspirational.
 *
 * Credentials are passed through the environment, never on the command line —
 * argv is world-readable in `ps` on a shared host.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run
        {--verify : Restore the dump into a scratch database and check it}
        {--keep-local : Skip pruning older dumps}';

    protected $description = 'Dump the database, copy it off-server, and optionally prove it restores';

    public function handle(): int
    {
        $started = microtime(true);

        try {
            $file = $this->dump();
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $size = $this->humanSize(filesize($file));
        $this->info("Dumped {$this->databaseName()} to {$file} ({$size}).");

        if ($this->option('verify')) {
            try {
                $tables = $this->verify($file);
                $this->info("Verified: restored cleanly with {$tables} tables.");
            } catch (Throwable $e) {
                // A dump that does not restore is worse than no dump, because
                // it is believed. This must be a failure, not a warning.
                $this->error('Verification FAILED: '.$e->getMessage());
                report($e);

                return self::FAILURE;
            }
        }

        if (config('backup.disk')) {
            try {
                $this->copyOffServer($file);
                $this->info('Copied to the '.config('backup.disk').' disk.');
            } catch (Throwable $e) {
                $this->error('Off-server copy failed: '.$e->getMessage());
                report($e);

                return self::FAILURE;
            }
        } else {
            $this->warn(
                'BACKUP_DISK is not set, so this dump exists only on this server. '
                .'A backup stored beside the database it backs up will not survive losing the machine.'
            );
        }

        if (! $this->option('keep-local')) {
            $pruned = $this->prune();
            if ($pruned) {
                $this->line("Pruned {$pruned} dump(s) older than ".config('backup.retention_days').' days.');
            }
        }

        $this->line(sprintf('Done in %.1fs.', microtime(true) - $started));

        return self::SUCCESS;
    }

    /** @return string absolute path to the gzipped dump */
    protected function dump(): string
    {
        $dir = config('backup.path');

        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create backup directory {$dir}.");
        }

        $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR
            .sprintf('%s-%s.sql.gz', $this->databaseName(), now()->format('Y-m-d-His'));

        $config = config('database.connections.'.config('database.default'));

        $arguments = [
            config('backup.mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            // Consistent snapshot without locking the storefront out mid-dump.
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
        ];

        /*
         * Restoring must not depend on the dump's own GTID state — but the flag
         * is MySQL-only, and MariaDB's mysqldump aborts on an unknown variable
         * rather than ignoring it. Development here runs MariaDB and production
         * runs MySQL 8, so this is decided from the live server rather than
         * assumed either way.
         */
        if (! $this->serverIsMariaDb()) {
            $arguments[] = '--set-gtid-purged=OFF';
        }

        $arguments[] = $this->databaseName();

        $process = new Process($arguments, timeout: 1800, env: [
            // Never as --password= on argv: that is visible in `ps`.
            'MYSQL_PWD' => (string) ($config['password'] ?? ''),
        ]);

        $handle = gzopen($file, 'wb9');

        if ($handle === false) {
            throw new RuntimeException("Cannot write to {$file}.");
        }

        try {
            // Streamed rather than buffered — a large catalogue must not have
            // to fit in PHP's memory limit on the way past.
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    gzwrite($handle, $buffer);
                }
            });
        } finally {
            gzclose($handle);
        }

        if (! $process->isSuccessful()) {
            @unlink($file);

            // A missing binary is by far the most common cause, and the raw
            // message for it is unhelpful.
            if (str_contains($process->getErrorOutput(), 'not recognized')
                || str_contains($process->getErrorOutput(), 'not found')) {
                throw new RuntimeException(
                    'mysqldump was not found. Set BACKUP_MYSQLDUMP to its full path.'
                );
            }

            throw new ProcessFailedException($process);
        }

        if (filesize($file) < 1024) {
            @unlink($file);
            throw new RuntimeException('The dump came out suspiciously small; treating it as a failure.');
        }

        return $file;
    }

    /**
     * Restore into a scratch database and count what came back.
     *
     * The scratch database is dropped first and last, so a previous failed run
     * cannot make this one pass on stale data.
     *
     * @return int tables restored
     */
    protected function verify(string $file): int
    {
        $scratch = config('backup.verify_database');

        if (! $scratch) {
            throw new RuntimeException('BACKUP_VERIFY_DATABASE is not set.');
        }

        if ($scratch === $this->databaseName()) {
            throw new RuntimeException('The verification database must not be the live database.');
        }

        $expected = count(DB::select('SHOW TABLES'));

        try {
            $this->mysql("DROP DATABASE IF EXISTS `{$scratch}`");
            $this->mysql("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $sql = gzdecode(file_get_contents($file));

            if ($sql === false) {
                throw new RuntimeException('The dump could not be decompressed.');
            }

            $this->mysql($sql, $scratch);

            $restored = count(DB::connection()->getPdo()
                ->query("SHOW TABLES FROM `{$scratch}`")
                ->fetchAll());

            if ($restored < $expected) {
                throw new RuntimeException(
                    "Restored {$restored} tables but the live database has {$expected}."
                );
            }

            return $restored;
        } finally {
            // Never leave a copy of production data lying around under a name
            // nobody is watching.
            $this->mysql("DROP DATABASE IF EXISTS `{$scratch}`");
        }
    }

    protected function copyOffServer(string $file): void
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Cannot read {$file} for upload.");
        }

        try {
            $ok = Storage::disk(config('backup.disk'))->writeStream(
                rtrim(config('backup.disk_path'), '/').'/'.basename($file),
                $stream,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $ok) {
            throw new RuntimeException('The storage disk rejected the upload.');
        }
    }

    /** @return int dumps deleted */
    protected function prune(): int
    {
        $cutoff = now()->subDays((int) config('backup.retention_days'))->getTimestamp();
        $deleted = 0;

        foreach (glob(rtrim(config('backup.path'), '/\\').DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $path) {
            if (filemtime($path) < $cutoff && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Run SQL through the mysql client, with credentials kept off argv. */
    protected function mysql(string $sql, ?string $database = null): void
    {
        $config = config('database.connections.'.config('database.default'));

        $command = [
            config('backup.mysql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            '--default-character-set=utf8mb4',
        ];

        if ($database) {
            $command[] = $database;
        }

        $process = new Process($command, timeout: 1800, env: [
            'MYSQL_PWD' => (string) ($config['password'] ?? ''),
        ]);

        $process->setInput($sql);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /** MariaDB and MySQL differ in which mysqldump flags they accept. */
    protected function serverIsMariaDb(): bool
    {
        try {
            return str_contains(
                strtolower((string) DB::selectOne('select version() as v')?->v),
                'mariadb',
            );
        } catch (Throwable) {
            // Unreachable database is the dump's problem to report, not this
            // helper's. Assume the stricter client and omit the flag.
            return true;
        }
    }

    protected function databaseName(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    protected function humanSize(int|false $bytes): string
    {
        if ($bytes === false) {
            return 'unknown size';
        }

        return $bytes > 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
