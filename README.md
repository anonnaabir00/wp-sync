# 🧩 WP Sync CLI Tool

A powerful artisan-style CLI tool to sync a remote WordPress site (files + database) to your LocalWP environment.

---

## 🚀 Features

- ✅ Sync `wp-content` via SSH (excluding specified folders)
- ✅ Dump remote DB via `mysqldump` and import locally
- ✅ Automatically update `siteurl` and `home` in local DB
- ✅ Multi-folder exclusion using `config.json`
- ✅ Securely push allowed folders to remote using `push`
- ✅ `--dry-run` flag for simulation
- ✅ `sync:all` command: one-shot sync + migrate
- ✅ Real-time animated progress using Symfony's ProgressBar
- ✅ Artisan-style CLI syntax like `php wp-sync sync`

---

## 🧰 Requirements

- PHP 8.0 or newer
- Composer
- Required CLI tools:
  - `sshpass` (for password-based SSH)
  - `rsync`
  - `mysqldump`
  - `mysql` client

---

### 💻 Quick Install on macOS (via Homebrew)

```bash
brew install php
brew install composer
brew install hudochenkov/sshpass/sshpass
brew install rsync
brew install mysql
```

---

### 🐧 Quick Install on Linux (Debian/Ubuntu)

```bash
sudo apt update
sudo apt install php php-cli php-mbstring unzip curl
sudo apt install composer
sudo apt install sshpass rsync mysql-client
```

---

### 🪟 Quick Install on Windows

#### Option A: Using Chocolatey

```powershell
choco install php composer rsync mysql sshpass
```

> 📝 You may need to use **Git Bash** or **WSL** for full compatibility with `rsync` and `sshpass`.

#### Option B: Manual Setup

- Install [PHP](https://windows.php.net/)
- Install [Composer](https://getcomposer.org/)
- Install [MySQL Tools](https://dev.mysql.com/downloads/)
- Use [Git Bash](https://gitforwindows.org/) or [Windows Subsystem for Linux](https://learn.microsoft.com/en-us/windows/wsl/)

---

## 📦 Installation

1. Open your LocalWP site folder and navigate to the `app/public` directory:

```bash
cd ~/Local\ Sites/your-site-name/app/public
```

2. Clone the CLI into this folder:

```bash
git clone https://github.com/anonnaabir00/wp-sync.git
cd wp-sync
```

> ⚠️ **Important:**  
> This tool is meant to be run inside your **LocalWP site's `public` folder**  
> so it can correctly sync your `wp-content` and local database.

3. Install dependencies:

```bash
composer install
chmod +x wp-sync
```

4. Add your configuration (see below)

---

## 🗂 Sample `config.json`

> Place this file in the `wp-sync` directory.

```json
{
  "local_wp_path": "/Users/yourname/Local Sites/example/app/public",

  "remote_host": "sftp.example.com",
  "remote_username": "youruser",
  "remote_path": "/htdocs",
  "auth_type": "password",
  "ssh_password": "yourpass123",

  "remote_db_host": "127.0.0.1",
  "remote_db_name": "example_remote_db",
  "remote_db_user": "dbuser",
  "remote_db_pass": "dbpass",

  "local_db_name": "example_local",
  "local_db_user": "root",
  "local_db_pass": "root",
  "local_db_host": "localhost",

  "local_site_url": "http://example.local",
  "table_prefix": "wp_",

  "exclude_dirs": [
    "uploads",
    "cache",
    "backups",
    "tmp"
  ],

  "push_dirs": [
    "themes/my-custom-theme",
    "mu-plugins",
    "languages"
  ]
}
```

> 🔐 For SSH keys instead of passwords:

```json
"auth_type": "key",
"ssh_key_path": "~/.ssh/id_rsa"
```

---

## ⚙️ Usage

> 💡 Always run commands from inside the `wp-sync` directory (`/app/public/wp-sync`)

```bash
php wp-sync sync             # Sync files + DB
php wp-sync migrate          # Update siteurl/home only
php wp-sync sync:all         # Full sync and migrate in one go
php wp-sync push             # Push specific folders from push_dirs[] to remote

php wp-sync sync --dry-run         # Simulate sync
php wp-sync sync:all --dry-run     # Simulate everything
```

---

## 💡 What It Does

- 📁 Syncs remote `wp-content` (excluding folders like `uploads`)
- 🧠 Dumps remote database securely over SSH
- 📥 Imports DB locally via TCP or socket fallback
- 🔁 Updates WordPress `siteurl` and `home` in your local DB
- 🚀 Clean CLI UX with progress animations
- 🔐 Pushes only folders explicitly whitelisted in `push_dirs[]`

---

## 🧪 Sample Output

```
📁 Syncing wp-content (excluding specified dirs)...
🧠 Exporting remote DB...
📥 Importing DB into local...
🔁 Updating siteurl/home to http://example.local
🎉 Sync complete!

🚀 Pushing allowed folders to remote server...
📂 Pushing: themes/my-custom-theme
✅ Successfully pushed: themes/my-custom-theme
🎉 Push complete!
```

---