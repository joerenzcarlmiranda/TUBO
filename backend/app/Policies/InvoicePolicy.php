<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->company_id !== null
            && $user->company_id === $invoice->company_id;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    public function retry(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }
}
