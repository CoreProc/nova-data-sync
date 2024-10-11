<?php

namespace Coreproc\NovaDataSync\Export\Jobs;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Export\Models\Export;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

abstract class ExportProcessor implements ShouldQueue
{
    use Queueable;

    protected ?Authenticatable $user = null;

    protected string $disk = '';

    protected string $name = '';

    protected string $directory = '';

    /**
     * Pass your query here as a Builder instance
     */
    abstract public function query(): Builder;

    public function __construct()
    {
        $this->onQueue($this->queue());
    }

    /**
     * Extend this method to format the item before exporting
     */
    public function formatRow($row): array
    {
        if ($row instanceof Model) {
            $row = $row->toArray();
        }

        if ($row instanceof stdClass) {
            $row = json_decode(json_encode($row), true);
        }

        return $row;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $jobs = [];
        $perPage = static::chunkSize(); // Number of items per page
        $totalRecords = $this->getQueryCount();
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
            ->allowFailures($this->allowFailures())
            ->name($this->name())
            ->onQueue(config('nova-data-sync.exports.queue', 'default'))
            ->dispatch();
    }

    public static function chunkSize(): int
    {
        return config('nova-data-sync.exports.chunk_size', 1000);
    }

    protected function name(): string
    {
        if (empty($this->name)) {
            // return the base name of the class
            return class_basename($this);
        }

        return $this->name;
    }

    /**
     * Override this in your ExportProcessor class to set a different queue.
     */
    protected function queue(): string
    {
        return config('nova-data-sync.exports.queue', 'default');
    }

    /**
     * Override this in your ExportProcessor class to set allow failures in the batch job.
     */
    protected function allowFailures(): bool
    {
        return true;
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

    protected function getQueryCount(): int
    {
        return $this->query()->count();
    }
}
