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

require 'recipe/symfony3.php';
require 'recipe/cachetool.php';
require 'recipe/cloudflare.php';
require '_install.php';
require '_system.php';
require '_override.php';
require '_deploy.php';

set('git_tty', true);
set('http_strict_server_name', true);

// Symfony console bin
set('sf', function () {
    return sprintf('{{bin/php}} {{deploy_path}}/current/%s/console', trim(get('bin_dir'), '/'));
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

    foreach ($task as $name => $tasks) {
        task($host->getHostname() . ':' . $name, $tasks);
    }

    foreach ($before as $name => $tasks) {
        foreach ((array)$tasks as $task) {
            before($name, $task);
        }
    }

    foreach ($after as $name => $tasks) {
        foreach ((array)$tasks as $task) {
            after($name, $task);
        }
    }
}

function _define_tasks(Host $host)
{
    foreach ((array)$host->get('defines') as $task => $commands) {
        $runs = [];
        foreach ((array)$commands as $command) {
            if (preg_match('/^(run|sf_run)\((.*)\)$/', $command, $match)) {
                $fn = $match[1];
                $arg = trim($match[2], preg_match('/^"/', $match[2]) ? '"' : "'");
            } else {
                throw new RuntimeException("Not supported command `$command`.");
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
    foreach ((array)$commands as $command) {
        run("{{sf}} $command {{console_options}}");
    }
}

/**
 * Copy directories. Useful for vendors directories
 */
task('common:copy_files', function () {
    $files = get('copy_files');
    foreach ($files as $file) {
        // Delete file if exists.
        run("if [ -f $(echo {{release_path}}/$file) ]; then rm -rf {{release_path}}/$file; fi");
        // Copy file.
        run("if [ -f $(echo {{deploy_path}}/current/$file) ]; then cp -rpf {{deploy_path}}/current/$file {{release_path}}/$file; fi");
    }
})->desc('Copy files')->setPrivate();

task('common:setup', function () {
    $hostname = Context::get()->getHost()->getHostname();
    writeln("> Setting up deploy environments on <fg=cyan>$hostname</fg=cyan> port <fg=cyan>{{port}}</fg=cyan>");

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
 * Replace parameters with config
 */
task('common:build_parameters', function () {
    $localParameters = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(
        get('local_parameters')
    ));

    $parameters = array_replace_recursive(
        $localParameters, ['parameters' => get('parameters')]
    );

    // Querystring for app.js & style.css
    $parameters['parameters']['prod_asset_version'] = time();

    _apply_config($parameters);

    $newParameters = \Symfony\Component\Yaml\Yaml::dump($parameters);

    run("mkdir -p {{deploy_path}}/shared/app/config");
    run('echo "' . $newParameters . '" > {{deploy_path}}/shared/app/config/parameters.yml');
})->setPrivate();

/**
 * Copy locale file & reinstall assets!
 */
task('common:copy_local', function () {
    foreach (get('copy_local_dirs') as $dir) {
        upload("$dir/*", "{{release_path}}/$dir");
    }

    foreach ((array)get('copy_local_files') as $file) {
        upload($file, "{{release_path}}/$file");
    }

    sf_run('sylius:theme:assets:install {{release_path}}/web --symlink');
});