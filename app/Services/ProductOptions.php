<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Rules for product options: up to MAX_OPTIONS named options (Colour, Size, Thickness…), each
 * variation picks one value per option, and its name is those values joined ("Brown / 42").
 */
class ProductOptions
{
    public const MAX_OPTIONS = 3;
    public const MAX_VARIATIONS = 100;
    public const SEPARATOR = ' / ';

    /** Suggestions shown in the form; owners can type any other name. */
    public const PRESETS = [
        'Colour', 'Size', 'Material', 'Style', 'Weight', 'Volume', 'Pack size', 'Flavour', 'Shade', 'Storage',
        'Thickness', 'Diameter', 'Length', 'Width', 'Gauge', 'Grade', 'Finish',
    ];

    /** Validation rules to merge into product create/update. */
    public static function rules(): array
    {
        return [
            'option_names' => 'nullable|array|max:'.self::MAX_OPTIONS,
            'option_names.*' => 'required|string|max:40|distinct:ignore_case',
            'variations' => 'nullable|array|max:'.self::MAX_VARIATIONS,
            'variations.*.options' => 'nullable|array',
            'variations.*.options.*' => 'nullable|string|max:60',
        ];
    }

    /**
     * Checks the variations against the option names and fills in each variation's name.
     * Without option names, variations are the old single list and are left as they are.
     *
     * @return array{0: array<int, string>|null, 1: array<int, array>}
     */
    public static function normalise(?array $optionNames, array $variations): array
    {
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $optionNames ?? []), 'strlen'));
        if (! $names) {
            return [null, $variations];
        }

        $seen = [];
        foreach ($variations as $i => &$v) {
            $opts = [];
            foreach ($names as $name) {
                $value = trim((string) ($v['options'][$name] ?? ''));
                if ($value === '') {
                    throw ValidationException::withMessages(["variations.{$i}.options" => "Choose a {$name} for every row."]);
                }
                $opts[$name] = $value;
            }
            $key = mb_strtolower(implode("\u{1F}", $opts));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["variations.{$i}.options" => implode(self::SEPARATOR, $opts).' is listed twice.']);
            }
            $seen[$key] = true;
            $v['options'] = $opts;
            $v['name'] = implode(self::SEPARATOR, $opts);
        }
        unset($v);

        return [$names, $variations];
    }
}
