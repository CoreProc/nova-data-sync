<?php

namespace Coreproc\NovaDataSync\Import\Events;

use Coreproc\NovaDataSync\Export\Models\Export;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportStartedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Export $export)
    {
        //
    }
}
