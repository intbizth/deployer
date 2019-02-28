<?php

use function Deployer\{
    task, get, run
};

/**
 * Adding not override!
 * Copy directories. Useful for vendors directories
 */
task('deploy:copy_files', function () {
    $files = get('copy_files');
    foreach ($files as $file) {
        // Delete file if exists.
        run("if [ -f $(echo {{release_path}}/$file) ]; then rm -rf {{release_path}}/$file; fi");
        // Copy file.
        run("if [ -f $(echo {{deploy_path}}/current/$file) ]; then cp -rpf {{deploy_path}}/current/$file {{release_path}}/$file; fi");
    }
})->desc('Copy files')->setPrivate();
