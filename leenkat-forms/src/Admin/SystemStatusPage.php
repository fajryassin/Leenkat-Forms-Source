<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Infrastructure\Diagnostics\Health;

/** Presentation only. Reuses the existing read-only health checks without storing results. */
final class SystemStatusPage {
    private static function text(string $value): string {
        $labels=['Administrator has all Leenkat Forms capabilities'=>__('Administrator has all Leenkat Forms capabilities','leenkat-forms'),'Editor has no Leenkat Forms capabilities'=>__('Editor has no Leenkat Forms capabilities','leenkat-forms'),'Migration lock'=>__('Migration lock','leenkat-forms'),'Stored schema matches code'=>__('Stored schema matches code','leenkat-forms'),'Registered Leenkat Forms REST routes'=>__('Registered Leenkat Forms REST routes','leenkat-forms'),'Database engines'=>__('Database engines','leenkat-forms'),'JSON extension'=>__('JSON extension','leenkat-forms'),'OpenSSL or Sodium'=>__('OpenSSL or Sodium','leenkat-forms'),'MySQL connection'=>__('MySQL connection','leenkat-forms'),'valid minimal definition'=>__('valid minimal definition','leenkat-forms'),'reject duplicate IDs'=>__('reject duplicate IDs','leenkat-forms'),'reject unknown step field'=>__('reject unknown step field','leenkat-forms'),'reject invalid schema version'=>__('reject invalid schema version','leenkat-forms'),'reject non-list fields'=>__('reject non-list fields','leenkat-forms'),'reject malformed required'=>__('reject malformed required','leenkat-forms'),'reject duplicate step assignment'=>__('reject duplicate step assignment','leenkat-forms'),'stable canonical JSON hash'=>__('stable canonical JSON hash','leenkat-forms'),'Unknown'=>__('Unknown','leenkat-forms'),'Passed'=>__('Passed','leenkat-forms'),'Failed'=>__('Failed','leenkat-forms'),'Missing'=>__('Missing','leenkat-forms'),'Check required'=>__('Check required','leenkat-forms'),'Not applicable'=>__('Not applicable','leenkat-forms'),'Not held'=>__('Not held','leenkat-forms'),'Held (review required)'=>__('Held (review required)','leenkat-forms'),'No routes registered'=>__('No routes registered','leenkat-forms'),'pending'=>__('pending','leenkat-forms'),'retry'=>__('retry','leenkat-forms'),'sent'=>__('sent','leenkat-forms'),'failed'=>__('failed','leenkat-forms'),'processing'=>__('processing','leenkat-forms'),'suppressed'=>__('suppressed','leenkat-forms')];
        if(str_starts_with($value,'Review: '))return __('Review: ','leenkat-forms').substr($value,8);
        return $labels[$value] ?? $value;
    }
    private static function section(string $title,array $rows): void {
        echo '<section class="lkf-system-section"><h3>'.esc_html($title).'</h3><table class="widefat striped"><tbody>';
        foreach($rows as [$label,$value,$state])echo '<tr><th scope="row">'.esc_html(self::text($label)).'</th><td><span'.($state!==''?' class="lkf-diagnostic-state is-'.esc_attr($state).'"':'').'><bdi dir="auto">'.esc_html(self::text($value)).'</bdi></span></td></tr>';
        echo '</tbody></table></section>';
    }
    private static function check(string $label,bool $ok,string $yes='Passed',string $no='Failed'): array {
        $labels=['Passed'=>__('Passed','leenkat-forms'),'Failed'=>__('Failed','leenkat-forms'),'Installed'=>__('Installed','leenkat-forms'),'Missing'=>__('Missing','leenkat-forms'),'Verified'=>__('Verified','leenkat-forms'),'Available'=>__('Available','leenkat-forms'),'Check required'=>__('Check required','leenkat-forms')];
        return [$label,$labels[$ok?$yes:$no] ?? ($ok?$yes:$no),$ok?'success':'error'];
    }
    private static function warning(string $message): string {
        // Migrations currently record only a missing table. Never echo an arbitrary server error.
        return preg_match('/^Missing table: [A-Za-z0-9_]+$/D',$message) ? sprintf(/* translators: %s: verified database table name. */ __('Missing table: %s','leenkat-forms'),substr($message,15)) : __('A database migration warning is recorded. Review database setup before running diagnostics again.','leenkat-forms');
    }
    public static function render(): void {
        if(!current_user_can('lkf_manage_forms'))wp_die(esc_html__('Access denied.','leenkat-forms'),'',['response'=>403]);
        $health=null;$failed=false;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['lkf_run_diagnostics']??'')==='1'){
            $nonce=is_string($_POST['_wpnonce']??null)?sanitize_text_field(wp_unslash($_POST['_wpnonce'])):'';
            if(!wp_verify_nonce($nonce,'lkf_run_diagnostics'))wp_die(esc_html__('Access denied or session expired. Reload and try again.','leenkat-forms'),'',['response'=>403]);
            try{$health=Health::snapshot();}catch(\Throwable $e){$failed=true;}
        }
        echo '<section class="lkf-tools-panel lkf-system-status"><h2>'.esc_html__('System Status','leenkat-forms').'</h2><p>'.esc_html__('View your environment and run the existing read-only diagnostic checks. Customer data is not changed.','leenkat-forms').'</p><form method="post" action="'.esc_url(admin_url('admin.php?page=leenkat-forms-tools&view=system-status')).'"><input type="hidden" name="lkf_run_diagnostics" value="1">';
        wp_nonce_field('lkf_run_diagnostics');
        echo '<button class="button button-primary">'.esc_html__('Run Diagnostics','leenkat-forms').'</button></form>';
        if($failed)echo '<div class="notice notice-error inline" role="alert"><p>'.esc_html__('Diagnostics could not complete. Reload and try again.','leenkat-forms').'</p></div>';
        elseif($health!==null)echo '<p class="lkf-diagnostics-result" role="status">'.esc_html__('Diagnostics completed for this request. Review each result below.','leenkat-forms').'</p>';
        else echo '<p class="description">'.esc_html__('Diagnostics have not been run for this request. Use Run Diagnostics to check validation, lifecycle, tables, indexes and runtime availability.','leenkat-forms').'</p>';

        self::section(__('Environment','leenkat-forms'),[
            [__('Plugin version','leenkat-forms'),LEENKAT_FORMS_VERSION,''],
            [__('PHP version','leenkat-forms'),PHP_VERSION,''],
            [__('WordPress version','leenkat-forms'),get_bloginfo('version'),''],
        ]);
        $database=[[__('Database schema version','leenkat-forms'),(string)get_option('lkf_db_version',0),'']];
        if($health!==null){
            foreach($health['tables'] as $table=>$exists)$database[]=self::check($table,$exists,'Installed','Missing');
            foreach($health['table_indexes'] as $name=>$ok)$database[]=self::check(sprintf(/* translators: %s: table suffix. */ __('Database indexes: %s','leenkat-forms'),$name),$ok,'Verified','Missing');
        }
        $warning=(string)get_option('lkf_migration_error','');
        if($warning!=='')$database[]=[__('Migration warning','leenkat-forms'),self::warning($warning),'warning'];
        self::section(__('Database and Schema','leenkat-forms'),$database);
        $contract=[[__('Form definition contract','leenkat-forms'),__('Schema v', 'leenkat-forms').\LeenkatForms\Forms\DefinitionValidator::SCHEMA_VERSION,'']];
        if($health!==null){
            $contract[]=self::check(__('Definition contract self-test','leenkat-forms'),$health['definition_contract_tests']);
            foreach($health['definition_test_cases'] as $name=>$passed)$contract[]=self::check(sprintf(/* translators: %s: contract test name. */ __('Contract test: %s','leenkat-forms'),self::text($name)),$passed);
        }else $contract[]=[__('Definition contract self-test','leenkat-forms'),__('Not run','leenkat-forms'),''];
        self::section(__('Form Definition Validation','leenkat-forms'),$contract);
        $lifecycle=[[__('Entry lifecycle contract','leenkat-forms'),sprintf(/* translators: %d: number of lifecycle states. */ __('%d states','leenkat-forms'),count(\LeenkatForms\Submissions\EntryStatus::all())), ''],[__('Public entry read endpoint','leenkat-forms'),\LeenkatForms\Security\SubmissionPolicy::PUBLIC_READ_ALLOWED?__('Enabled','leenkat-forms'):__('Disabled','leenkat-forms'),'']];
        $lifecycle[]=$health!==null?self::check(__('Entry lifecycle self-test','leenkat-forms'),$health['lifecycle_test']):[__('Entry lifecycle self-test','leenkat-forms'),__('Not run','leenkat-forms'),''];
        self::section(__('Entry Lifecycle','leenkat-forms'),$lifecycle);
        if($health!==null){
            $other=[[__('Module contracts','leenkat-forms'),implode(', ',$health['contracts']),'']];
            foreach($health['runtime'] as $name=>$passed)$other[]=self::check(sprintf(/* translators: %s: runtime capability name. */ __('Runtime: %s','leenkat-forms'),self::text($name)),$passed,__('Available', 'leenkat-forms'),__('Check required', 'leenkat-forms'));
            foreach($health['deliveries'] as $status=>$count)$other[]=[sprintf(/* translators: %s: delivery status. */ __('Deliveries: %s','leenkat-forms'),self::text($status)),(string)$count,''];
            self::section(__('Other Existing Diagnostics','leenkat-forms'),$other);
            $audit=[];
            foreach($health['foundation_audit'] as $name=>$value){
                if(is_array($value)){foreach($value as $table=>$engine)$audit[]=[sprintf(/* translators: %s: database table name. */ __('Engine: %s','leenkat-forms'),$table),$engine,$engine==='Missing'?'error':''];}
                else $audit[]=[$name,(string)$value,$value==='Passed'?'success':(str_contains((string)$value,'required')?'warning':'')];
            }
            self::section(__('M1.7 Foundation audit (read-only)','leenkat-forms'),$audit);
        }
        echo '</section>';
    }
}
