#!/usr/bin/env php
<?php
/**
 * CLI Sync Tool (Final Version with TCP + Socket fallback)
 * - Syncs wp-content (excluding uploads)
 * - Dumps remote DB using mysqldump via SSH
 * - Imports DB locally using mysql CLI with TCP, then fallback to socket
 */

$config_path = __DIR__ . '/config.json';

if (!file_exists($config_path)) {
    echo "❌ config.json not found.\n";
    exit(1);
}

$config = json_decode(file_get_contents($config_path), true);

function validate($key) {
    global $config;
    if (empty($config[$key])) {
        echo "❌ Missing config: $key\n";
        exit(1);
    }
}

// Validate required keys
$required_keys = [
    'local_wp_path', 'remote_host', 'remote_username', 'remote_path', 'auth_type',
    'remote_db_name', 'remote_db_user', 'remote_db_pass',
    'local_db_name', 'local_db_user', 'local_db_pass'
];
array_map('validate', $required_keys);

// Paths
$wp_path = rtrim($config['local_wp_path'], '/');
$wp_content_path = escapeshellarg($wp_path . '/wp-content');

// Remote SSH & DB info
$user = escapeshellarg($config['remote_username']);
$host = escapeshellarg($config['remote_host']);
$remote_path = rtrim($config['remote_path'], '/') . '/wp-content/';
$remote_db_name = escapeshellarg($config['remote_db_name']);
$remote_db_user = escapeshellarg($config['remote_db_user']);
$remote_db_pass = escapeshellarg($config['remote_db_pass']);
$remote_db_host = escapeshellarg($config['remote_db_host'] ?? '127.0.0.1');

// Local DB info
$local_db_name = escapeshellarg($config['local_db_name']);
$local_db_user = escapeshellarg($config['local_db_user']);
$local_db_pass = escapeshellarg($config['local_db_pass']);
$local_db_host = escapeshellarg($config['local_db_host'] ?? 'localhost');

// Auth method (key or password)
$authType = $config['auth_type'];
if ($authType === 'password') {
    $ssh_prefix = "sshpass -p " . escapeshellarg($config['ssh_password']) . " ssh $user@$host";
    $rsync_prefix = "sshpass -p " . escapeshellarg($config['ssh_password']) . " rsync -avz -e ssh";
} else {
    $key = escapeshellarg($config['ssh_key_path']);
    $ssh_prefix = "ssh -i $key $user@$host";
    $rsync_prefix = "rsync -avz -e 'ssh -i $key'";
}

echo "📁 Syncing wp-content (excluding uploads)...\n";
$rsync_cmd = "$rsync_prefix --exclude='uploads' $user@$host:$remote_path $wp_content_path";
passthru($rsync_cmd, $rsync_result);
if ($rsync_result !== 0) {
    echo "❌ File sync failed.\n";
    exit(1);
}

echo "🧠 Exporting remote DB using mysqldump...\n";
$tmpfile = tempnam(sys_get_temp_dir(), 'pressable_db_') . ".sql";
$remote_dump_cmd = "mysqldump -h $remote_db_host -u $remote_db_user -p$remote_db_pass $remote_db_name";
$full_dump_cmd = "$ssh_prefix \"$remote_dump_cmd\" > \"$tmpfile\"";
passthru($full_dump_cmd, $dump_result);

if (!file_exists($tmpfile) || filesize($tmpfile) < 100) {
    echo "❌ Remote DB export failed or returned empty.\n";
    unlink($tmpfile);
    exit(1);
}

echo "📥 Importing into local DB using mysql CLI...\n";

// First try TCP
$tcp_cmd = "mysql -h $local_db_host -u $local_db_user -p$local_db_pass $local_db_name < \"$tmpfile\"";
exec($tcp_cmd . " 2>&1", $tcp_output, $tcp_result);

// Fallback to socket if TCP fails
if ($tcp_result !== 0) {
    echo "⚠️ TCP connection failed. Trying socket method...\n";
    $socket_cmd = "mysql --protocol=socket -u $local_db_user -p$local_db_pass $local_db_name < \"$tmpfile\"";
    exec($socket_cmd . " 2>&1", $socket_output, $socket_result);

    if ($socket_result !== 0) {
        echo "❌ Socket import also failed:\n" . implode("\n", $socket_output) . "\n";
        unlink($tmpfile);
        exit(1);
    } else {
        echo "✅ Imported via socket.\n";
    }
} else {
    echo "✅ Imported via TCP.\n";
}

unlink($tmpfile);
echo "🎉 Sync complete: wp-content and remote DB imported successfully.\n";
