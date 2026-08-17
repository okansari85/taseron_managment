<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

class DbRestore extends Command
{
    protected $signature = 'db:restore
                            {--force : Restore without confirmation}';

    protected $description = 'Restore the development database from the latest snapshot';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (!in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Only MySQL/MariaDB databases are supported.');

            return self::FAILURE;
        }

        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;

        if (!$database || !$username) {
            $this->error('Database configuration is incomplete.');

            return self::FAILURE;
        }

        $snapshotPath = database_path('snapshots/development.sql');

        if (!file_exists($snapshotPath)) {
            $this->error("Snapshot not found: {$snapshotPath}");

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $this->warn("WARNING: Database '{$database}' will be overwritten.");
            $this->warn('All existing development data in this database may be replaced.');

            if (!$this->confirm('Continue?')) {
                $this->info('Restore cancelled.');

                return self::SUCCESS;
            }
        }

        $mysqlBinary = $this->findBinary([
            'mariadb',
            'mysql',
        ]);

        if (!$mysqlBinary) {
            $this->error('Neither mariadb nor mysql client was found.');

            return self::FAILURE;
        }

        $this->info('Restoring database snapshot...');

        $arguments = [
            $mysqlBinary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            $database,
        ];

        $process = new Process($arguments);
        $process->setTimeout(300);

        if ($password !== null && $password !== '') {
            $process->setEnv([
                'MYSQL_PWD' => $password,
            ]);
        }

        $input = fopen($snapshotPath, 'rb');

        if ($input === false) {
            $this->error('Could not open snapshot file.');

            return self::FAILURE;
        }

        $process->setInput($input);
        $process->run();

        fclose($input);

        if (!$process->isSuccessful()) {
            $this->error('Database restore failed.');
            $this->line($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Database restored successfully.');
        $this->info("Snapshot: {$snapshotPath}");
        $this->info("Database: {$database}");

        return self::SUCCESS;
    }

    private function findBinary(array $binaries): ?string
{
    $finder = new ExecutableFinder();

    foreach ($binaries as $binary) {
        $path = $finder->find($binary);

        if ($path !== null) {
            return $path;
        }
    }

    return null;
    }
}
