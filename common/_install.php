<?php

use function Deployer\{
    task, upload, get, run, parse
};

function _substitutions(array $paths)
{
    // may -i '' -e ... @see https://stackoverflow.com/questions/19456518
    $substitutions = '';

    foreach ($paths as $key => $value) {
        $substitutions .= sprintf("-e 's/%s/%s/g' ", $key, preg_quote(parse($value), '/'));
    }

    if (!empty($substitutions)) {
        run("find {{deploy_path}}/.deploy/ -type f -exec sed -i $substitutions {} \;");
    }
}

task('common:install:init', function () {
    $phpVersion = get('php_version');

    // create target
    run('if [ ! -d {{deploy_path}} ]; then mkdir -p {{deploy_path}}; fi');

    // upload config
    upload('{{app_path}}/*', "{{deploy_path}}/.deploy");

    _substitutions((array)get('substitutions', []));

    // main file
    run("ln -nfs {{deploy_path}}/.deploy/nginx/nginx.conf /etc/nginx/nginx.conf");

    // include dirs
    run("ln -nfs {{deploy_path}}/.deploy/nginx/bots.d /etc/nginx/bots.d");
    run("ln -nfs {{deploy_path}}/.deploy/nginx/http.d /etc/nginx/http.d");
    run("ln -nfs {{deploy_path}}/.deploy/nginx/server.d /etc/nginx/server.d");
    run("ln -nfs {{deploy_path}}/.deploy/nginx/vhost.d /etc/nginx/vhost.d");

    // link files
    run("ln -nfs {{deploy_path}}/.deploy/nginx/conf.d/blacklist.conf /etc/nginx/conf.d/blacklist.conf");
    run("chmod 0755 {{deploy_path}}/.deploy/nginx/conf.d/blacklist.conf");

    run("ln -nfs {{deploy_path}}/.deploy/cli/php.ini /etc/php/$phpVersion/cli/conf.d/10-custom.ini");

    // TODO: multi support
    run("ln -nfs {{deploy_path}}/.deploy/fpm/php.ini /etc/php/$phpVersion/fpm/conf.d/10-custom.ini");
    run("ln -nfs {{deploy_path}}/.deploy/fpm/pool/www.conf /etc/php/$phpVersion/fpm/pool.d/www.conf");

    run("ln -nfs {{deploy_path}}/.deploy/supervisor/supervisord.conf /etc/supervisor/supervisord.conf");

    // cannot use symlink for mysql due to permission on my.cnf denide by mysql user
    run("cp -f {{deploy_path}}/.deploy/mysql/my.cnf /etc/mysql/my.cnf");

    run("cp -f {{deploy_path}}/.deploy/ssl/* /etc/ssl");
})->setPrivate();

task('common:install:init_vhost', function () {
    $backendName = get('backend_name');
    $backendPort = get('backend_port');
    $deployPath = get('deploy_path');
    $vhostMapPath = get('vhost_map_path');
    $targetFile = "$deployPath/.deploy/nginx/vhost.d/$backendName.conf";

    run("cp -R {{deploy_path}}/.deploy/nginx/default_backend.conf.dist $targetFile");

    // upload user vhost map
    upload($vhostMapPath, "{{deploy_path}}/.deploy/nginx/http.d/vhost_map_user.conf");

    _substitutions([
        'EDIT_ME_BACKEND_PORT' => $backendPort,
        'EDIT_ME_BACKEND_NAME' => $backendName,
        'EDIT_ME_REMOTE_ADDR' => get('cloudflare_proxy_used') ? '$http_cf_connecting_ip' : '$remote_addr',
    ]);

    // upload user defined supervisors
    // be careful filename when using in multi-backend mode.
    foreach ((array)get('supervisors') as $file) {
        upload($file, "{{deploy_path}}/.deploy/supervisor/conf.d/");
    }
})->setPrivate();

task('common:install:testing', function () {
    run("rm -rf {{deploy_path}}/{{backend_name}}/current && mkdir -p {{deploy_path}}/{{backend_name}}/current/web");
    run("ln -nfs {{deploy_path}}/.deploy/app.php {{deploy_path}}/{{backend_name}}/current/web/app.php");
})->setPrivate();

task('common:install:clean', function () {
    run("rm -rf {{deploy_path}}/*");
    run("rm -rf {{deploy_path}}/.deploy");
})->setPrivate();

task('common:install:clear', function () {
    run("rm -rf {{deploy_path}}/{{backend_name}}/*");
})->setPrivate();

task('common:system:install', [
    'common:setup',
    'common:install:clean',
    'common:install:init',
    'common:install:init_vhost',
    'common:install:testing',
    'reload:fpm',
    'reload:nginx',
    'reload:mysql',
    'reload:supervisor',
])->desc('Initial system');

task('common:system:vhost', [
    'common:setup',
    'common:install:clear',
    'common:install:init_vhost',
    'common:install:testing',
    'reload:fpm',
    'reload:nginx',
    'reload:supervisor',
])->desc('Initial Vhost');

task('common:system:vhost_update', [
    'common:setup',
    'common:install:init_vhost',
    'reload:fpm',
    'reload:nginx',
    'update:supervisor',
])->desc('Update Vhost');

task('common:system:reset_config', [
    'common:setup',
    'common:install:init',
    'reload:fpm',
    'reload:nginx',
    'reload:mysql',
    'reload:supervisor',
])->desc('Reset system');

task('common:system:reset_nginx', [
    'common:setup',
    'common:install:init',
    'reload:nginx',
])->desc('Reset Only Nginx system');

task('common:system:clear', [
    'common:install:clear',
])->desc('Clear system');
