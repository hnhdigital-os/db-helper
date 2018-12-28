<?php

namespace App\Commands;

use App\Traits\SharedTrait;
use DB;
use HnhDigital\CliHelper\CommandInternalsTrait;
use HnhDigital\CliHelper\FileSystemTrait;
use LaravelZero\Framework\Commands\Command;
use ZipArchive;

class BackupCommand extends Command
{
    use CommandInternalsTrait, FileSystemTrait, SharedTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'backup
                            {--profile=0 : Profile}
                            {--connection=0 : Local connection}
                            {--database=0 : Database}
                            {--no-progress=0 : Do not display progress}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Backup a specific local database';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->loadExistingProfiles();

        // Select profile.
        if (empty($profile = $this->option('profile'))) {
            $profile = $this->selectProfile();
        } elseif (!array_has($this->profiles, $profile)) {
            $this->error('Invalid profile supplied.');

            return 1;
        }

        // Select connection profile.
        if (empty($connection = $this->option('connection'))) {
            $connection = $this->selectLocalConnection($profile);
        } elseif (!array_has($this->profiles, $profile.'.'.$connection)) {
            $this->error('Invalid connection profile supplied.');

            return 1;
        } else {
            $this->loadDatabaseConnections($profile);
        }

        // Select database.
        if (empty($database = $this->option('database'))) {
            $database = $this->selectDatabase($profile, $connection);
        } else {
            config(['database.connections.'.$connection.'.database' => $database]);

            try {
                DB::connection($connection)->select('show tables');
            } catch (\Exception $e) {
                $this->error($e->getMessage());

                return 1;
            }
        }

        return $this->runBackup($profile, $connection, $database);
    }

    /**
     * Select profile.
     *
     * @return string
     */
    private function selectProfile()
    {
        $menu_options = [];

        foreach ($this->profiles as $name => $profile_data) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu('Select profile', $menu_options)->open();

        return $option;
    }

    /**
     * Select local connection.
     *
     * @return string
     */
    private function selectLocalConnection($profile)
    {
        $menu_options = [];

        foreach (array_get($this->profiles, $profile.'.local', []) as $name => $connection_detail) {
            $menu_options[$name] = strtoupper($name);
        }

        $option = $this->menu('Select local connection', $menu_options)->open();

        $this->loadDatabaseConnections($profile, $option);

        return $option;
    }

    /**
     * Select database.
     *
     * @return string
     */
    private function selectDatabase($profile, $connection)
    {
        $databases = [];

        $available_databases = DB::connection($connection)
            ->select('show databases');

        foreach ($available_databases as $database) {
            $databases[$database->Database] = $database->Database;
        }

        $database = $this->menu('Select database', $databases)->open();

        config(['database.connections.'.$connection.'.database' => $database]);

        return $database;
    }

    /**
     * Run backup.
     *
     * @return void
     */
    private function runBackup($profile, $connection, $database)
    {
        $backup_file_path = $this->getConfigPath(sprintf('backups/%s/%s', $profile, $database)).'/'.date('Y-m-d-H-i-s');

        $this->line('');

        // Display status of the backup progress.
        $progress_cmd = '';
        if (!$this->option('no-progress')) {
            // Get the data and index length of tables in this database.
            $size_result = DB::connection($connection)->select(sprintf(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 0) AS "size"
                FROM information_schema.TABLES
                WHERE table_schema="%s"',
                $database
            ));

            // Get value and set to 65% compression.
            $size = round(object_get(array_get($size_result, '0'), 'size') * 0.65, 0);
            $progress_cmd = sprintf(' | pv --progress --size "%sm"', $size);
        }

        // Run the mysql dump.
        exec(sprintf(
            'export MYSQL_PWD=%s; mysqldump --user=%s --compress --complete-insert --disable-keys --quick --single-transaction --add-drop-table --add-drop-table %s %s > "%s"',
            array_get($this->profiles, $profile.'.local.'.$connection.'.password'),
            array_get($this->profiles, $profile.'.local.'.$connection.'.username'),
            $database,
            $progress_cmd,
            $backup_file_path.'.sql'
        ));

        if (!file_exists($backup_file_path.'.sql') || filesize($backup_file_path.'.sql') == 0) {
            return 1;
        }

        $this->line('');

        // Remove definer sections as it breaks restores.
        exec(sprintf(
            "perl -pe 's/\sDEFINER=`[^`]+`@`[^`]+`//' < \"%s\" > \"%s\"",
            $backup_file_path.'.sql',
            $backup_file_path.'.sql'
        ));

        // Create ZIP of given sql file.
        $zip = new ZipArchive();
        $zip->open($backup_file_path.'.zip', ZipArchive::CREATE);
        $zip->addFile($backup_file_path.'.sql', 'backup.sql');
        $zip->close();

        // Remove sql file.
        unlink($backup_file_path.'.sql');

        $this->line($backup_file_path.'.zip');

        return 0;
    }
}