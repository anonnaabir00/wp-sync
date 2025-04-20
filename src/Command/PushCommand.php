<?php

namespace WPSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PushCommand extends Command
{
    protected static $defaultName = 'push';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configPath = __DIR__ . '/../../config.json';
        if (!file_exists($configPath)) {
            $output->writeln("<error>❌ config.json not found.</error>");
            return Command::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);
        $localPath = rtrim($config['local_wp_path'], '/');
        $pushDirs = $config['push_dirs'] ?? [];

        if (empty($pushDirs)) {
            $output->writeln("<comment>⚠️ No folders specified in 'push_dirs'. Nothing to push.</comment>");
            return Command::SUCCESS;
        }

        $user = escapeshellarg($config['remote_username']);
        $host = escapeshellarg($config['remote_host']);
        $remoteBase = rtrim($config['remote_path'], '/') . '/wp-content';

        if ($config['auth_type'] === 'password') {
            $rsyncPrefix = "sshpass -p " . escapeshellarg($config['ssh_password']) . " rsync -avz -e ssh";
        } else {
            $key = escapeshellarg($config['ssh_key_path']);
            $rsyncPrefix = "rsync -avz -e 'ssh -i $key'";
        }

        $output->writeln("<info>🚀 Pushing allowed folders to remote server...</info>");

        foreach ($pushDirs as $dir) {
            $localDir = $localPath . '/wp-content/' . $dir;
            if (!is_dir($localDir)) {
                $output->writeln("<error>❌ Skipping: '$dir' does not exist locally.</error>");
                continue;
            }

            $remoteDir = "$user@$host:$remoteBase/" . dirname($dir) . '/';
            $rsyncCmd = "$rsyncPrefix " . escapeshellarg($localDir) . " $remoteDir";

            $output->writeln("📂 Pushing: <comment>$dir</comment>");
            passthru($rsyncCmd, $resultCode);

            if ($resultCode !== 0) {
                $output->writeln("<error>❌ Failed to push: $dir</error>");
                return Command::FAILURE;
            } else {
                $output->writeln("<info>✅ Successfully pushed: $dir</info>");
            }
        }

        $output->writeln("<info>🎉 Push complete!</info>");
        return Command::SUCCESS;
    }
}
