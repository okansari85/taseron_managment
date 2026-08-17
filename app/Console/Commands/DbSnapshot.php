<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DbSnapshot extends Command
{
    protected $signature = 'db:snapshot';

    protected $description = 'Create a development database snapshot';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (!in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error('Only MySQL/MariaDB databases are supported.');
            return self::FAILURE;
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        if (!$database || !$username) {
            $this->error('Database configuration is incomplete.');
            return self::FAILURE;
        }

        $snapshotDirectory = database_path('snapshots');
        $snapshotPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'development.sql';

        if (!is_dir($snapshotDirectory)) {
            mkdir($snapshotDirectory, 0755, true);
        }

        $dumpBinary = $this->findBinary([
            'mariadb-dump',
            'mysqldump',
        ]);

        if (!$dumpBinary) {
            $this->error('Neither mariadb-dump nor mysqldump was found.');
            return self::FAILURE;
        }

        $arguments = [
        $dumpBinary,
        '--host=' . $host,
        '--port=' . $port,
        '--user=' . $username,
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--skip-lock-tables',
        $database,
    ];

        $this->info('Creating database snapshot...');

        $process = new Process($arguments);
        $process->setTimeout(300);

        if ($password !== null && $password !== '') {
            $process->setEnv([
                'MYSQL_PWD' => $password,
            ]);
        }

        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->error('Database snapshot failed.');
            $this->line($process->getErrorOutput());

            return self::FAILURE;
        }

        file_put_contents($snapshotPath, $process->getOutput());

        $size = number_format(filesize($snapshotPath) / 1024, 2);

        $this->newLine();
        $this->info("Snapshot created successfully: {$snapshotPath}");
        $this->info("Size: {$size} KB");

        return self::SUCCESS;
    }

    private function findBinary(array $binaries): ?string
    {
        foreach ($binaries as $binary) {
            $process = new Process(['bash', '-lc', "command -v {$binary}"]);
            $process->run();

            if ($process->isSuccessful()) {
                $path = trim($process->getOutput());

                if ($path !== '') {
                    return $path;
                }
            }
        }

        return null;
    }
}