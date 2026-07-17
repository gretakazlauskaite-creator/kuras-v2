<?php
namespace Deployer;

require 'vendor/autoload.php';
require 'vendor/deployer/deployer/recipe/common.php';

// ── Project ───────────────────────────────────────────────────
set('repository',     'git@github-kuras:dzentota/kuras.git');
set('git_tty',        false);
set('keep_releases',  5);
set('default_timeout', 300);

// Shared between releases (persisted across deploys)
add('shared_files', ['.env']);
add('shared_dirs',  ['public/uploads']);

// Dirs writable by the web server
add('writable_dirs', ['public/uploads']);

// ── Host ──────────────────────────────────────────────────────
host('134.122.71.31')
    ->set('remote_user',  'larasail')
    ->set('deploy_path',  '~/kuras')
    ->set('ssh_multiplexing', true);

// ── Tasks ─────────────────────────────────────────────────────

// Install Composer dependencies (no dev, no scripts)
desc('Install Composer dependencies');
task('deploy:vendors', function () {
    run('cd {{release_path}} && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction 2>&1');
});

// Run DB migrations
desc('Run database migrations');
task('deploy:migrate', function () {
    $migrations = glob(__DIR__ . '/migrations/*.sql');
    if (empty($migrations)) return;

    // Get DB credentials from shared .env
    $envPath = '{{deploy_path}}/shared/.env';
    run("set -a && source $envPath && set +a && " .
        'for f in {{release_path}}/migrations/*.sql; do ' .
        '  mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f" 2>&1 || true; ' .
        'done');
});

// Set up cron job for daily price import (idempotent)
desc('Install crontab');
task('deploy:cron', function () {
    $cronLine = '15 10 * * * cd {{deploy_path}}/current && php bin/import.php >> {{deploy_path}}/shared/import.log 2>&1';
    // Add only if not already present
    run("(crontab -l 2>/dev/null | grep -qF 'bin/import.php') || " .
        "(crontab -l 2>/dev/null; echo '$cronLine') | crontab -");
    info('Cron: ' . $cronLine);
});

// Clear application cache (version-bump file + cached files)
desc('Clear application cache');
task('deploy:cache:clear', function () {
    // Bump the version file so all web-cached data is instantly invalidated.
    // This is the same mechanism used by the import script.
    run('php -r "
        \$v = (string)time();
        @file_put_contents(\"/tmp/kuras_cache_version\", \$v, LOCK_EX);
        foreach (glob(\"/tmp/kuras_cache/*.cache\") ?: [] as \$f) { @unlink(\$f); }
        function_exists(\"apcu_clear_cache\") && apcu_clear_cache();
        echo \"Cache cleared (version={\$v})\n\";
    "');
});

// ── Deploy flow ───────────────────────────────────────────────
desc('Deploy kuras.pricer.lt');
task('deploy', [
    'deploy:prepare',       // create release dir, shared symlinks
    'deploy:vendors',       // composer install
    'deploy:migrate',       // run any new SQL migrations
    'deploy:publish',       // symlink current → release
    'deploy:cache:clear',   // APCu
    'deploy:cron',          // ensure crontab is installed
]);

after('deploy:failed', 'deploy:unlock');

