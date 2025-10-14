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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $this->import->refresh();

        if ($this->import->status === Status::STOPPED->value) {
            Log::info('[BulkImportProcessor] Import was stopped. Marking import as completed.', [
                'import_id' => $this->import->id,
                'import_processor' => $this->import->processor,
            ]);
            $this->import->update([
                'completed_at' => now(),
            ]);

            return;
        }

        Log::info('[BulkImportProcessor] Starting bulk import...', [
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

        // Grab the number of rows
        $rowsCount = $this->import->file_total_rows;
        $chunkSize = $this->import->processor::chunkSize();

        $jobs = [];

        // Chunk the rows count and create a job for each
        for ($rowIndex = 0; $rowIndex < $rowsCount; $rowIndex += $chunkSize) {
            $jobs[] = new $this->import->processor($this->import, $filepath, $rowIndex);
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

        // Ensure fresh database connection for batch dispatch
        // This is critical in multi-tenant environments where DB::purge() may have been called
        // during tenant switching, causing the Connection object in DatabaseBatchRepository
        // to have a null PDO. This fix is safe for non-multi-tenant apps as well.
        $batchConnection = config('queue.batching.database');

        if ($batchConnection) {
            // Purge and reconnect to ensure clean connection state
            DB::purge($batchConnection);

            // Forget both BatchRepository singletons so they get rebuilt with fresh connection
            app()->forgetInstance('Illuminate\Bus\BatchRepository');
            app()->forgetInstance('Illuminate\Bus\DatabaseBatchRepository');

            // Force connection to be established
            DB::connection($batchConnection)->reconnect();
        }

        Bus::batch($jobs)
            ->then(function (Batch $batch) {
                Log::debug('[BulkImportProcessor] Batch finished');
            })
            ->finally(function (Batch $batch) use ($import, $filepath) {

                $import->refresh();

                if (in_array($import->status, [Status::STOPPED->value, Status::STOPPING->value])) {
                    Log::info('[BulkImportProcessor] Import was stopped.', [
                        'import_id' => $import->id,
                        'import_processor' => $import->processor,
                    ]);
                    $import->update([
                        'status' => Status::STOPPED,
                        'completed_at' => now(),
                    ]);
                } else {
                    $import->update([
                        'status' => Status::COMPLETED,
                        'completed_at' => now(),
                    ]);
                }

                Log::info('[BulkImportProcessor] Import finished.', [
                    'import_id' => $import->id,
                    'import_processor' => $import->processor,
                ]);

                // Remove the import file from local storage
                if (file_exists($filepath)) {
                    unlink($filepath);
                }

                event(new ImportCompletedEvent($import));

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
