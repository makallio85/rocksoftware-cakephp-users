<?php
declare(strict_types=1);

/**
 * The settings page extends the application's own `App\Controller\AppController`
 * when there is one, so it renders inside the application's layout with its
 * menu, its signed-in user and its own checks, like any of its pages. Without
 * one (the plugin's own tests) it extends CakePHP's controller.
 *
 * This file only declares that alias; the autoloader includes it when
 * `RockSoftware\Users\Controller\SettingsBaseController` is first used.
 */

namespace RockSoftware\Users\Controller;

use Cake\Controller\Controller;

if (!class_exists(SettingsBaseController::class, false)) {
    class_alias(
        class_exists('App\Controller\AppController') ? 'App\Controller\AppController' : Controller::class,
        SettingsBaseController::class,
    );
}
