<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Category')
                ->schema([
                    TextInput::make('name')
                        ->label('Category name')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),
                ]),
        ]);
    }
}
