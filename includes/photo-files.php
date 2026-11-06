<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

const PHOTO_FILE_PREFIX = 'sm-file:';
const PHOTO_MIGRATION_HOOK = 'sports_meet_migrate_photo_files';

function photo_file_path(string $reference): string {
    if (!preg_match('/\Asm-file:([a-f0-9]{32}):[a-f0-9]{64}\z/', $reference, $matches)) {
        throw new \RuntimeException('照片文件记录无效。');
    }
    $uploads = wp_get_upload_dir();
    return trailingslashit($uploads['basedir']).'sports-meet-photos/'.$matches[1].'.smphoto';
}

/** Encrypt files in uploads so direct requests cannot expose unaudited photos. */
function photo_write_file(string $jpeg): string {
    if (!function_exists('openssl_encrypt')) {
        throw new \RuntimeException('主机需要启用 PHP OpenSSL 才能保存照片。');
    }
    $key = random_bytes(32);
    $reference = PHOTO_FILE_PREFIX.bin2hex(random_bytes(16)).':'.bin2hex($key);
    $path = photo_file_path($reference);
    if (!wp_mkdir_p(dirname($path))) {
        throw new \RuntimeException('无法创建照片目录，请检查主机目录权限。');
    }
    $nonce = random_bytes(12);
    $ciphertext = openssl_encrypt($jpeg, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new \RuntimeException('无法保护照片文件。');
    }
    $payload = $nonce.$tag.$ciphertext;
    $handle = @fopen($path, 'xb');
    if (!$handle) {
        throw new \RuntimeException('无法写入照片文件，请检查主机空间和目录权限。');
    }
    $written = fwrite($handle, $payload);
    $closed = fclose($handle);
    if ($written !== strlen($payload) || !$closed || @hash_file('sha256', $path) !== hash('sha256', $payload)) {
        @unlink($path);
        throw new \RuntimeException('照片文件写入不完整。');
    }
    return $reference;
}

function photo_read_bytes(string $image, int $expectedBytes): string {
    if (!str_starts_with($image, PHOTO_FILE_PREFIX)) {
        return $image;
    }
    $path = photo_file_path($image);
    $payload = @file_get_contents($path, false, null, 0, $expectedBytes + 29);
    if ($payload === false || strlen($payload) !== $expectedBytes + 28) {
        throw new \RuntimeException('照片文件缺失或不完整。');
    }
    $key = hex2bin(substr($image, -64));
    $jpeg = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    if ($jpeg === false) {
        throw new \RuntimeException('照片文件验证失败。');
    }
    return $jpeg;
}

function photo_remove_file(string $reference): void {
    if (!str_starts_with($reference, PHOTO_FILE_PREFIX)) {
        return;
    }
    $path = photo_file_path($reference);
    if (is_file($path) && !@unlink($path)) {
        error_log('Sports Meet: could not remove a photo file.');
    }
}

function photo_migrate_one(int $id): void {
    $reference = null;
    $committing = false;
    q('START TRANSACTION');
    try {
        $row = q('SELECT image,bytes FROM photos WHERE id=? FOR UPDATE', [$id])->fetch();
        if ($row && !str_starts_with($row['image'], PHOTO_FILE_PREFIX)) {
            $reference = photo_write_file($row['image']);
            if (photo_read_bytes($reference, (int)$row['bytes']) !== $row['image']) {
                throw new \RuntimeException('照片迁移验证失败。');
            }
            q('UPDATE photos SET image=? WHERE id=?', [$reference,$id]);
        }
        $committing = true;
        q('COMMIT');
    } catch (\Throwable $error) {
        try {
            q('ROLLBACK');
        } finally {
            if ($reference !== null && !$committing) photo_remove_file($reference);
        }
        throw $error;
    }
}

function photo_schedule_migration(int $delay = 30): void {
    if (!get_option('sports_meet_photo_files_done') && !wp_next_scheduled(PHOTO_MIGRATION_HOOK)) {
        wp_schedule_single_event(time() + $delay, PHOTO_MIGRATION_HOOK);
    }
}

function photo_migrate_batch(): void {
    try {
        $ids = q('SELECT id FROM photos WHERE LEFT(image,8)<>? ORDER BY id LIMIT 8', [PHOTO_FILE_PREFIX])->fetchAll(7);
        foreach ($ids as $id) photo_migrate_one((int)$id);
        delete_option('sports_meet_photo_files_error');
        if (count($ids) < 8) {
            update_option('sports_meet_photo_files_done', 1, false);
            return;
        }
        photo_schedule_migration();
    } catch (\Throwable $error) {
        error_log('Sports Meet photo migration: '.$error->getMessage());
        update_option('sports_meet_photo_files_error', $error->getMessage(), false);
        photo_schedule_migration(600);
    }
}

add_action('init', __NAMESPACE__.'\\photo_schedule_migration', 10, 0);
add_action(PHOTO_MIGRATION_HOOK, __NAMESPACE__.'\\photo_migrate_batch');
