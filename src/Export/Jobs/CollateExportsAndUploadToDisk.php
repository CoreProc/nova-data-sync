<?php

namespace Coreproc\NovaDataSync\Export\Jobs;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Export\Models\Export;
use Coreproc\NovaDataSync\Import\Events\ExportCompletedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Throwable;

class CollateExportsAndUploadToDisk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Export $export,
        protected string $batchUuid,
        protected string $exportName,
        protected string $exportDisk,
        protected string $exportDirectory)
    {
        $this->onQueue(config('nova-data-sync.exports.queue', 'default'));
    }

    /**
     * Execute the job.
     *
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function handle(): void
    {
        $files = $this->getFilesSortedByIndex($this->batchUuid);

        Log::debug('[CollateExportsAndUploadToDisk] Collating files', [
            'files' => $files,
        ]);

        $collatedFileName = $this->exportName . '.csv';
        $collatedFilePath = $this->storagePath($collatedFileName);
        $collatedFileWriter = SimpleExcelWriter::create($collatedFilePath);

        foreach ($files as $file) {
            $fileRows = SimpleExcelReader::create($this->storagePath($file))->getRows();
            $collatedFileWriter->addRows($fileRows);
        }

        $collatedFileWriter->close();

        // Delete all files
        foreach ($files as $file) {
            Log::debug('[CollateExportsAndUploadToDisk] Deleting file', [
                'file' => $file,
            ]);
            unlink($this->storagePath($file));
        }

        $finalCollateFilePath = "{$this->exportDirectory}/{$collatedFileName}";

        Log::info('[CollateExportsAndUploadToDisk] Uploading collated file to disk', [
            'disk' => $this->exportDisk,
            'directory' => $this->exportDirectory,
            'path' => $finalCollateFilePath,
        ]);

        // Upload collated file to disk
        $this->export->addMedia($collatedFilePath)
            ->toMediaCollection('file', $this->exportDisk);

        $this->export->update([
            'filename' => $collatedFileName,
            'status' => Status::COMPLETED,
            'completed_at' => now(),
        ]);

        event(new ExportCompletedEvent($this->export));
    }

    protected function storagePath($path = ''): string
    {
        // create temp directory if it doesn't exist
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'));
        }

        return storage_path('app/temp/' . trim($path, '/'));
    }

    function getFilesSortedByIndex($uuid): array
    {
        $allFiles = scandir($this->storagePath());
        $filteredFiles = array_filter($allFiles, function ($file) use ($uuid) {
            // Matching the pattern 'export-{uuid}-{index}.csv'
            return preg_match("/export-{$uuid}-\d+\.csv$/", $file);
        });

        usort($filteredFiles, function ($a, $b) {
            // Extracting index from filename
            preg_match("/export-[^-]+-(\d+)\.csv$/", $a, $matchesA);
            $indexA = $matchesA[1] ?? 0;
            preg_match("/export-[^-]+-(\d+)\.csv$/", $b, $matchesB);
            $indexB = $matchesB[1] ?? 0;

            return $indexA <=> $indexB;
        });

        return $filteredFiles;
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->update([
            'status' => Status::FAILED->value
        ]);
    }
}