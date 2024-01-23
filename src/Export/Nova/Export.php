<?php

namespace Coreproc\NovaDataSync\Export\Nova;

use App\Nova\Resource;
use Coreproc\NovaDataSync\Enum\Status as StatusEnum;
use Ebess\AdvancedNovaMediaLibrary\Fields\Files;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Status;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Export extends Resource
{
    /**
     * The model the resource corresponds to.
     */
    public static string $model = \Coreproc\NovaDataSync\Export\Models\Export::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'id',
    ];

    public static $displayInNavigation = false;

    /**
     * Get the fields displayed by the resource.
     *
     * @param NovaRequest $request
     * @return array
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Status::make('Status')
                ->loadingWhen([StatusEnum::PENDING->value, StatusEnum::IN_PROGRESS->value])
                ->failedWhen([StatusEnum::FAILED->value]),

            MorphTo::make('Initiated By', 'user')
                ->types(config('nova-data-sync.nova_resources.users'))
                ->sortable()
                ->readonly(),

            Text::make('Processor', 'processor_short_name'),

            Text::make('Filename', 'filename')
                ->readonly(),

            Number::make('File Total Rows')->onlyOnDetail(),

            DateTime::make('Created At')
                ->sortable()
                ->readonly(),

            DateTime::make('Started At')
                ->onlyOnDetail()
                ->readonly(),

            DateTime::make('Completed At')
                ->onlyOnDetail()
                ->readonly(),

            Files::make('File', 'file')
                ->hideFromIndex()
                ->readonly(),
        ];
    }

    /**
     * Get the cards available for the request.
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the filters available for the resource.
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     */
    public function actions(NovaRequest $request): array
    {
        return [
            //
        ];
    }

    /**
     * Determine if the current user can create new resources.
     */
    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /**
     * Determine if the current user can update the given resource.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /**
     * Determine if the current user can delete the given resource.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    /**
     * Determine if the current user can replicate the given resource or throw an exception.
     *
     * @throws AuthorizationException
     */
    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }
}
