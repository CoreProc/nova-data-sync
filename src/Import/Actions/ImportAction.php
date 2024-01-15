<?php

namespace Coreproc\NovaDataSync\Actions;

use App\Nova\Imports\TestImport\TestImportProcessor;
use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Jobs\BulkImportProcessor;
use Coreproc\NovaDataSync\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;

abstract class ImportAction extends Action
{
    use InteractsWithQueue;
    use Queueable;

    public $onlyOnIndex = true;

    public $standalone = true;

    public $withoutConfirmation = false;

    public $confirmText = '';

    private string $helpText = '';

    public string $processor;

    public function __construct()
    {
        $this->onQueue(config('nova-data-sync.imports.queue', 'default'));
    }

    abstract protected function expectedHeaders(): array;

    protected function checkHeaders(array $headers): bool
    {
        return count(array_diff($this->expectedHeaders(), $headers)) === 0;
    }

    /**
     * Override this method to provide help text
     */
    public function helpText(): string
    {
        $params = http_build_query(['class' => get_class($this)]);
        $url = url('nova-vendor/nova-data-sync/imports/sample?' . $params);

        return '<a href="' . $url . '">Download sample file</a>';
    }

    /**
     * Perform the action on the given models.
     *
     * @param ActionFields $fields
     * @return ActionResponse|Action
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function handle(ActionFields $fields): ActionResponse|Action
    {
        /** @var UploadedFile $file */
        $file = $fields->get('file');

        $excelReader = SimpleExcelReader::create($file->path(), 'csv')
            ->formatHeadersUsing(fn($header) => Str::snake($header));

        /**
         * Check the required columns
         */
        if ($this->checkHeaders($excelReader->getHeaders()) === false) {
            return Action::danger('Your upload has invalid headers.');
        }

        $excelReader->getRows()->count();

        /** @var Import $import */
        $import = Import::query()->create([
            'user_id' => request()->user()->id,
            'user_type' => get_class(request()->user()),
            'filename' => $file->getClientOriginalName(),
            'status' => Status::PENDING,
            'processor' => $this->processor,
            'file_total_rows' => $excelReader->getRows()->count(),
        ]);

        $import->addMedia($file->path())->toMediaCollection('file');

        dispatch(new BulkImportProcessor($import));

        return Action::redirect(url(Nova::path() . '/resources/imports/' . $import->id));
    }

    /**
     * Get the fields available on the action.
     */
    public function fields(NovaRequest $request): array
    {
        return [
            File::make('File', 'file')
                ->rules([
                    'required',
                    'mimes:txt,csv',
                ])
                ->help($this->helpText()),
        ];
    }
}
