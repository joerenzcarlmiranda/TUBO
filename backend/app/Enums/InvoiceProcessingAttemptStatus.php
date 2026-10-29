<?php

namespace App\Enums;

enum InvoiceProcessingAttemptStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
