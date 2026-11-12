<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Display-only inbox filters. Inputs never change stored entry values. */
final class EntryListFilters {
    public static function dates(array $request): array|false {
        $start=ManagementPage::text($request,'date_from');$end=ManagementPage::text($request,'date_to');
        if ($start===''&&$end==='') return [];
        $dates=[];
        foreach ([$start,$end] as $i=>$value) {
            if ($value==='') {$dates[$i]=null;continue;}
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) return false;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
            if (!$date || $date->format('Y-m-d')!==$value) return false;
            $dates[$i]=$date;
        }
        if ($start!==''&&$end!==''&&$start>$end) return false;
        $utc=new \DateTimeZone('UTC');
        return [$dates[0]?$dates[0]->setTimezone($utc)->format('Y-m-d H:i:s'):'',$dates[1]?$dates[1]->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'):''];
    }
    public static function search(array $fields,array $request): string {
        global $wpdb;
        $text=mb_substr(ManagementPage::text($request,'s'),0,100);
        if ($text==='') return '';
        $source=ManagementPage::text($request,'search_field') ?: 'all';
        $operator=ManagementPage::text($request,'search_operator') ?: 'contains';
        if (!in_array($operator,['contains','not_contains','equals','not_equals'],true) || ($source!=='all'&&$source!=='entry_id'&&!isset($fields[$source]))) return ' AND 1=0';
        $contains=in_array($operator,['contains','not_contains'],true);
        $pattern=$contains?'%'.$wpdb->esc_like($text).'%':$text;
        $comparison=$contains?'LIKE':'=';
        $parts=[];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        if ($source==='entry_id') $parts[]=$wpdb->prepare("CAST(e.id AS CHAR) {$comparison} %s",$pattern);
        else {
            foreach (array_slice($source==='all'?array_keys($fields):[$source],0,300) as $id) {
                // The JSON path is prepared as a value; field IDs can never become SQL syntax.
                $path='$.'.wp_json_encode((string)$id,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                $parts[]=$wpdb->prepare("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.payload,%s)),'') {$comparison} %s",$path,$pattern);
            }
            if ($source==='all'&&ctype_digit($text)) $parts[]=$wpdb->prepare('e.id=%d',(int)$text);
        }
        if (!$parts) return ' AND 1=0';
        return ' AND '.(in_array($operator,['not_contains','not_equals'],true)?'NOT ':'').'('.implode(' OR ',$parts).')';
    }
    public static function context(array $request): array {
        $args=[];foreach (['s','search_field','search_operator','date_from','date_to','days','status','flag','orderby','order'] as $key) {
            $value=ManagementPage::text($request,$key);if ($value!=='') $args[$key]=$value;
        }
        return $args;
    }
}
