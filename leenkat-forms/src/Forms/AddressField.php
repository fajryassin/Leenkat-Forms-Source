<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

final class AddressField {
    public static function data(): array { return require __DIR__ . '/address-data.php'; }
    public static function parts(array $settings): array {
        $parts = ['address1','address2','city','state','postal'];
        if (($settings['address_scheme'] ?? 'us') === 'international') { $parts[] = 'country'; }
        return array_values(array_filter($parts, static fn(string $part): bool => !in_array($part, ['address2','postal','country'], true) || empty($settings['address_parts'][$part]['hidden'])));
    }
    public static function captions(array $settings): array {
        $us = ($settings['address_scheme'] ?? 'us') === 'us';
        return ['address1'=>__('Address Line 1','leenkat-forms'),'address2'=>__('Address Line 2','leenkat-forms'),'city'=>__('City','leenkat-forms'),'state'=>$us ? __('State','leenkat-forms') : __('State / Province / Region','leenkat-forms'),'postal'=>$us ? __('Zip Code','leenkat-forms') : __('Postal Code','leenkat-forms'),'country'=>__('Country','leenkat-forms')];
    }
    public static function options(string $part, array $settings): array {
        $data = self::data();
        return $part === 'country' ? $data['countries'] : ($part === 'state' && ($settings['address_scheme'] ?? 'us') === 'us' ? $data['states'] : []);
    }
}
