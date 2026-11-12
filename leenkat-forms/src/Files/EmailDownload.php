<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

/** Email bearer links: one file, seven days; fragments keep credentials out of request URLs. */
final class EmailDownload {
    public const TTL=604800;
    public static function hooks(): void {
        add_action('admin_post_lkf_email_file',[self::class,'serve']);
        add_action('admin_post_nopriv_lkf_email_file',[self::class,'serve']);
    }
    private static function encode(string $raw): string {return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
    private static function decode(string $raw): string|false {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$raw))return false;
        $decoded=base64_decode(strtr($raw,'-_','+/'),true);
        return is_string($decoded)&&self::encode($decoded)===$raw?$decoded:false;
    }
    private static function signature(string $body): string {return hash_hmac('sha256','lkf-email-download-v1|'.$body,wp_salt('auth'),true);}
    private static function endpoint(): string {
        // Cron/CLI workers may not have an HTTPS request context on an HTTPS site.
        $https=is_ssl()||wp_parse_url(home_url('/'),PHP_URL_SCHEME)==='https'||wp_parse_url((string)get_option('siteurl',''),PHP_URL_SCHEME)==='https';
        return add_query_arg('action','lkf_email_file',admin_url('admin-post.php',$https?'https':'admin'));
    }
    public static function url(int $entryId,array $file): ?string {
        global $wpdb;$id=(int)($file['file_id']??0);$field=$file['field_id']??'';
        if($entryId<1||$id<1||!is_string($field))return null;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $row=$wpdb->get_row($wpdb->prepare("SELECT f.* FROM {$wpdb->prefix}lkf_entry_files f INNER JOIN {$wpdb->prefix}lkf_entries e ON e.id=f.entry_id WHERE f.id=%d AND f.entry_id=%d AND f.field_id=%s AND e.status='complete'",$id,$entryId,$field),ARRAY_A);
        if(!$row||!in_array($row['status'],['private','media'],true))return null;
        $now=time();$body=self::encode(wp_json_encode(['f'=>$id,'e'=>$entryId,'h'=>$row['sha256'],'i'=>$now,'x'=>$now+self::TTL,'n'=>bin2hex(random_bytes(32))]));
        $token='v1.'.$body.'.'.self::encode(self::signature($body));
        return self::endpoint().'#token='.$token;
    }
    /** Stateless verification does not create options, credentials, logs or database rows. */
    public static function claims(string $token,?int $now=null): ?array {
        if(strlen($token)>768)return null;$pieces=explode('.',$token);
        if(count($pieces)!==3||$pieces[0]!=='v1')return null;
        $raw=self::decode($pieces[1]);$signature=self::decode($pieces[2]);
        if(!is_string($raw)||!is_string($signature)||strlen($signature)!==32||!hash_equals(self::signature($pieces[1]),$signature))return null;
        $c=json_decode($raw,true);$now??=time();
        if(!is_array($c)||array_keys($c)!==['f','e','h','i','x','n'])return null;
        foreach(['f','e','i','x'] as $key)if(!is_int($c[$key])||$c[$key]<1)return null;
        if(!is_string($c['h'])||!preg_match('/^[a-f0-9]{64}$/D',$c['h'])||!is_string($c['n'])||!preg_match('/^[a-f0-9]{64}$/D',$c['n']))return null;
        if($c['i']>$now+30||$c['x']-$c['i']!==self::TTL||$c['x']<=$now)return null;
        return $c;
    }
    public static function record(array $claims): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $row=$wpdb->get_row($wpdb->prepare("SELECT f.* FROM {$wpdb->prefix}lkf_entry_files f INNER JOIN {$wpdb->prefix}lkf_entries e ON e.id=f.entry_id WHERE f.id=%d AND f.entry_id=%d AND e.status='complete'",$claims['f'],$claims['e']),ARRAY_A);
        return $row&&in_array($row['status'],['private','media'],true)&&hash_equals($row['sha256'],$claims['h'])?$row:null;
    }
    private static function headers(): void {
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    private static function unavailable(): never {
        self::headers();header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        wp_die(esc_html__('This download link is invalid, expired or no longer available.','leenkat-forms'),'',['response'=>404]);
    }
    public static function serve(): never {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
        self::headers();$method=$_SERVER['REQUEST_METHOD']??'GET';
        if($method==='POST'){
            // Credentials are accepted only in a bounded POST body, never query parameters.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential. Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
            if(isset($_GET['token'])||(int)($_SERVER['CONTENT_LENGTH']??0)>2048)self::unavailable();
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential. Receipt/download credential is strictly parsed, scoped and cryptographically verified by claims()/ticket(); sanitizing opaque credentials would change their identity.
            $token=$_POST['token']??null;$claims=is_string($token)?self::claims($token):null;
            if(!$claims||!self::record($claims))self::unavailable();
            $lease=null;try{$lease=FileLease::acquire($claims['e']);$row=self::record($claims);$path=$row?FileRecords::resolve($row):null;}catch(\Throwable){FileLease::release($lease);self::unavailable();}
            if(!$path){FileLease::release($lease);self::unavailable();}
            Download::stream($path,$row['original_name'],$lease);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential.
        if($method!=='GET'||isset($_GET['token']))self::unavailable();
        // Minimal standalone landing: no theme, analytics, external assets or credential-bearing URLs.
        $nonce=base64_encode(random_bytes(24));header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Content-Type: text/html; charset=UTF-8');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core get_language_attributes returns escaped language/direction attributes; the remaining dynamic document values are escaped in their output contexts.
        echo '<!doctype html><html '.get_language_attributes().'><head><meta name="referrer" content="no-referrer"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html__('Download attachment','leenkat-forms').'</title></head><body><main><h1>'.esc_html__('Download attachment','leenkat-forms').'</h1><p id="message">'.esc_html__('This secure email link expires seven days after it was created. Anyone with the link can download this attachment until it expires.','leenkat-forms').'</p><form method="post" action="'.esc_url(self::endpoint()).'"><input type="hidden" name="token" id="token"><button id="download" disabled>'.esc_html__('Download file','leenkat-forms').'</button></form><noscript>'.esc_html__('Enable JavaScript to open this secure email link.','leenkat-forms').'</noscript></main>';
        self::printLandingScript($nonce);
        echo '</body></html>';exit;
    }

    private static function printLandingScript(string $nonce): void {
        $handle = 'leenkat-forms-email-download';
        wp_register_script($handle, false, [], LEENKAT_FORMS_VERSION, true);
        wp_enqueue_script($handle);
        wp_add_inline_script($handle, <<<'JS'
(()=>{const fragment=location.hash;history.replaceState(null,"",location.pathname+location.search);const token=fragment.startsWith("#token=")?fragment.slice(7):"";if(/^v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(token)&&token.length<=768){document.getElementById("token").value=token;document.getElementById("download").disabled=false;}})();
JS);
        $attributes = static function(array $attributes) use ($nonce, $handle): array {
            if (($attributes['id'] ?? '') === $handle . '-js-after') { $attributes['nonce'] = $nonce; }
            return $attributes;
        };
        add_filter('wp_inline_script_attributes', $attributes);
        try { wp_scripts()->do_items([$handle]); }
        finally { remove_filter('wp_inline_script_attributes', $attributes); }
    }
}
