<?php

namespace Coreproc\NovaDataSync\Listeners;

use Coreproc\NovaDataSync\Events\ImportStartedEvent;
use Laravel\Nova\Notifications\NovaNotification;

class SendImportStartedNovaNotification
{
    /**
     * Handle the event.
     */
    public function handle(ImportStartedEvent $event): void
    {
        $event->import->user->notify(
            NovaNotification::make()
                ->message('Your import has started. Processor: ' . $event->import->processor_short_name)
                ->url('/resources/imports/' . $event->import->id)
                ->icon('view')
                ->type('info')
        );
    }
}
