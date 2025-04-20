<?php

namespace WPSync\Command;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    protected static $defaultName = 'sync';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the sync without executing any commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');

        $configPath = __DIR__ . '/../../config.json';
        if (!file_exists($configPath)) {
            $output->writeln("<error>❌ config.json not found.</error>");
            return Command::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);
        $required_keys = [
            'local_wp_path', 'remote_host', 'remote_username', 'remote_path', 'auth_type',
            'remote_db_name', 'remote_db_user', 'remote_db_pass',
            'local_db_name', 'local_db_user', 'local_db_pass'
        ];

        foreach ($required_keys as $key) {
            if (empty($config[$key])) {
                $output->writeln("<error>❌ Missing config: $key</error>");
                return Command::FAILURE;
            }
        }

        $wpPath = rtrim($config['local_wp_path'], '/');
        $wpContentPath = escapeshellarg($wpPath . '/wp-content');
        $user = escapeshellarg($config['remote_username']);
        $host = escapeshellarg($config['remote_host']);
        $remotePath = rtrim($config['remote_path'], '/') . '/wp-content/';
        $remoteDbName = escapeshellarg($config['remote_db_name']);
        $remoteDbUser = escapeshellarg($config['remote_db_user']);
        $remoteDbPass = escapeshellarg($config['remote_db_pass']);
        $remoteDbHost = escapeshellarg($config['remote_db_host'] ?? '127.0.0.1');
        $localDbName = escapeshellarg($config['local_db_name']);
        $localDbUser = escapeshellarg($config['local_db_user']);
        $localDbPass = escapeshellarg($config['local_db_pass']);
        $localDbHost = escapeshellarg($config['local_db_host'] ?? 'localhost');
        $localSiteUrl = $config['local_site_url'] ?? 'http://localhost';
        $tablePrefix = $config['table_prefix'] ?? 'wp_';

        // Exclude dirs
        $excludeOptions = '';
        foreach ($config['exclude_dirs'] ?? [] as $dir) {
            $excludeOptions .= " --exclude=" . escapeshellarg($dir);
        }

        // SSH setup
        if ($config['auth_type'] === 'password') {
            $sshPrefix = "sshpass -p " . escapeshellarg($config['ssh_password']) . " ssh $user@$host";
            $rsyncPrefix = "sshpass -p " . escapeshellarg($config['ssh_password']) . " rsync -avz -e ssh";
        } else {
            $key = escapeshellarg($config['ssh_key_path']);
            $sshPrefix = "ssh -i $key $user@$host";
            $rsyncPrefix = "rsync -avz -e 'ssh -i $key'";
        }

        $output->writeln("<info>📁 Syncing wp-content (excluding specified dirs)...</info>");
        $progress = new ProgressBar($output, 3);
        $progress->start();

        // RSYNC
        $rsyncCmd = "$rsyncPrefix $excludeOptions $user@$host:$remotePath $wpContentPath";
        $progress->advance();
        if ($dryRun) {
            $output->writeln("\n<comment>[dry-run] $rsyncCmd</comment>");
        } else {
            passthru($rsyncCmd, $rsyncResult);
            if ($rsyncResult !== 0) {
                $output->writeln("\n<error>❌ File sync failed.</error>");
                return Command::FAILURE;
            }
        }

        // Export DB
        $progress->advance();
        $tmpfile = tempnam(sys_get_temp_dir(), 'pressable_db_') . ".sql";
        $remoteDumpCmd = "mysqldump -h $remoteDbHost -u $remoteDbUser -p$remoteDbPass $remoteDbName";
        $fullDumpCmd = $sshPrefix . ' "' . $remoteDumpCmd . '" > "' . $tmpfile . '"';

        if ($dryRun) {
            $output->writeln("<comment>[dry-run] $fullDumpCmd</comment>");
        } else {
            passthru($fullDumpCmd, $dumpResult);
            if (!file_exists($tmpfile) || filesize($tmpfile) < 100) {
                $output->writeln("\n<error>❌ Remote DB export failed or empty.</error>");
                unlink($tmpfile);
                return Command::FAILURE;
            }
        }

        // Import DB
        $progress->advance();
        $progress->finish();
        $output->writeln("\n<info>📥 Importing DB into local...</info>");
        $tcpCmd = "mysql -h {$localDbHost} -u {$localDbUser} -p{$localDbPass} {$localDbName} < \"{$tmpfile}\"";
        $socketCmd = "mysql --protocol=socket -u {$localDbUser} -p{$localDbPass} {$localDbName} < \"{$tmpfile}\"";

        if ($dryRun) {
            $output->writeln("<comment>[dry-run] $tcpCmd</comment>");
        } else {
            exec($tcpCmd . " 2>&1", $tcpOutput, $tcpResult);
            if ($tcpResult !== 0) {
                $output->writeln("<comment>⚠️ TCP failed. Trying socket...</comment>");
                exec($socketCmd . " 2>&1", $socketOutput, $socketResult);
                if ($socketResult !== 0) {
                    $output->writeln("<error>❌ Socket import failed:</error>");
                    $output->writeln(implode("\n", $socketOutput));
                    unlink($tmpfile);
                    return Command::FAILURE;
                } else {
                    $output->writeln("✅ Imported via socket.");
                }
            } else {
                $output->writeln("✅ Imported via TCP.");
            }
        }

        // Update siteurl + home
        $output->writeln("<info>🔁 Updating siteurl/home to {$localSiteUrl}...</info>");
        if ($dryRun) {
            $output->writeln("<comment>[dry-run] UPDATE {$tablePrefix}options SET option_value = '{$localSiteUrl}' WHERE option_name IN ('siteurl','home')</comment>");
        } else {
            try {
                $dsn = "mysql:host={$config['local_db_host']};dbname={$config['local_db_name']}";
                $pdo = new PDO($dsn, $config['local_db_user'], $config['local_db_pass']);
                $stmt = $pdo->prepare("UPDATE {$tablePrefix}options SET option_value = :url WHERE option_name IN ('siteurl', 'home')");
                $stmt->execute(['url' => $localSiteUrl]);
                $output->writeln("<info>✅ siteurl/home updated.</info>");
            } catch (\Exception $e) {
                $output->writeln("<error>❌ DB Error: {$e->getMessage()}</error>");
            }
        }

        if (!$dryRun) unlink($tmpfile);

        $output->writeln("<info>🎉 Sync complete!</info>");
        return Command::SUCCESS;
    }
}
