<?php

namespace App\Support\Shipping;

use Illuminate\Support\Str;

/**
 * The places a store can ship to. One list for the dashboard editor, the storefront checkout and
 * the validation rules, so they can never disagree.
 */
final class Locations
{
    /** A zone with this "country" covers every country no more specific zone claims. */
    public const EVERYWHERE = '*';

    /** @var array<string, string> */
    public const COUNTRIES = [
        'NG' => 'Nigeria', 'GH' => 'Ghana', 'KE' => 'Kenya', 'ZA' => 'South Africa', 'TZ' => 'Tanzania', 'UG' => 'Uganda',
        'RW' => 'Rwanda', 'ET' => 'Ethiopia', 'EG' => 'Egypt', 'MA' => 'Morocco', 'SN' => 'Senegal', 'CI' => "Côte d'Ivoire",
        'CM' => 'Cameroon', 'BJ' => 'Benin', 'TG' => 'Togo', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        'GB' => 'United Kingdom', 'IE' => 'Ireland', 'FR' => 'France', 'DE' => 'Germany', 'NL' => 'Netherlands', 'ES' => 'Spain', 'IT' => 'Italy',
        'US' => 'United States', 'CA' => 'Canada', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IN' => 'India', 'CN' => 'China', 'AU' => 'Australia',
    ];

    /** @var list<string> */
    public const NIGERIAN_STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti',
        'Enugu', 'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun',
        'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    /** Spellings people actually type, mapped to the canonical name. */
    private const STATE_ALIASES = [
        'abuja' => 'FCT', 'fct abuja' => 'FCT', 'abuja fct' => 'FCT', 'federal capital territory' => 'FCT', 'f c t' => 'FCT',
        'akwaibom' => 'Akwa Ibom', 'crossriver' => 'Cross River', 'nassarawa' => 'Nasarawa',
    ];

    /**
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /**
     * Canonical Nigerian state for whatever a customer typed ("lagos state", "ABUJA", "F.C.T"), or null if unknown.
     */
    public static function canonicalState(?string $input): ?string
    {
        $key = trim((string) preg_replace('/\s+/', ' ', str_replace(['.', '-', ','], ' ', Str::lower((string) $input))));
        $key = trim((string) preg_replace('/\s+state$/', '', $key));

        if ($key === '') {
            return null;
        }

        if (isset(self::STATE_ALIASES[$key])) {
            return self::STATE_ALIASES[$key];
        }

        foreach (self::NIGERIAN_STATES as $state) {
            if (Str::lower($state) === $key) {
                return $state;
            }
        }

        return null;
    }
}
