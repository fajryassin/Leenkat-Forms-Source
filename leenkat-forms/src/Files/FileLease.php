<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

/** OS locks protect attachments even while a stalled delivery is being reconciled. */
final class FileLease {
    public static function acquire(int $entryId,bool $exclusive=false) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
        if($entryId<1)throw new \RuntimeException(esc_html__('Entry files are unavailable.', 'leenkat-forms'));
        $root=PrivateStorage::root();$path=$root.'/entry-'.hash_hmac('sha256',(string)$entryId,EncryptedStorage::identity()).'.lock';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The local exclusive lease requires a native stream handle and flock; remote WP_Filesystem transports do not provide advisory locking.
        if(!PrivateStorage::noLinks($path))throw new \RuntimeException(esc_html__('Entry files are unavailable.', 'leenkat-forms'));$handle=@fopen($path,'c');if(!$handle)throw new \RuntimeException(esc_html__('Entry files are unavailable.', 'leenkat-forms'));@chmod($path,0600);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
        if(!flock($handle,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)){fclose($handle);throw new \RuntimeException(esc_html__('Entry files are in use. Retry after delivery or download finishes.', 'leenkat-forms'));}return $handle;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
    public static function release($handle): void {if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);}}
}
