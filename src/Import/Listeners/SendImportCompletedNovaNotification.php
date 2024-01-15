<?php

namespace Coreproc\NovaDataSync\Listeners;

use Coreproc\NovaDataSync\Events\ImportCompletedEvent;
use Laravel\Nova\Notifications\NovaNotification;

class SendImportCompletedNovaNotification
{
    /**
     * Handle the event.
     */
    public function handle(ImportCompletedEvent $event): void
    {
        $event->import->user->notify(
            NovaNotification::make()
                ->message('Your import has completed. Processor: ' . $event->import->processor_short_name)
                ->url('/resources/imports/' . $event->import->id)
                ->icon('view')
        );
    }
}
