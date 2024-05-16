<?php

namespace Coreproc\NovaDataSync\Export\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Export extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'user_type',
        'filename',
        'status',
        'processor',
        'file_total_rows',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('nova-data-sync.exports.table_name', 'exports'));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
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
