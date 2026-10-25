<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Core\BasePlugin;

/**
 * Stands in for the UI plugin (`RockSoftware/Ui`), which the users plugin only
 * suggests: loaded under its name, with an `auth` layout of its own.
 */
class UiStandInPlugin extends BasePlugin
{
    protected ?string $name = 'RockSoftware/Ui';

    protected bool $bootstrapEnabled = false;

    protected bool $routesEnabled = false;

    protected bool $middlewareEnabled = false;

    protected bool $consoleEnabled = false;

    protected bool $servicesEnabled = false;

    protected bool $eventsEnabled = false;

    /**
     * @return string
     */
    public function getPath(): string
    {
        return dirname(__DIR__) . DS . 'plugins' . DS . 'UiStandIn' . DS;
    }
}
