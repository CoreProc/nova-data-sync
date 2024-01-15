<?php

namespace Coreproc\NovaDataSync\Import\Enum;

enum Status: string
{
    case PENDING = "Pending";
    case IN_PROGRESS = "In Progress";
    case FAILED = "Failed";
    case COMPLETED = "Completed";
}
