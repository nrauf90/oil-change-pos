<?php

namespace App\Enums;

/**
 * The buckets a workshop's daily outlay falls into.
 *
 * The first five are the ones the PRD names; the rest are the recurring
 * outlays a real shop hits — fuel for the pickup, an advance against wages,
 * the monthly rent, keeping the ramp and the compressor alive.
 */
enum ExpenseCategory: string
{
    case ShopSupplies = 'shop_supplies';
    case Refreshments = 'refreshments';
    case Utility = 'utility';
    case TeaLunch = 'tea_lunch';
    case PartsProcurement = 'parts_procurement';
    case Fuel = 'fuel';
    case WagesAdvance = 'wages_advance';
    case Rent = 'rent';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ShopSupplies => 'Shop Supplies',
            self::Refreshments => 'Refreshments',
            self::Utility => 'Utility',
            self::TeaLunch => 'Tea / Lunch',
            self::PartsProcurement => 'Parts Procurement',
            self::Fuel => 'Fuel',
            self::WagesAdvance => 'Wages / Advance',
            self::Rent => 'Rent',
            self::Maintenance => 'Maintenance',
            self::Other => 'Other',
        };
    }

    /** A tint per category so the cash-drawer breakdown reads at a glance. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::ShopSupplies => 'bg-sky-100 text-sky-800',
            self::Refreshments => 'bg-emerald-100 text-emerald-800',
            self::Utility => 'bg-indigo-100 text-indigo-800',
            self::TeaLunch => 'bg-orange-100 text-orange-800',
            self::PartsProcurement => 'bg-violet-100 text-violet-800',
            self::Fuel => 'bg-rose-100 text-rose-800',
            self::WagesAdvance => 'bg-amber-100 text-amber-900',
            self::Rent => 'bg-cyan-100 text-cyan-800',
            self::Maintenance => 'bg-lime-100 text-lime-800',
            self::Other => 'bg-slate-100 text-slate-700',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
