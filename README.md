# Laravel Nova Data Sync

This is a Laravel Nova tool to that provides features to import and export CSV files.

![Import Action](https://raw.githubusercontent.com/coreproc/nova-data-sync/main/docs/import-index.png)

## Installation

You can install the package in to a Laravel app that uses Nova via composer:

```bash
composer require coreproc/nova-data-sync
```

Publish the package's config and migrations:

```bash
php artisan vendor:publish --provider="Coreproc\NovaDataSync\ToolServiceProvider"
```

This package requires [Laravel Horizon](https://laravel.com/docs/10.x/horizon) and comes with the package. If you have
not gone through Horizon's install process yet, you can install it by running:

```bash
php artisan horizon:install
```

Make sure to configure Horizon's environment processes in `config/horizon.php`.

You should also migrate the job batches table:

```bash
php artisan queue:batches-table

php artisan migrate
```

This package also requires [spatie/laravel-media-library](https://github.com/spatie/laravel-medialibrary) and comes with
this package. If you have not gone through
the installation process of Media Library, you should publish the migrations for it:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"

php artisan migrate
```

Publish Media Library's config file:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
```

Also run the following command to publish the config file for
[ebess/advanced-nova-media-library](https://github.com/ebess/advanced-nova-media-library):

```bash
php artisan vendor:publish --tag=nova-media-library
```

## Usage

Add the tool to your `NovaServiceProvider.php`:

```php
public function tools()
{
    return [
        // ...
        new \Coreproc\NovaDataSync\NovaDataSync(),
    ];
}
```

The Nova Data Sync tool should now appear in Nova's sidebar.

### Importing Data Using a Nova Action

To start with creating an Import feature, you will need two create two classes that extend the following:

- an `ImportProcess` class that contains the validation rules and process logic for each row of an imported CSV
  file.
- and an `ImportNovaAction` class that is essentially a Nova Action

Here is a sample `ImportProcessor`:

```php
<?php

namespace App\Nova\Imports\TestImport;

use Coreproc\NovaDataSync\Import\Jobs\ImportProcessor;use Illuminate\Support\Facades\Log;

class TestImportProcessor extends ImportProcessor
{
    public static function expectedHeaders(): array
    {
        return ['field1', 'field2'];
    }
    
    protected function rules(array $row, int $rowIndex): array
    {
        // Use Laravel validation rules to validate the values in each row.
        return [
            'field1' => ['required'],
            'field2' => ['required'],
        ];
    }
    
    protected function process(array $row, int $rowIndex): void
    {
        Log::info('processing row ' . $rowIndex);
        
        // Put the logic to process each row in your imported CSV here
    }
    
    /**
    * Optional: The number of rows to process per chunk. If not defined, it will use the 
    * default chunk size defined in the config file.
    */
    public static function chunkSize(): int
    {
        return 100;
    }
}
```

The `rules()` method is where you can define the validation rules for each row of the CSV file. It will be passed the
`$row` and `$rowIndex` parameters. The `$row` parameter is an array of the CSV row's data. The `$rowIndex` parameter is
the index. Return an array of Laravel's validation rules.

The expected headers are defined in the `expectedHeaders()` method. This is used to validate the headers of the CSV.

The `process()` method is where you can define the logic for each row of the CSV file. It will be passed the `$row` and
`$rowIndex` parameters. The `$row` parameter is an array of the CSV row's data. The `$rowIndex` parameter is the index.

If you throw an `Exception` inside the `process()` method, the row will be marked as failed and the exception message
will be shown in the failed report for the Import.

Next, create an `ImportNovaAction` class and define the `$processor` class you just created.

```php
<?php

namespace App\Nova\Imports\TestImport;

use Coreproc\NovaDataSync\Import\Nova\Actions\ImportNovaAction;

class TestImportAction extends ImportNovaAction
{
    // A sample processor will be shown below
    public string $processor = TestImportProcessor::class;
}
```

Next, put your `ImportNovaAction` inside the `actions()` method of one of your Nova Resources:

```php
public function actions(Request $request)
{
    return [
        new TestImportAction(),
    ];
}
```

It should look something like this:

![Import Action](https://raw.githubusercontent.com/coreproc/nova-data-sync/main/docs/import-action.png)


### Using the Import feature without the Nova Action

If you want to use the Import feature without the Nova Action, you can still use your ImportProcessor class. Here is an
example:

```php
use Coreproc\NovaDataSync\Import\Actions\ImportAction;

// Get the filepath of the CSV file. Should be coming from the local system.
$filepath = 'path/to/file.csv';

$importProcessor = TestImportProcessor::class;

$user = request()->user(); // This can be null

try {
    $importModel = ImportAction::make($importProcessor, $filepath, $user);
} catch (Exception $e) {
    // Handle exception
}
```

This will dispatch the jobs necessary to handle the import. You'll also be able to see the progress of the import in the
Nova Data Sync tool.


### Importing Configuration

You can find configuration options for the Import feature in `config/nova-data-sync.php`.

```php
'imports' => [
    'disk' => env('MEDIA_DISK', 'public'),
    'chunk_size' => 1000,
    'queue' => 'default',
],
```

### Exporting Data Using a Nova Action

### User Configuration

Each import and export have a morphable `user()` relationship. This is used to determine who imported or exported the
data. You will need to define Nova resource of each user type that you want to use for the import and export features.
This can be done in the `config/nova-data-sync.php` file.

```php
'nova_resources' => [

    /**
     * Since users are defined as morphable, we need to specify the Nova resource
     * associated with the users we want.
     */
    'users' => [
        \App\Nova\User::class,
    ],

],
```

By default, this already has the `App\Nova\User::class` resource. You can add more user resources like
`App\Nova\BackendUser` as needed.
