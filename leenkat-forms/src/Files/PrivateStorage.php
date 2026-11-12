<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

/** New encrypted objects and read-compatible private objects from the submitted release. */
final class PrivateStorage {
    public static function inside(string $path,string $base): bool {
        $path=strtolower(str_replace('\\','/',rtrim($path,'/\\')));$base=strtolower(str_replace('\\','/',rtrim($base,'/\\')));
        return $path===$base||str_starts_with($path,$base.'/');
    }
    public static function noLinks(string $path): bool {
        $normalized=str_replace('\\','/',$path);$parts=[];
        foreach(explode('/',$normalized) as $part){if($part===''||$part==='.')continue;if($part==='..'){if(!$parts||end($parts)==='..')return false;array_pop($parts);}else $parts[]=$part;}
        $cursor=(str_starts_with($normalized,'/')?'/':'').implode('/',$parts);
        // Windows readlink resolves ordinary directories too; a changed target reveals a junction.
        while($cursor!==''&&dirname($cursor)!==$cursor){
            if(is_link($cursor))return false;$target=@readlink($cursor);
            if(is_string($target)&&strtolower(str_replace('\\','/',rtrim($target,'/\\')))!==strtolower(str_replace('\\','/',rtrim($cursor,'/\\'))))return false;
            $cursor=dirname($cursor);
        }
        return true;
    }
    public static function root(): string {return EncryptedStorage::root(true);}
    public static function legacyDefault(): string {
        $site=rtrim(ABSPATH,'/\\');
        return dirname($site,2).'/leenkat-private/'.substr(hash('sha256',$site.'|'.get_current_blog_id()),0,24);
    }
    public static function outsidePublicRoots(string $path, bool $legacy=false): bool {
        $site=rtrim(ABSPATH,'/\\');
        $uploads=wp_upload_dir(null,false);
        // DOCUMENT_ROOT is filesystem metadata, not a WP-slashed form value.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve path separators, reject control characters, resolve with realpath(), then compare canonical filesystem roots below. Text sanitizers can silently change valid directory names.
        $documentRoot=isset($_SERVER['DOCUMENT_ROOT'])&&is_string($_SERVER['DOCUMENT_ROOT'])?$_SERVER['DOCUMENT_ROOT']:'';
        if(preg_match('/[\x00-\x1f\x7f]/',$documentRoot))return false;
        $roots=[$site,$uploads['basedir']??'', $documentRoot];
        if($legacy)$roots[]=dirname($site);
        foreach($roots as $web){$base=$web!==''?realpath($web):false;if($base&&(self::inside($path,$base)||($legacy&&self::inside($base,$path))))return false;}
        return self::noLinks($path);
    }
    public static function legacyRoot(): string {
        $root=defined('LEENKAT_FORMS_LEGACY_STORAGE_DIR')?(string)LEENKAT_FORMS_LEGACY_STORAGE_DIR:(defined('LKF_PRIVATE_STORAGE_DIR')?(string)LKF_PRIVATE_STORAGE_DIR:self::legacyDefault());
        if(!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$root)||!self::noLinks($root))throw new \RuntimeException(esc_html__('Private file storage is unavailable.', 'leenkat-forms'));
        $normal=wp_normalize_path($root);
        if(preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$normal)||str_starts_with($normal,'//'))throw new \RuntimeException(esc_html__('Unsafe private storage path.', 'leenkat-forms'));
        $real=realpath($root);
        if($real===false&&!file_exists($root)&&!is_link($root))return rtrim($normal,'/');
        if(!$real||!is_dir($real)||!self::outsidePublicRoots($real,true))throw new \RuntimeException(esc_html__('Private file storage must be outside the web roots.', 'leenkat-forms'));
        return wp_normalize_path($real);
    }
    public static function encrypted(string $key): bool {return (bool)preg_match('/^p2-[a-f0-9]{64}\.lkf$/D',$key);}
    public static function validKey(string $key): bool {return self::encrypted($key)||(bool)preg_match('/^(?:p1-[a-f0-9]{64}\.[a-z0-9]{1,10}|t1-[a-f0-9]{64}\.csv)$/D',$key);}
    public static function path(string $key): ?string {
        if(!self::validKey($key))return null;
        if(self::encrypted($key))return EncryptedStorage::open($key);
        try{$root=self::legacyRoot();}catch(\Throwable){return null;}
        $path=$root.DIRECTORY_SEPARATOR.$key;$real=realpath($path);
        return $real&&self::noLinks($path)&&self::inside($real,$root)&&is_file($real)&&is_readable($real)?$real:null;
    }
    public static function put(string $source,string $extension,int $size,string $hash): string {
        if(!preg_match('/^[a-z0-9]{1,10}$/D',$extension)||$size<1||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!self::noLinks($source)||!is_file($source))throw new \RuntimeException(esc_html__('The file could not be stored securely.', 'leenkat-forms'));
        if($size>EncryptedStorage::MAX_BYTES)throw new \RuntimeException(esc_html__('The file is too large.', 'leenkat-forms'));
        $key='p2-'.bin2hex(random_bytes(32)).'.lkf';
        $intent=self::root().'/'.$key.'.pending';
        // Intent markers contain only a timestamp. Keep them until the database transaction commits.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
        $marker=@fopen($intent,'xb');if(!$marker)throw new \RuntimeException(esc_html__('The file could not be stored securely.', 'leenkat-forms'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
        $marked=fwrite($marker,(string)time())!==false;fclose($marker);
        try{
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
            if(!$marked||!($in=@fopen($source,'rb')))throw new \RuntimeException(esc_html__('The file could not be stored securely.', 'leenkat-forms'));
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
            try{EncryptedStorage::write($key,$in);}finally{fclose($in);}
            $verified=self::verify($key,$size,$hash);
            if(!$verified)throw new \RuntimeException(esc_html__('The file could not be stored securely.', 'leenkat-forms'));
            EncryptedStorage::close($verified);
            return $key;
        }catch(\Throwable $error){self::remove($key);throw $error;}
    }
    public static function verify(string $key,int $size,string $hash): ?string {
        if($size<1||!preg_match('/^[a-f0-9]{64}$/D',$hash))return null;
        if(self::encrypted($key))return EncryptedStorage::open($key,$size,$hash);
        $path=self::path($key);return $path&&filesize($path)===$size&&hash_equals($hash,(string)@hash_file('sha256',$path))?$path:null;
    }
    public static function matches(string $key,int $size,string $hash): bool {
        $path=self::verify($key,$size,$hash);
        if(!$path)return false;
        self::close($path);
        return true;
    }
    private static function objectRoot(string $key): string {return self::encrypted($key)?EncryptedStorage::root():self::legacyRoot();}
    public static function pending(string $key): bool {
        if(!self::validKey($key))return false;
        try{$marker=self::objectRoot($key).'/'.$key.'.pending';}catch(\Throwable){return false;}
        return self::noLinks($marker)&&is_file($marker);
    }
    public static function keep(string $key): void {
        if(!self::validKey($key))return;
        $intent=self::objectRoot($key).'/'.$key.'.pending';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
        if(self::noLinks($intent)&&is_file($intent))@unlink($intent);
    }
    public static function remove(string $key): bool {
        if(!self::validKey($key))return false;
        $candidate=self::objectRoot($key).'/'.$key;
        if(!self::noLinks($candidate)||!self::noLinks($candidate.'.pending'))return false;
        $path=self::encrypted($key)?EncryptedStorage::ciphertext($key):self::path($key);
        if(!$path&&file_exists($candidate))return false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
        if($path&&!@unlink($path))return false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
        return !file_exists($candidate.'.pending')||@unlink($candidate.'.pending');
    }
    public static function temporary(string $contents): string {return EncryptedStorage::temporary($contents);}
    public static function close(string $path): void {EncryptedStorage::close($path);}
    public static function reap(): void {
        global $wpdb;$roots=[];
        foreach([static fn()=>EncryptedStorage::root(),[self::class,'legacyRoot']] as $resolve){
            try{$root=$resolve();if(is_dir($root))$roots[]=$root;}catch(\Throwable){}
        }
        foreach($roots as $root)foreach(new \DirectoryIterator($root) as $file){if(!$file->isFile()||$file->isLink()||!str_ends_with($file->getFilename(),'.pending'))continue;$key=substr($file->getFilename(),0,-8);if(!self::validKey($key)||self::objectRoot($key)!==$root||$file->getMTime()>time()-DAY_IN_SECONDS)continue;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $refs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE storage_key=%s",$key));if($wpdb->last_error){update_option('lkf_file_cleanup_attention',true,false);continue;}if($refs)self::keep($key);else if(!self::remove($key))update_option('lkf_file_cleanup_attention',true,false);
        }
    }
}
