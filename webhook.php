<?php
/**
 * VK to WordPress Auto Poster — точка входа для Callback API ВКонтакте
 * https://chepetck.ru/vk-poster/webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/vk-api.php';
require_once __DIR__ . '/wordpress-api.php';

// Буферизуем весь вывод — чтобы log_info() не мешал ответу ВК
ob_start();

// ── 1. Только POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── 2. Читаем тело запроса ──────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (empty($data) || !isset($data['type'])) {
    ob_end_clean();
    http_response_code(400);
    exit('Bad Request');
}

$event_type = $data['type'];

// ── 3. Подтверждение адреса (confirmation) ──────────────────
// ВК присылает этот запрос один раз при настройке Callback API.
// Нужно вернуть ТОЛЬКО строку токена — ничего лишнего!
if ($event_type === 'confirmation') {
    ob_end_clean(); // выбрасываем всё что накопилось в буфере
    http_response_code(200);
    header('Content-Type: text/plain');
    echo VK_CONFIRMATION_TOKEN;
    exit;
}

// ── 4. Проверяем секретный ключ ─────────────────────────────
if (VK_SECRET_KEY !== '' && ($data['secret'] ?? '') !== VK_SECRET_KEY) {
    ob_end_clean();
    http_response_code(403);
    exit('Forbidden');
}

// ── 5. Сразу отвечаем ВК «ok» ──────────────────────────────
ob_end_clean();
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';

// Принудительно отправляем ответ клиенту
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_start();
    flush();
}

// ── 6. Логируем событие (уже после ответа ВК) ───────────────
log_info("Webhook: получено событие «{$event_type}»");

// ── 7. Обрабатываем только новые посты ──────────────────────
if ($event_type !== 'wall_post_new') {
    log_info("Webhook: событие «{$event_type}» пропускаем");
    exit;
}

$post_obj = $data['object'] ?? [];
$post_id  = (int)($post_obj['id'] ?? 0);
$group_id = (int)($data['group_id'] ?? VK_GROUP_ID);

if (!$post_id) {
    log_err("Webhook: не удалось получить post_id из события");
    exit;
}

log_info("Webhook: обрабатываем новый пост #$post_id из группы $group_id");

// ── 8. Получаем полный пост из VK API ───────────────────────
$post = vk_get_post($group_id, $post_id);

if (!$post || !vk_post_is_valid($post)) {
    exit;
}

// ── 9. Скачиваем и загружаем изображения ────────────────────
$image_urls     = vk_extract_images($post);
$featured_id    = null;
$attachment_ids = [];

foreach ($image_urls as $idx => $url) {
    $image_data = download_file($url);
    if (!$image_data) {
        log_warn("Пропускаем изображение #" . ($idx + 1));
        continue;
    }

    $media_id = wp_upload_image($image_data, "vk-{$group_id}-{$post_id}-{$idx}");
    if (!$media_id) continue;

    if ($idx === 0) {
        $featured_id = $media_id;
    } else {
        $attachment_ids[] = $media_id;
    }
}

// ── 10. Определяем категории по хештегам ────────────────────
$text         = $post['text'] ?? '';
$category_ids = hashtags_to_category_ids($text);

// ── 11. Создаём пост в WordPress ────────────────────────────
$wp_id = wp_create_post(
    make_post_title($text),
    $text,
    $category_ids,
    $featured_id,
    WP_POST_STATUS
);

if (!$wp_id) {
    log_err("Webhook: пост VK #$post_id НЕ создан в WordPress");
    exit;
}

// ── 12. Привязываем остальные изображения ───────────────────
if (!empty($attachment_ids)) {
    wp_attach_images_to_post($wp_id, $attachment_ids);
}

cleanup_temp(3600);

log_ok("Webhook: VK пост #$post_id успешно опубликован в WordPress (ID=$wp_id)");