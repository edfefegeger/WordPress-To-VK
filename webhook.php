<?php
/**
 * VK to WordPress Auto Poster — точка входа для Callback API ВКонтакте
 * https://chepetck.ru/vk-poster/webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/vk-api.php';
require_once __DIR__ . '/wordpress-api.php';

// Буферизуем весь вывод
ob_start();

// ── 1. Только POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── 2. Читаем тело запроса ───────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (empty($data) || !isset($data['type'])) {
    ob_end_clean();
    http_response_code(400);
    exit('Bad Request');
}

$event_type = $data['type'];

// ── 3. Подтверждение адреса ──────────────────────────────────
if ($event_type === 'confirmation') {
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: text/plain');
    echo VK_CONFIRMATION_TOKEN;
    exit;
}

// ── 4. Проверяем секретный ключ ──────────────────────────────
if (VK_SECRET_KEY !== '' && ($data['secret'] ?? '') !== VK_SECRET_KEY) {
    ob_end_clean();
    http_response_code(403);
    exit('Forbidden');
}

// ── 5. Сразу отвечаем ВК «ok» ───────────────────────────────
ob_end_clean();
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_start();
    flush();
}

// ── 6. Только новые посты ────────────────────────────────────
if ($event_type !== 'wall_post_new') {
    exit;
}

$post_obj = $data['object'] ?? [];
$post_id  = (int)($post_obj['id'] ?? 0);
$group_id = (int)($data['group_id'] ?? VK_GROUP_ID);

if (!$post_id) {
    log_err("Webhook: не удалось получить post_id из события");
    exit;
}

// ── 7. ЗАЩИТА ОТ ДУБЛЕЙ ─────────────────────────────────────
// Сохраняем обработанные post_id в файл — если уже обработан, пропускаем
$processed_file = __DIR__ . '/temp/processed_posts.txt';
$processed_ids  = [];

if (file_exists($processed_file)) {
    $processed_ids = array_filter(explode("\n", file_get_contents($processed_file)));
}

if (in_array((string)$post_id, $processed_ids)) {
    log_info("Webhook: пост #$post_id уже обработан, пропускаем дубль");
    exit;
}

// Сохраняем ID как обработанный
file_put_contents($processed_file, $post_id . "\n", FILE_APPEND | LOCK_EX);

log_info("Webhook: обрабатываем новый пост #$post_id из группы $group_id");

// ── 8. Получаем полный пост из VK API ───────────────────────
$post = vk_get_post($group_id, $post_id);

if (!$post || !vk_post_is_valid($post)) {
    exit;
}

// ── 9. Скачиваем и загружаем ВСЕ изображения ────────────────
$image_urls     = vk_extract_images($post);
$featured_id    = null;   // обложка (первая картинка)
$attachment_ids = [];     // остальные картинки

log_info("Webhook: найдено изображений: " . count($image_urls));

foreach ($image_urls as $idx => $url) {
    log_info("Webhook: скачиваем изображение #" . ($idx + 1) . " из " . count($image_urls));

    $image_data = download_file($url);
    if (!$image_data) {
        log_warn("Пропускаем изображение #" . ($idx + 1) . " — не удалось скачать");
        continue;
    }

    $media_id = wp_upload_image($image_data, "vk-{$group_id}-{$post_id}-{$idx}");
    if (!$media_id) {
        log_warn("Пропускаем изображение #" . ($idx + 1) . " — не удалось загрузить в WP");
        continue;
    }

    if ($idx === 0) {
        $featured_id = $media_id;  // первая → обложка
        log_info("Изображение #1 установлено как обложка (ID: $media_id)");
    } else {
        $attachment_ids[] = $media_id;  // остальные → вложения
        log_info("Изображение #" . ($idx + 1) . " добавлено как вложение (ID: $media_id)");
    }
}

// ── 10. Категории по хештегам ────────────────────────────────
$text         = $post['text'] ?? '';
$category_ids = hashtags_to_category_ids($text);

// ── 11. Создаём пост в WordPress ─────────────────────────────
$wp_id = wp_create_post(
    make_post_title($text),
    $text,
    $category_ids,
    $featured_id,
    WP_POST_STATUS,
    $attachment_ids  // ← все остальные картинки вставятся в тело поста
);

if (!$wp_id) {
    log_err("Webhook: пост VK #$post_id НЕ создан в WordPress");
    exit;
}

// ── 12. Привязываем остальные изображения к посту ────────────
if (!empty($attachment_ids)) {
    log_info("Привязываем " . count($attachment_ids) . " доп. изображений к посту $wp_id");
    wp_attach_images_to_post($wp_id, $attachment_ids);
}

cleanup_temp(3600);

log_ok("Webhook: VK пост #$post_id успешно опубликован в WordPress (ID=$wp_id)");
log_ok("Итого: обложка=" . ($featured_id ?? 'нет') . ", вложений=" . count($attachment_ids));