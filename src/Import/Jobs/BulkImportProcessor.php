<?php

namespace Coreproc\NovaDataSync\Import\Jobs;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Import\Events\ImportCompletedEvent;
use Coreproc\NovaDataSync\Import\Events\ImportStartedEvent;
use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

class BulkImportProcessor implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(protected Import $import)
    {
        //
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        Log::debug('[BulkImportProcessor] Starting bulk import...', [
            'import_id' => $this->import->id,
            'import_processor' => $this->import->processor,
        ]);

        $this->import->update([
            'status' => Status::IN_PROGRESS,
            'started_at' => now(),
        ]);

        event(new ImportStartedEvent($this->import));

        $media = $this->import->getFirstMedia('file');

        // Temporarily save the file to the local storage
        $filepath = storage_path('app/' . $media->uuid);
        $mediaContent = stream_get_contents($media->stream());
        file_put_contents($filepath, $mediaContent);

        $readerRows = SimpleExcelReader::create($filepath, 'csv')->getRows();

        $chunks = $readerRows->chunk($this->import->processor::chunkSize());

        $jobs = [];

        // Chunk these rows then process it according to the import process class
        foreach ($chunks as $index => $rows) {
            $jobs[] = new $this->import->processor($this->import, $rows, $index + 1);
        }

        if (empty($jobs)) {
            Log::debug('[BulkImportProcessor] No jobs to dispatch. Marking import as completed.');

            $this->import->update([
                'status' => Status::COMPLETED,
                'completed_at' => now(),
            ]);

            // Remove the import file from local storage
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            event(new ImportCompletedEvent($this->import));

            return;
        }

        Log::debug('[BulkImportProcessor] Dispatching jobs...', [$jobs]);

        $import = $this->import;

        Bus::batch($jobs)
            ->then(function (Batch $batch) {
                Log::debug('[BulkImportProcessor] Batch finished');
            })
            ->finally(function (Batch $batch) use ($import, $filepath) {
                $import->update([
                    'status' => Status::COMPLETED,
                    'completed_at' => now(),
                ]);

                // Remove the import file from local storage
                if (file_exists($filepath)) {
                    unlink($filepath);
                }

                event(new ImportCompletedEvent($this->import));

                // Dispatch job to collate failed chunks to one file
                dispatch(new CollateFailedChunks($import))
                    ->onQueue(config('nova-data-sync.imports.queue', 'default'));
            })
            ->allowFailures()
            ->name($this->import->id . '-import')
            ->onQueue(config('nova-data-sync.imports.queue', 'default'))
            ->dispatch();
    }
}
