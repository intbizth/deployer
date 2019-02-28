<?php

use Deployer\Deployer;
use Deployer\Host\Host;
use Deployer\Task\Context;

use function Deployer\{
    task, writeln, get, set, run, inventory, upload, before, after, parse
};

use function Deployer\Support\{
    array_merge_alternate
};

require 'recipe/symfony4.php';
require 'recipe/cachetool.php';
require 'recipe/cloudflare.php';
require '_docker.php';
require '_install.php';
require '_system.php';
require '_override.php';
require '_deploy.php';

set('git_tty', true);
// TODO: parse env via `hosts.yml`
set('env', [
    'APP_ENV' => 'prod',
    'APP_DEBUG' => '0',
]);

// Symfony console bin
set('sf', function () {
    // can't use `release_path` case of using on none-release task.
    return '{{bin/php}} {{deploy_path}}/current/bin/console';
});

/**
 * @param $file
 *
 * @throws Exception
 */
function servers($file)
{
    if (!file_exists($file) || !is_readable($file)) {
        throw new Exception("File `$file` doesn't exists or doesn't readable.");
    }

    $content = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'hosts.yml') . "\r\n" . file_get_contents($file);
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'deployer-server.yml';

    file_put_contents($file, $content);

    inventory($file);

    foreach (Deployer::get()->hosts as $host) {
        _define_tasks($host);
        _setup_tasks($host);
    }
}

function _setup_tasks(Host $host)
{
    $tasks = (array)$host->get('tasks');
    $before = $tasks['before'] ?? [];
    $after = $tasks['after'] ?? [];
    $task = $tasks['task'] ?? [];

    $selfName = $host->getHostname();

    foreach ($task as $name => $tasks) {
        task($selfName . ':' . $name, $tasks);
    }

    foreach ($before as $name => $tasks) {
        foreach ((array)$tasks as $task) {
            $name = preg_replace('/^self:', $selfName . ':', $name);
            $task = preg_replace('/^self:', $selfName . ':', $task);

            before($name, $task);
        }
    }

    foreach ($after as $name => $tasks) {
        foreach ((array)$tasks as $task) {
            $name = preg_replace('/^self:/', $selfName . ':', $name);
            $task = preg_replace('/^self:/', $selfName . ':', $task);

            after($name, $task);
        }
    }
}

function _define_tasks(Host $host)
{
    foreach ((array)$host->get('defines') as $task => $commands) {
        $runs = [];
        foreach ((array)$commands as $command) {
            if (preg_match('/^(run|sf_run|supervisor_ctl)\((.*)\)$/', $command, $match)) {
                $fn = $match[1];
                $arg = trim($match[2], preg_match('/^"/', $match[2]) ? '"' : "'");
            } else {
                throw new RuntimeException("Not supported command `$command`.");
            }

            if ('run' === $fn) {
                $fn = function ($arg) {
                    run($arg);
                };
            }

            $runs[] = [$fn, $arg];
        }

        task($host->getHostname() . ':' . $task, function () use ($runs) {
            foreach ($runs as $run) {
                call_user_func(...$run);
            }
        });
    }
}

function _apply_config(&$config)
{
    array_walk_recursive($config, function (&$item) {
        $item = parse($item);
    });
}

function sf_run($commands)
{
    // need to use `release_path` to use released console. (not use current_path case of not finish release yet.)
    $console = '{{bin/php}} {{release_path}}/bin/console';

    foreach ((array)$commands as $command) {
        run("$console $command");
    }
}

function supervisor_ctl($command)
{
    // unix server use default authen
    run("supervisorctl -usupervisor -psupervisor_password $command");
}

task('common:setup', function () {
    $hostname = Context::get()->getHost()->getHostname();
    writeln("> Setting up deploy environments on <fg=cyan>$hostname</fg=cyan> port <fg=cyan>{{port}}</fg=cyan>");

    set('deploy_path', get('deploy_root') . DIRECTORY_SEPARATOR . get('backend_name'));

    $environments = (array)get('environments', []);

    foreach (array_keys($environments) as $key) {
        $configs = $environments[$key];

        // `undefined` prevent exception, `has` not cover.
        if ($originConfig = get($key, 'undefined')) {
            if (is_array($originConfig) && is_array($configs) && array_key_exists(0, $configs)) {
                if ('@override' === $configs[0]) {
                    array_shift($configs);
                } else {
                    $configs = array_merge_alternate($originConfig, $configs);
                }
            }
        }

        if (is_string($configs) && !empty($configs)) {
            $configs = parse($configs);
        }

        if (is_array($configs)) {
            _apply_config($configs);
        }

        set($key, $configs);
    }

    if (true === get('cachetool', 'undefined')) {
        set('cachetool', get('php_fastcgi'));
    }

})->desc('Setup deploy environments.')->setPrivate();

/**
 * Copy locale file & reinstall assets!
 */
task('common:copy_local', function () {
    foreach ((array)get('copy_local_dirs') as $dir) {
        upload("$dir/", "{{release_path}}/$dir");
    }

    foreach ((array)get('copy_local_files') as $file) {
        upload($file, "{{release_path}}/$file");
    }

    /**sf_run('assets:install {{release_path}}/web --relative');

    if (get('sylius_theme_used')) {
    sf_run('sylius:theme:assets:install {{release_path}}/web --relative');
    }**/
});
