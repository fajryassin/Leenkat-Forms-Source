<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

/** Authenticated, chunked objects in uploads. Only ciphertext is written there. */
final class EncryptedStorage {
    public const KEY_OPTION = 'leenkat_forms_storage_key_v2';
    public const MAX_BYTES = 52428800;
    private const CHUNK = 65536;
    private const MAGIC = "LKF2";
    /** @var array<string,resource> PHP closes/unlinks these on request shutdown. */
    private static array $temporary = [];

    private static function fail(): never {
        throw new \RuntimeException(esc_html__('Encrypted private storage is unavailable.', 'leenkat-forms'));
    }

    private static function key(bool $create = false): string {
        $value = get_option(self::KEY_OPTION, null);
        if ($value === null && $create) {
            add_option(self::KEY_OPTION, bin2hex(random_bytes(32)), '', false);
            $value = get_option(self::KEY_OPTION, null);
        }
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { self::fail(); }
        return hex2bin($value);
    }

    public static function identity(bool $create = false): string {
        return hash_hmac('sha256', 'leenkat-forms-site-identity-v2', self::key($create));
    }

    public static function root(bool $create = false): string {
        $uploads = wp_upload_dir(null, false);
        $base = $uploads['basedir'] ?? null;
        if (!empty($uploads['error']) || !is_string($base) || !preg_match('~^(?:[A-Za-z]:/|/)~',wp_normalize_path($base)) ||
            preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~',wp_normalize_path($base)) || !PrivateStorage::noLinks($base)) { self::fail(); }
        $root = rtrim($base, '/\\') . '/leenkat-forms-private-' . substr(self::identity($create), 0, 32);
        if (!PrivateStorage::noLinks($root)) { self::fail(); }
        if ($create && !is_dir($root) && !wp_mkdir_p($root)) { self::fail(); }
        if (file_exists($root)) {
            $canonical = realpath($root);
            $parent = realpath($base);
            if (!$canonical || !$parent || !is_dir($canonical) || !PrivateStorage::noLinks($root) ||
                !PrivateStorage::inside($canonical, $parent) || PrivateStorage::inside($parent, $canonical)) { self::fail(); }
            return wp_normalize_path($canonical);
        }
        return wp_normalize_path($root);
    }

    public static function available(): bool {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) { return false; }
        try { $stream = self::privateTemp(); }
        catch (\Throwable) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
        fclose($stream);
        return true;
    }

    /** Never put decrypted data in WordPress uploads or a known document root. */
    private static function privateTemp() {
        $tmp = realpath(sys_get_temp_dir());
        if (!$tmp || !PrivateStorage::outsidePublicRoots($tmp)) { self::fail(); }
        $stream = tmpfile();
        if (!$stream) { self::fail(); }
        $path = stream_get_meta_data($stream)['uri'];
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
        if (!PrivateStorage::outsidePublicRoots($path) || !PrivateStorage::noLinks($path) || !@chmod($path, 0600)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            fclose($stream);
            self::fail();
        }
        return $stream;
    }

    private static function retain($stream): string {
        $path = stream_get_meta_data($stream)['uri'];
        self::$temporary[$path] = $stream;
        return $path;
    }

    public static function close(string $path): void {
        if (isset(self::$temporary[$path])) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            fclose(self::$temporary[$path]);
            unset(self::$temporary[$path]);
        }
    }

    public static function temporary(string $contents): string {
        $stream = self::privateTemp();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
        if (fwrite($stream, $contents) !== strlen($contents) || !fflush($stream)) { fclose($stream); self::fail(); }
        return self::retain($stream);
    }

    private static function aad(string $name): string { return 'leenkat-forms-object-v2|' . $name; }

    /** Atomic replacement is allowed only for an existing encrypted migration journal. */
    public static function write(string $name, $in, bool $replace = false): void {
        if (!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push') || !self::validName($name) || !is_resource($in)) { self::fail(); }
        $root = self::root(true);
        $dest = $root . '/' . $name;
        if (!PrivateStorage::noLinks($dest) || (file_exists($dest) && (!$replace || !is_file($dest)))) { self::fail(); }
        $tmp = $root . '/write-' . bin2hex(random_bytes(32)) . '.lkf';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
        $out = @fopen($tmp, 'xb');
        if (!$out) { self::fail(); }
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            @chmod($tmp, 0600);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key());
            $prefix = self::MAGIC . $header;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            if (fwrite($out, $prefix) !== strlen($prefix)) { self::fail(); }
            $size = 0;
            while (true) {
                $plain = stream_get_contents($in, self::CHUNK);
                if ($plain === false || ($plain === '' && !feof($in))) { self::fail(); }
                $size += strlen($plain);
                if ($size > self::MAX_BYTES) { self::fail(); }
                $last = strlen($plain) < self::CHUNK;
                $packet = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, self::aad($name), $last
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
                if (fwrite($out, $packet) !== strlen($packet)) { self::fail(); }
                if ($last) { break; }
            }
            if (!fflush($out) || !fsync($out)) { self::fail(); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            fclose($out);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            if (!PrivateStorage::noLinks($dest) || !@rename($tmp, $dest)) { self::fail(); }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            if (is_resource($out)) { fclose($out); }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }

    public static function validName(string $name): bool {
        return (bool)preg_match('/^(?:p2-[a-f0-9]{64}|migration-[a-f0-9]{32})\.lkf$/D', $name);
    }

    public static function ciphertext(string $name): ?string {
        if (!self::validName($name)) { return null; }
        try { $root = self::root(); } catch (\Throwable) { return null; }
        $path = $root . '/' . $name;
        $real = realpath($path);
        return $real && PrivateStorage::inside($real, $root) && PrivateStorage::noLinks($path) && is_file($real) ? $real : null;
    }

    /** Verify the complete stream before exposing even one plaintext byte to callers. */
    public static function open(string $name, ?int $bytes = null, ?string $sha = null): ?string {
        $path = self::ciphertext($name);
        if (!$path) { return null; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
        $in = @fopen($path, 'rb');
        if (!$in) { return null; }
        $out = null;
        try {
            $header = stream_get_contents($in, 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (!is_string($header) || strlen($header) !== 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES || !str_starts_with($header, self::MAGIC)) { return null; }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 4), self::key());
            $out = self::privateTemp();
            $size = 0;
            $hash = hash_init('sha256');
            $frame = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            while (true) {
                $packet = stream_get_contents($in, $frame);
                if (!is_string($packet) || $packet === '') { return null; }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $packet, self::aad($name));
                if ($result === false) { return null; }
                [$plain, $tag] = $result;
                $last = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if ($last ? (strlen($packet) >= $frame || fgetc($in) !== false || !feof($in)) :
                    ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || strlen($packet) !== $frame)) { return null; }
                $size += strlen($plain);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
                if ($size > self::MAX_BYTES || ($bytes !== null && $size > $bytes) || fwrite($out, $plain) !== strlen($plain)) { return null; }
                hash_update($hash, $plain);
                if ($last) { break; }
            }
            if (($bytes !== null && $size !== $bytes) || ($sha !== null && !hash_equals($sha, hash_final($hash))) || !fflush($out)) { return null; }
            $temp = self::retain($out);
            $out = null;
            return $temp;
        } catch (\Throwable) { return null; }
        finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            fclose($in);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Bounded authenticated streams, owner-only temporary files, and atomic same-directory commits require local filesystem handles; WP_Filesystem cannot supply those guarantees.
            if (is_resource($out)) { fclose($out); }
        }
    }
}
