<?php

namespace Coreproc\NovaDataSync\Export\Nova\Action;

use Coreproc\NovaDataSync\Export\Jobs\ExportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

abstract class ExportNovaAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $standalone = true;

    abstract protected function processor(ActionFields $fields, Collection $models): ExportProcessor;

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models): void
    {
        dispatch($this->processor($fields, $models));
    }
}
