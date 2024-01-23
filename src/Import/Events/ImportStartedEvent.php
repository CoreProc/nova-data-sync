<?php

namespace Coreproc\NovaDataSync\Import\Events;

use Coreproc\NovaDataSync\Import\Models\Import;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImportStartedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Import $import)
    {
        //
    }
}
