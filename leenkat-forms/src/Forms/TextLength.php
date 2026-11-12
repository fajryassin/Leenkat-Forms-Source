<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Unicode scalar values, with CRLF/CR canonicalized to LF; no grapheme/NFC folding. */
final class TextLength {
    public static function normalize(string $value): string { return trim(str_replace(["\r\n","\r"],"\n",$value)," \t\n\r\0\x0B"); }
    public static function count(string $value, string $unit = 'characters'): int {
        $value=self::normalize($value);
        if ($unit === 'words') { return count(preg_split('/[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []); }
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (preg_match_all('/./us', $value) ?: 0);
    }
    public static function valid(string $value,array $settings,int $maximum): bool {
        if(preg_match('//u',$value)!==1 || strlen($value)>4*$maximum || self::count($value)>$maximum)return false;
        return empty($settings['limit_length_enabled']) || self::count($value,(string)($settings['length_unit'] ?? 'characters')) <= (int)($settings['max_length'] ?? 100);
    }
    public static function attributes(array $settings,int $maximum=1000): string {
        $limit = (int)($settings['max_length'] ?? 100);
        $words=!empty($settings['limit_length_enabled']) && ($settings['length_unit'] ?? 'characters')==='words';
        $characters=!empty($settings['limit_length_enabled'])&&!$words?min($maximum,$limit):$maximum;
        return ' data-lkf-max-chars="'.$characters.'" data-lkf-max-bytes="'.(4*$maximum).'"'.($words?' data-lkf-max-words="'.$limit.'"':'');
    }
}
