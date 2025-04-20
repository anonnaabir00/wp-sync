<?php

namespace WPSync\Command;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../../config.json'), true);
        $dsn = 'mysql:host=' . $config['local_db_host'] . ';dbname=' . $config['local_db_name'];
        $tablePrefix = $config['table_prefix'] ?? 'wp_';
        $url = $config['local_site_url'] ?? 'http://localhost';

        try {
            $pdo = new PDO($dsn, $config['local_db_user'], $config['local_db_pass']);
            $stmt = $pdo->prepare("UPDATE {$tablePrefix}options SET option_value = :url WHERE option_name IN ('siteurl', 'home')");
            $stmt->execute(['url' => $url]);
            $output->writeln("✅ Updated siteurl/home to $url");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>❌ {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
