<?php

namespace Coreproc\NovaDataSync\Import\Actions;

use Coreproc\NovaDataSync\Enum\Status;
use Coreproc\NovaDataSync\Import\Jobs\BulkImportProcessor;
use Coreproc\NovaDataSync\Import\Jobs\ImportProcessor;
use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
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
    public static function make(string $processor, string $filepath, ?Authenticatable $user = null): Import
    {
        $excelReader = SimpleExcelReader::create($filepath, 'csv');

        if (!is_subclass_of($processor, ImportProcessor::class) && $processor !== ImportProcessor::class) {
            throw new InvalidArgumentException('Class name must be a subclass of ' . ImportProcessor::class);
        }

        /**
         * Check the required columns
         */
        if (static::checkHeaders($processor::expectedHeaders(), $excelReader->getHeaders()) === false) {
            throw new InvalidArgumentException('File headers do not match the expected headers.');
        }

        $import = Import::query()->create([
            'user_id' => $user?->id ?? null,
            'user_type' => !empty($user) ? get_class($user) : null,
            'filename' => basename($filepath),
            'status' => Status::PENDING,
            'processor' => $processor,
            'file_total_rows' => $excelReader->getRows()->count(),
        ]);

        $import->addMedia($filepath)->toMediaCollection('file');

        dispatch(new BulkImportProcessor($import));

        return $import;
    }

    public function setUser(Authenticatable $user): self
    {
        $this->user = $user;

        return $this;
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
