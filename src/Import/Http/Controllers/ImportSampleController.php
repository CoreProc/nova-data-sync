<?php

namespace Coreproc\NovaDataSync\Import\Http\Controllers;

use Coreproc\NovaDataSync\Import\Jobs\ImportProcessor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ImportSampleController extends Controller
{
    public function __invoke(Request $request)
    {
        $importProcessorClass = $request->get('class');

        if (class_exists($importProcessorClass) === false) {
            return response('', 404);
        }

        // Check if $importAction is an instance of ImportAction
        if (!is_subclass_of($importProcessorClass, ImportProcessor::class)
            && $importProcessorClass !== ImportProcessor::class) {
            return response('', 404);
        }

        $fileName = class_basename($importProcessorClass) . '-sample.csv';

        SimpleExcelWriter::streamDownload($fileName)
            ->addHeader($importProcessorClass::expectedHeaders())
            ->toBrowser();
    }
}
