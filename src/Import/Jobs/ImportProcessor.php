<?php

namespace Coreproc\NovaDataSync\Import\Jobs;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Str;
use Throwable;

abstract class ImportProcessor implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected SimpleExcelWriter $failedImportsReportWriter;
    protected string $className;
    protected int $processedCount = 0;
    protected int $failedCount = 0;

    public function __construct(
        protected Import $import,
        protected string $csvFilePath,
        protected int    $index
    )
    {
        $this->queue = config('nova-data-sync.imports.queue', 'default');
        $this->className = self::class;
        Log::debug('[' . $this->className . '] Initialized');
    }

    abstract public static function expectedHeaders(): array;

    abstract protected function rules(array $row, int $rowIndex): array;

    /**
     * Process the row and return your newly created model.
     *
     * @var array $row contains the row data from the CSV file.
     */
    abstract protected function process(array $row, int $rowIndex): void;

    public static function chunkSize(): int
    {
        return config('nova-data-sync.imports.chunk_size', 1000);
    }

    /**
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function handle(): void
    {
        if ($this->shouldQuit()) {
            return;
        }

        Log::debug('[' . $this->className . '] Starting import...');

        // Initialize failed imports report
        $this->initializeFailedImportsReport();

        // Check if csvfilepath exists, if not, get the media content and save the csvfilepath locally
        if (!file_exists($this->csvFilePath)) {
            $media = $this->import->getFirstMedia('file');
            $mediaContent = stream_get_contents($media->stream());
            file_put_contents($this->csvFilePath, $mediaContent);
        }

        $readerRows = SimpleExcelReader::create($this->csvFilePath, 'csv')->getRows();

        $rows = $readerRows->skip($this->index)->take(static::chunkSize());

        $rows->each(function ($row, $index) {
            if ($this->shouldQuit()) {
                return false;
            }

            $rowIndex = $index + 1;

            try {
                $this->validateRow($row, $rowIndex);
                $this->process($row, $rowIndex);
                $this->incrementTotalRowsProcessed();
            } catch (Throwable $e) {
                $this->incrementTotalRowsFailed($row, $rowIndex, $e->getMessage());
            }

            return true;
        });

        // Ensure any remaining increments are saved
        if ($this->processedCount > 0) {
            $this->import->increment('total_rows_processed', $this->processedCount);
            $this->processedCount = 0;
        }
        if ($this->failedCount > 0) {
            $this->import->increment('total_rows_failed', $this->failedCount);
            $this->failedCount = 0;
        }

        $this->failedImportsReportWriter->close();

        // Upload to media library
        $this->import->addMedia($this->failedImportsReportWriter->getPath())
            ->toMediaCollection('failed-chunks', config('nova-data-sync.imports.disk'));
    }

    protected function incrementTotalRowsProcessed(): void
    {
        $this->processedCount++;
        if ($this->processedCount >= 100) {
            Log::debug("[{$this->className}] Processed 100 rows, committing to database...");
            $this->import->increment('total_rows_processed', $this->processedCount);
            $this->processedCount = 0;
        }
    }

    protected function incrementTotalRowsFailed($row, $rowIndex, $message): void
    {
        Log::debug("[{$this->className}] Failed row {$rowIndex}", [
            'message' => $message,
            'row' => $row,
        ]);

        data_set($row, 'origin_row', $rowIndex);
        data_set($row, 'error', $message);

        $this->failedImportsReportWriter->addRow($row);

        $this->failedCount++;
        if ($this->failedCount >= 100) {
            Log::debug("[{$this->className}] Failed 100 rows, committing to database...");
            $this->import->increment('total_rows_failed', $this->failedCount);
            $this->failedCount = 0;
        }

        $this->incrementTotalRowsProcessed();
    }

    private function initializeFailedImportsReport(): void
    {
        $fileName = "import-{$this->import->id}-failed-chunk-" . now()->format('YmdHis') . '-' . Str::random(6) . '.csv';
        $filePath = storage_path('app/' . $fileName);

        Log::debug('[ImportProcessor] Initializing failed imports report', [
            'file_path' => $filePath,
        ]);

        $this->failedImportsReportWriter = SimpleExcelWriter::create($filePath);
    }

    /**
     * @throws ValidationException
     */
    protected function validateRow(array $row, int $rowIndex): bool
    {
        if (empty($this->rules($row, $rowIndex))) {
            return true;
        }

        $validator = validator($row, $this->rules($row, $rowIndex));

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return true;
    }

    protected function shouldQuit(): bool
    {
        // Check the status of Import model to see if it is still in progress every 10 seconds,
        // if not, return false. Check using cache to avoid hitting the database every time
        return Cache::remember('nova-data-sync-import-' . $this->import->id . '-should-stop', now()->addSeconds(10),
            function () {
                $this->import->refresh();
                return $this->import->status !== Status::IN_PROGRESS->value;
            }
        );
    }
}
