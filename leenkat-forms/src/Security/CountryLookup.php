<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

/** Local allocation lookup. Request headers alone never establish a visitor's country. */
final class CountryLookup {
    public static function ready(): bool {return is_readable(LEENKAT_FORMS_DIR.'assets/data/country-v4.dat')&&is_readable(LEENKAT_FORMS_DIR.'assets/data/country-v6.dat');}
    public static function country(string $ip): ?string {
        $packed=inet_pton($ip);if($packed===false)return null;
        if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")$packed=substr($packed,12);
        if(!filter_var(inet_ntop($packed),FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only fixed-width local country data is searched using bounded native stream reads.
        $width=strlen($packed);$file=LEENKAT_FORMS_DIR.'assets/data/country-v'.($width===4?'4':'6').'.dat';$handle=@fopen($file,'rb');if(!$handle)return null;
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only fixed-width local country data is searched using bounded native stream reads.
            $header=fread($handle,10);if(strlen($header)!==10||substr($header,0,6)!=='LKFC'.chr(1).chr($width))return null;
            $count=unpack('N',substr($header,6))[1];$size=2*$width+2;if($count<1||filesize($file)!==10+$count*$size)return null;$low=0;$high=$count-1;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only fixed-width local country data is searched using bounded native stream reads.
            while($low<=$high){$mid=intdiv($low+$high,2);if(fseek($handle,10+$mid*$size)!==0)return null;$row=fread($handle,$size);if(strlen($row)!==$size)return null;if(strcmp($packed,substr($row,0,$width))<0)$high=$mid-1;elseif(strcmp($packed,substr($row,$width,$width))>0)$low=$mid+1;else{$code=substr($row,-2);return isset(\LeenkatForms\Forms\AddressField::data()['countries'][$code])?$code:null;}}
            return null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Read-only fixed-width local country data is searched using bounded native stream reads.
        } finally {fclose($handle);}
    }
    public static function inRange(string $ip,string $cidr): bool {
        $pieces=explode('/',$cidr);$address=inet_pton($pieces[0]);$value=inet_pton($ip);if($address===false||$value===false||strlen($address)!==strlen($value))return false;$bits=$pieces[1] ?? (string)(strlen($address)*8);if(!ctype_digit($bits)||(int)$bits>strlen($address)*8)return false;$bytes=intdiv((int)$bits,8);$remainder=(int)$bits%8;return substr($value,0,$bytes)===substr($address,0,$bytes)&&(!$remainder||(ord($value[$bytes])&(255<<(8-$remainder)))===(ord($address[$bytes])&(255<<(8-$remainder))));
    }
    public static function clientIp(array $server): string {
        $peer=is_string($server['REMOTE_ADDR'] ?? null)?$server['REMOTE_ADDR']:'';if(!filter_var($peer,FILTER_VALIDATE_IP))return '';
        $trusted=defined('LKF_TRUSTED_PROXIES')&&is_array(LKF_TRUSTED_PROXIES)?LKF_TRUSTED_PROXIES:[];
        $isTrusted=static fn(string $ip):bool=>(bool)array_filter($trusted,static fn($cidr):bool=>is_string($cidr)&&self::inRange($ip,$cidr));
        if(!$isTrusted($peer))return $peer;$forwarded=$server['HTTP_X_FORWARDED_FOR'] ?? '';if(!is_string($forwarded)||strlen($forwarded)>2048)return $peer;
        $chain=explode(',',$forwarded);$chain[]=$peer;for($i=count($chain)-1;$i>=0;$i--){$address=trim($chain[$i]);if(!filter_var($address,FILTER_VALIDATE_IP))return $peer;if(!$isTrusted($address))return $address;}return $peer;
    }
}
