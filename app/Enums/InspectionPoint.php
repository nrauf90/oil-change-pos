<?php

namespace App\Enums;

/**
 * The standard check-points an oil-change shop walks through on every car.
 *
 * The list is code, not data: it is the shop's fixed procedure, it is what the
 * printed sheet on the wall says, and pinning it here means a stored
 * inspection can never reference a check-point the app no longer understands.
 */
enum InspectionPoint: string
{
    /* ---- Under the bonnet ------------------------------------------ */
    case EngineOil = 'engine_oil';
    case OilFilter = 'oil_filter';
    case AirFilter = 'air_filter';
    case CabinFilter = 'cabin_filter';
    case Coolant = 'coolant';
    case Battery = 'battery';
    case Belts = 'belts';

    /* ---- Brakes ---------------------------------------------------- */
    case BrakePads = 'brake_pads';
    case BrakeFluid = 'brake_fluid';

    /* ---- On the ramp ----------------------------------------------- */
    case TyreTread = 'tyre_tread';
    case TyrePressure = 'tyre_pressure';
    case Suspension = 'suspension';

    /* ---- Walk-around ----------------------------------------------- */
    case WiperBlades = 'wiper_blades';
    case Lights = 'lights';

    public function label(): string
    {
        return match ($this) {
            self::EngineOil => 'Engine Oil',
            self::OilFilter => 'Oil Filter',
            self::AirFilter => 'Air Filter',
            self::CabinFilter => 'Cabin Filter',
            self::Coolant => 'Coolant',
            self::Battery => 'Battery',
            self::Belts => 'Belts & Hoses',
            self::BrakePads => 'Brake Pads',
            self::BrakeFluid => 'Brake Fluid',
            self::TyreTread => 'Tyre Tread',
            self::TyrePressure => 'Tyre Pressure',
            self::Suspension => 'Suspension & Steering',
            self::WiperBlades => 'Wiper Blades',
            self::Lights => 'Lights & Indicators',
        };
    }

    /** The section of the walk-around this point belongs to, for the form. */
    public function group(): string
    {
        return match ($this) {
            self::EngineOil, self::OilFilter, self::AirFilter,
            self::CabinFilter, self::Coolant, self::Battery, self::Belts => 'Under the bonnet',
            self::BrakePads, self::BrakeFluid => 'Brakes',
            self::TyreTread, self::TyrePressure, self::Suspension => 'On the ramp',
            self::WiperBlades, self::Lights => 'Walk-around',
        };
    }

    /** Fixed display order, used to keep a stored report in procedure order. */
    public function position(): int
    {
        return array_search($this, self::cases(), true) ?: 0;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Every point bucketed by section, in procedure order.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $point) {
            $groups[$point->group()][] = $point;
        }

        return $groups;
    }
}
