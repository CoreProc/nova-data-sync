<?php

namespace Coreproc\NovaDataSync\Export\Jobs;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Export\Models\Export;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

abstract class ExportProcessor implements ShouldQueue
{
    protected ?Authenticatable $user = null;

    protected string $disk = '';

    protected string $name = '';

    protected string $directory = '';

    abstract public function query(): Builder;

    /**
     * Extend this method to format the item before exporting
     */
    public function formatRow($row): array
    {
        return $row;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $jobs = [];
        $perPage = 500; // Number of items per page
        $totalRecords = $this->query()->count();
        $totalPages = ceil($totalRecords / $perPage);
        $batchUuid = Str::uuid();
        $exportName = $this->name();
        $exportDisk = $this->disk();
        $exportDirectory = $this->directory();

        Log::info('[' . self::class . '] Exporting query', ['count' => $totalRecords]);

        $export = $this->initializeExport($totalRecords);

        if ($totalRecords <= 0) {
            Log::info('[' . self::class . '] No records to export');
            $export->update([
                'status' => Status::COMPLETED->value,
                'completed_at' => now(),
            ]);
            return;
        }

        for ($page = 1; $page <= $totalPages; $page++) {
            $jobs[] = new ExportToCsv($this, $page, $perPage, $batchUuid);
        }

        Bus::batch($jobs)
            ->progress(function (Batch $batch) use ($export) {
                $export->update([
                    'status' => Status::IN_PROGRESS->value,
                ]);
            })
            ->then(function (Batch $batch) use ($export, $batchUuid, $exportDisk, $exportName, $exportDirectory) {
                // Collate and upload to disk job
                dispatch(new CollateExportsAndUploadToDisk($export, $batchUuid, $exportName, $exportDisk, $exportDirectory));

                Log::debug("[{$exportName}] Export completed.", [
                    "exportId" => $export->id,
                    "batchId" => $batch->id,
                    "totalJobs" => $batch->totalJobs,
                    "failedJobs" => $batch->failedJobs,
                ]);
            })
            ->allowFailures()
            ->name($this->name())
            ->dispatch();
    }

    protected function name(): string
    {
        if (empty($this->name)) {
            // return the base name of the class
            return class_basename($this);
        }

        return $this->name;
    }

    protected function directory(): string
    {
        return $this->directory;
    }

    protected function disk(): string
    {
        if (empty($this->disk)) {
            return config('nova-data-sync.exports.disk');
        }

        return $this->disk;
    }

    private function initializeExport(int $totalRecords): Export
    {
        return Export::query()->create([
            'user_id' => $this->user?->id ?? null,
            'user_type' => !empty($this->user) ? get_class($this->user) : null,
            'status' => Status::PENDING->value,
            'processor' => self::class,
            'file_total_rows' => $totalRecords,
            'started_at' => now(),
        ]);
    }

    public function setUser(Authenticatable $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function setDirectory(string $directory): self
    {
        $this->directory = $directory;

        return $this;
    }
}
