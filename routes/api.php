<?php

use Coreproc\NovaDataSync\Actions\ImportAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tool API Routes
|--------------------------------------------------------------------------
|
| Here is where you may register API routes for your tool. These routes
| are loaded by the ServiceProvider of your tool. They are protected
| by your tool's "Authorize" middleware by default. Now, go build!
|
*/

Route::get('/imports/sample', function (Request $request) {
    $importActionClass = $request->get('class');

    if (class_exists($importActionClass) === false) {
        return response('', 404);
    }

    /** @var ImportAction $importAction */
    $importAction = new $importActionClass();

    // Check if $importAction is an instance of ImportAction
    if ($importAction instanceof ImportAction === false) {
        return response('', 404);
    }

    $fileName = class_basename($importAction->processor) . '-sample.csv';

    \Spatie\SimpleExcel\SimpleExcelWriter::streamDownload($fileName)
        ->addHeader($importAction->expectedHeaders())
        ->toBrowser();
});
