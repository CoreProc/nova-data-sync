<?php

namespace Coreproc\NovaDataSync\Import\Nova\Actions;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

class ImportStopAction extends Action
{
    public $onlyOnDetail = true;

    public function name(): string
    {
        return 'Stop Import';
    }

    /**
     * Perform the action on the given models.
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        /** @var Import $import */
        $import = $models->first();

        $import->update([
            'status' => Status::STOPPED->value,
        ]);

        return Action::message('Import has been stopped.');
    }
}
