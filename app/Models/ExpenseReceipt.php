<?php

namespace App\Models;

use Database\Factories\ExpenseReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of proof filed against an outlay — a photographed till slip or a
 * PDF a supplier emailed over.
 *
 * `path` is a tenant-scoped location on the private 'local' disk, never a URL:
 * receipts are streamed back through a permissioned route so one shop can
 * never read another's paperwork by guessing a filename.
 */
class ExpenseReceipt extends TenantModel
{
    /** @use HasFactory<ExpenseReceiptFactory> */
    use HasFactory;

    /**
     * `expense_id` and `user_id` are deliberately absent: what a receipt is
     * attached to, and who attached it, are decided by the relationship in the
     * controller, never by a posted form field.
     */
    protected $fillable = ['path', 'original_name', 'mime_type', 'size_in_bytes'];

    protected function casts(): array
    {
        return [
            'size_in_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** PDFs cannot render in an <img> tag, so the view needs to tell them apart. */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
