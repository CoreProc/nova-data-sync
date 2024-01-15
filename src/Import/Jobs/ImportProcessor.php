<?php

namespace Coreproc\NovaDataSync\Import\Jobs;

use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
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

    public function __construct(
        protected Import         $import,
        protected LazyCollection $rows,
        protected int            $index
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
        Log::debug('[' . $this->className . '] Starting import...');

        // Initialize failed imports report
        $this->initializeFailedImportsReport();

        $chunkIndex = $this->index;

        $this->rows->each(function ($row, $index) use ($chunkIndex) {
            $rowIndex = $chunkIndex + $index + 1;

            try {
                $this->validateRow($row, $rowIndex);
                $this->process($row, $rowIndex);
                $this->incrementTotalRowsProcessed($rowIndex);
            } catch (Throwable $e) {
                $this->incrementTotalRowsFailed($row, $rowIndex, $e->getMessage());
            }
        });

        $this->failedImportsReportWriter->close();

        // Upload to media library
        $this->import->addMedia($this->failedImportsReportWriter->getPath())
            ->toMediaCollection('failed-chunks', config('nova-data-sync.imports.disk'));
    }

    protected function incrementTotalRowsProcessed($rowIndex): void
    {
        Log::debug("[{$this->className}] Processed row {$rowIndex}");

        $this->import->increment('total_rows_processed');
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

        $this->import->increment('total_rows_failed');

        $this->incrementTotalRowsProcessed($rowIndex);
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
}
