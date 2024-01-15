<?php

namespace Coreproc\NovaDataSync\Import\Actions;

use Coreproc\NovaDataSync\Import\Enum\Status;
use Coreproc\NovaDataSync\Import\Jobs\BulkImportProcessor;
use Coreproc\NovaDataSync\Import\Jobs\ImportProcessor;
use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportAction
{
    /**
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     * @throws InvalidArgumentException
     */
    public static function make(string $processor, string $filepath): Import
    {
        $excelReader = SimpleExcelReader::create($filepath, 'csv')
            ->formatHeadersUsing(fn($header) => Str::snake($header));

        if (!is_subclass_of($processor, ImportProcessor::class) && $processor !== ImportProcessor::class) {
            throw new InvalidArgumentException('Class name must be a subclass of ' . ImportProcessor::class);
        }

        /**
         * Check the required columns
         */
        if (static::checkHeaders($processor::expectedHeaders(), $excelReader->getHeaders()) === false) {
            throw new InvalidArgumentException('File headers do not match the expected headers.');
        }

        $excelReader->getRows()->count();

        /** @var Import $import */
        $import = Import::query()->create([
            'user_id' => request()->user()->id,
            'user_type' => get_class(request()->user()),
            'filename' => basename($filepath),
            'status' => Status::PENDING,
            'processor' => $processor,
            'file_total_rows' => $excelReader->getRows()->count(),
        ]);

        $import->addMedia($filepath)->toMediaCollection('file');

        dispatch(new BulkImportProcessor($import));

        return $import;
    }

    public static function checkHeaders(array $expectedHeaders, array $headers): bool
    {
        foreach ($expectedHeaders as $expectedHeader) {
            if (!in_array($expectedHeader, $headers)) {
                return false;
            }
        }

        return count(array_diff($expectedHeaders, $headers)) === 0;
    }
}
