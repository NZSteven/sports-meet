<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

const PHOTO_MEDIA_META = '_sports_meet_photo_id';
const PHOTO_MEDIA_EVENT = 'sports_meet_sync_photo_media';

function photo_media_attachment(int $id): int {
    global $wpdb;
    return (int)q("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='attachment' AND m.meta_key=? AND m.meta_value=? LIMIT 1", [PHOTO_MEDIA_META,$id])->fetchColumn();
}

/** Register the existing gallery image as a Media Library item, without copying its file. */
function photo_sync_media(int $id): void {
    q('START TRANSACTION');
    try {
        $photo = q('SELECT id,owner,title,status,width,height,created FROM photos WHERE id=? FOR UPDATE', [$id])->fetch();
        if ($photo && $photo['status'] === 'published' && !photo_media_attachment($id)) {
            $attachment = wp_insert_attachment([
                'post_title' => $photo['title'] ?: '运动会照片 '.$id,
                'post_mime_type' => 'image/jpeg',
                'post_author' => (int)$photo['owner'],
                'post_status' => 'inherit',
                'post_date' => $photo['created'],
                'guid' => photo_url($id),
            ], false, 0, true, false);
            if (!$attachment || is_wp_error($attachment) || !add_post_meta($attachment, PHOTO_MEDIA_META, $id, true)) {
                throw new \RuntimeException('无法登记媒体库照片。');
            }
            wp_update_attachment_metadata($attachment, [
                'width' => (int)$photo['width'],
                'height' => (int)$photo['height'],
                'sizes' => [],
            ]);
        }
        q('COMMIT');
    } catch (\Throwable $error) {
        q('ROLLBACK');
        throw $error;
    }
}

function photo_sync_media_safely(int $id): void {
    try {
        photo_sync_media($id);
    } catch (\Throwable $error) {
        error_log('Sports Meet photo media: '.$error->getMessage());
        delete_option('sports_meet_photo_media_done');
        photo_schedule_media();
    }
}

function photo_schedule_media(): void {
    if (!get_option('sports_meet_photo_media_done') && !wp_next_scheduled(PHOTO_MEDIA_EVENT)) {
        wp_schedule_single_event(time()+60, PHOTO_MEDIA_EVENT);
    }
}

function photo_sync_media_batch(): void {
    global $wpdb;
    try {
        $ids = q("SELECT g.id FROM photos g WHERE g.status='published' AND NOT EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id
            WHERE m.meta_key=? AND m.meta_value=CAST(g.id AS CHAR) AND p.post_type='attachment'
        ) ORDER BY g.id LIMIT 12", [PHOTO_MEDIA_META])->fetchAll(7);
        foreach ($ids as $id) photo_sync_media((int)$id);
        if (count($ids)<12) update_option('sports_meet_photo_media_done', 1, false);
    } catch (\Throwable $error) {
        error_log('Sports Meet photo media sync: '.$error->getMessage());
    }
    photo_schedule_media();
}

add_action('init', __NAMESPACE__.'\\photo_schedule_media', 10, 0);
add_action(PHOTO_MEDIA_EVENT, __NAMESPACE__.'\\photo_sync_media_batch');

add_filter('wp_get_attachment_url', function($url, int $attachment) {
    $id = (int)get_post_meta($attachment, PHOTO_MEDIA_META, true);
    return $id ? photo_url($id) : $url;
}, 10, 2);

add_filter('image_downsize', function($result, int $attachment, $size) {
    $id = (int)get_post_meta($attachment, PHOTO_MEDIA_META, true);
    if (!$id) return $result;
    $metadata = wp_get_attachment_metadata($attachment);
    [$width,$height] = image_constrain_size_for_editor((int)($metadata['width']??0), (int)($metadata['height']??0), $size);
    return [photo_url($id),$width,$height,false];
}, 10, 3);
