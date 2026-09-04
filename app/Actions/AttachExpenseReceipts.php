<?php

namespace App\Actions;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Files the proof behind an outlay.
 *
 * Receipts live on the private 'local' disk under the tenant's own prefix, so
 * they are never reachable by URL and never visible to another shop. If the
 * database write fails after a file has landed, the file is removed again —
 * an orphaned blob on disk with no row pointing at it is silent rot.
 */
final readonly class AttachExpenseReceipts
{
    public const DIRECTORY = 'expense-receipts';

    public function __construct(private TenantStoragePath $storagePaths) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function __invoke(Expense $expense, array $files, ?User $user = null): void
    {
        if ($files === []) {
            return;
        }

        /** @var list<string> $storedPaths */
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $storedPath = $this->storagePaths->store($file, self::DIRECTORY);
                $storedPaths[] = $storedPath;

                $receipt = new ExpenseReceipt([
                    'path' => $storedPath,
                    // Trimmed to the column width so a pathological filename
                    // cannot fail the insert after the file is already on disk.
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size_in_bytes' => $file->getSize() ?: 0,
                ]);

                // Who attached it comes from the session, never from the payload.
                $receipt->user()->associate($user);
                $expense->receipts()->save($receipt);
            }
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                $this->storagePaths->delete($storedPath, self::DIRECTORY);
            }

            throw $exception;
        }
    }

    /** Drops one receipt's row and its file together. */
    public function forget(ExpenseReceipt $receipt): void
    {
        $receipt->delete();

        $this->storagePaths->delete($receipt->path, self::DIRECTORY);
    }

    /**
     * Clears the files behind an expense that is about to be deleted. The rows
     * themselves go with the cascading foreign key.
     */
    public function purge(Expense $expense): void
    {
        foreach ($expense->receipts()->pluck('path') as $path) {
            $this->storagePaths->delete($path, self::DIRECTORY);
        }
    }
}
