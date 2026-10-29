<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Submitted = 'submitted';
    case Failed = 'failed';
}
