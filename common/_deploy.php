<?php

use function Deployer\{
    task, after
};

task('common:install', [
    'common:setup',
    'common:system:clear',
    'deploy:info',
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:clear_paths',
    'deploy:shared',
    'deploy:assets',
    'deploy:vendors',
    'common:copy_local',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'cachetool:clear:apc',
    'deploy:writable',
    'deploy:symlink',
    'cleanup',
])->desc('First install system.');

task('common:deploy', [
    'common:setup',
    'deploy:info',
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:clear_paths',
    'deploy:shared',
    'deploy:assets',
    'deploy:vendors',
    'common:copy_local',
    'database:migrate',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'cachetool:clear:apc',
    'deploy:writable',
    'deploy:symlink',
    'cleanup',
])->desc('Deploy');

task('common:deploy_quick', [
    'common:setup',
    'deploy:info',
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:clear_paths',
    'deploy:shared',
    'deploy:copy_dirs',
    'deploy:copy_files',
    'database:migrate',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'cachetool:clear:apc',
    'deploy:writable',
    'deploy:symlink',
    'cleanup',
])->desc('Deploy');

after('cleanup', 'reload:supervisor');
after('cleanup', 'reload:fpm');
after('cleanup', 'reload:nginx');
after('cleanup', 'success');
after('deploy:failed', 'deploy:unlock');
after('rollback', 'reload:fpm');
