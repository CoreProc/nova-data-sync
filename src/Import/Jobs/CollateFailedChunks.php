<?php

namespace Coreproc\NovaDataSync\Import\Jobs;

use Coreproc\NovaDataSync\Import\Models\Import;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Str;

class CollateFailedChunks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected Import $import)
    {
        //
    }

    /**
     * Execute the job.
     *
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function handle(): void
    {
        Log::info('[CollateFailedChunks] Processing failed chunks', [
            'import_id' => $this->import->id,
            'processor' => $this->import->processor,
        ]);

        $failedChunksMedia = $this->import->getMedia('failed-chunks');

        if ($failedChunksMedia->count() === 0) {
            Log::info('[CollateFailedChunks] No failed chunks found', [
                'import_id' => $this->import->id,
                'processor' => $this->import->processor,
            ]);
            return;
        }

        $failedImportsFilePath = storage_path("app/import-{$this->import->id}-failed.csv");
        $failedImportWriter = SimpleExcelWriter::create($failedImportsFilePath);

        $hasFailedRows = false;

        $failedChunksMedia->each(function (Media $media) use ($failedImportWriter, &$hasFailedRows) {
            Log::debug('[CollateFailedChunks] Processing failed chunk media', [
                'import_id' => $this->import->id,
                'media_id' => $media->id,
            ]);

            // Temporarily save the file to the local storage
            $filepath = storage_path('app/' . $media->uuid . '-' . Str::random(4) . '.csv');
            $mediaContent = stream_get_contents($media->stream());
            file_put_contents($filepath, $mediaContent);

            $failedRows = SimpleExcelReader::create($filepath)->getRows();

            if ($failedRows->isNotEmpty()) {
                $hasFailedRows = true;
                // Read the file and write it to the failed import file
                $failedImportWriter->addRows($failedRows);
            }

            // Delete the file from the local storage
            unlink($filepath);

            // Delete the media from the import
            try {
                $media->delete();
            } catch (Exception $e) {
                Log::warning('[CollateFailedChunks] Failed to delete failed chunk media', [
                    'import_id' => $this->import->id,
                    'media_id' => $media->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });

        $failedImportWriter->close();

        // Delete all failed media chunks
        $this->import->clearMediaCollection('failed-chunks');

        if (!$hasFailedRows) {
            Log::info('[CollateFailedChunks] No failed rows found', [
                'import_id' => $this->import->id,
                'processor' => $this->import->processor,
            ]);
            unlink($failedImportsFilePath);
            return;
        }

        $this->import->addMedia($failedImportsFilePath)
            ->toMediaCollection('failed', config('nova-data-sync.imports.disk'));

        Log::info('[CollateFailedChunks] Finished processing failed chunks', [
            'import_id' => $this->import->id,
            'processor' => $this->import->processor,
        ]);
    }
}
