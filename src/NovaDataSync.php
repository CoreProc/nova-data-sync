<?php

namespace Coreproc\NovaDataSync;

use Coreproc\NovaDataSync\Resources\Import;
use Illuminate\Http\Request;
use Laravel\Nova\Exceptions\NovaException;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;

class NovaDataSync extends Tool
{
    /**
     * Perform any tasks that need to happen when the tool is booted.
     */
    public function boot(): void
    {
        Nova::script('nova-data-sync', __DIR__ . '/../dist/js/tool.js');
        Nova::style('nova-data-sync', __DIR__ . '/../dist/css/tool.css');
    }

    /**
     * Build the menu that renders the navigation links for the tool.
     * @throws NovaException
     */
    public function menu(Request $request): mixed
    {
        return MenuSection::make('Nova Data Sync', [
            MenuItem::make('Imports', '/resources/imports'),
            MenuItem::make('Exports', '/resources/exports'),
        ])
            ->path('/nova-data-sync')
            ->icon('server');
    }
}
