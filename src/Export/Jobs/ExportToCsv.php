<?php

namespace Coreproc\NovaDataSync\Export\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\SimpleExcel\SimpleExcelWriter;
use stdClass;

class ExportToCsv implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ExportProcessor $processor,
        protected int             $page,
        protected int             $perPage,
        protected string          $batchUuid)
    {
        $this->onQueue(config('nova-data-sync.exports.queue', 'default'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $items = $this->processor->query()->forPage($this->page, $this->perPage)->get();

        if (empty($items)) {
            return;
        }

        // Leading index is used to make sure that the files are sorted
        $leadingIndex = str_pad($this->page, 5, '0', STR_PAD_LEFT);

        $fileName = "export-{$this->batchUuid}-{$leadingIndex}.csv";
        $csvPath = $this->storagePath($fileName);
        $csvWriter = SimpleExcelWriter::create($csvPath, 'csv');

        $items->each(function ($item) use ($csvWriter) {
            if ($item instanceof Model) {
                $item = $item->toArray();
            }

            if ($item instanceof stdClass) {
                $item = json_decode(json_encode($item), true);
            }

            // Convert arrays inside $data to strings
            foreach ($item as $key => $value) {
                if (is_array($value)) {
                    $item[$key] = json_encode($value);
                }
            }

            $csvWriter->addRow($this->processor->formatRow($item));
        });

        $csvWriter->close();
    }

    protected function storagePath($path = ''): string
    {
        // create temp directory if it doesn't exist
        if (!is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'));
        }

        return storage_path('app/temp/' . trim($path, '/'));
    }
}
