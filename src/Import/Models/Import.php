<?php

namespace Coreproc\NovaDataSync\Import\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Import extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('nova-data-sync.imports.table_name', 'imports'));
    }

    protected $fillable = [
        'user_id',
        'user_type',
        'filename',
        'status',
        'processor',
        'meta',
        'file_total_rows',
        'total_rows_processed',
        'total_rows_failed',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
        $this->addMediaCollection('failed')->singleFile();
        $this->addMediaCollection('failed-chunks');
    }

    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    public function getProcessorShortNameAttribute(): string
    {
        return class_basename($this->processor);
    }
}
