<?php

namespace App\Filament\Resources\Items\Pages;

use App\Enums\ItemType;
use App\Filament\Resources\Items\ItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeValidate(): void
    {
        $this->data = $this->normalizedData($this->data ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->normalizedData($data);
        unset($data['vehicleCompatibilities']);

        return $data;
    }

    protected function afterSave(): void
    {
        if (($this->record->type !== ItemType::Product) || $this->record->is_universal) {
            $this->record->vehicleCompatibilities()->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedData(array $data): array
    {
        $type = (string) ($data['type'] ?? ItemType::Product->value);
        $isUniversal = (bool) ($data['is_universal'] ?? false);

        if ($type !== ItemType::Product->value) {
            $data['is_universal'] = false;
            $data['vehicleCompatibilities'] = [];

            return $data;
        }

        if ($isUniversal) {
            $data['vehicleCompatibilities'] = [];
        }

        return $data;
    }
}
