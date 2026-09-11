<?php

namespace App\Actions;

use App\Models\Item;
use App\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Files an item's photo.
 *
 * Images live on the private 'local' disk under the tenant's own prefix, so
 * they are never reachable by URL and never visible to another shop —
 * exactly the same shape as `AttachExpenseReceipts`, but one image per item
 * instead of many receipts per expense.
 */
final readonly class AttachItemImage
{
    public const DIRECTORY = 'item-images';

    public function __construct(private TenantStoragePath $storagePaths) {}

    public function __invoke(Item $item, ?UploadedFile $file, bool $remove = false): void
    {
        if ($remove) {
            $this->storagePaths->delete($item->image_path, self::DIRECTORY);

            $item->image_path = null;
            $item->image_original_name = null;
            $item->image_mime_type = null;
            $item->save();

            return;
        }

        if ($file === null) {
            return;
        }

        if ($item->image_path !== null) {
            $this->storagePaths->delete($item->image_path, self::DIRECTORY);
        }

        $storedPath = $this->storagePaths->store($file, self::DIRECTORY);

        try {
            $item->image_path = $storedPath;
            // Trimmed to the column width so a pathological filename cannot
            // fail the update after the file is already on disk.
            $item->image_original_name = mb_substr($file->getClientOriginalName(), 0, 255);
            $item->image_mime_type = $file->getMimeType() ?? 'application/octet-stream';
            $item->save();
        } catch (Throwable $exception) {
            $this->storagePaths->delete($storedPath, self::DIRECTORY);

            throw $exception;
        }
    }
}
