<?php

namespace App\Filament\Resources\Items\Pages;

use App\Enums\ItemType;
use App\Filament\Resources\Items\ItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected function beforeValidate(): void
    {
        $this->data = $this->normalizedData($this->data ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->normalizedData($data);
        unset($data['vehicleCompatibilities']);

        return $data;
    }

    protected function afterCreate(): void
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
