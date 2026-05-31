<?php
/**
 * Работа с VK API
 */

require_once __DIR__ . '/functions.php';

/**
 * Выполняет запрос к VK API
 *
 * @param string $method  Метод API, например 'wall.getById'
 * @param array  $params  Параметры
 * @return array|null     Поле 'response' из ответа API или null при ошибке
 */
function vk_api(string $method, array $params = []): ?array
{
    $params['access_token'] = VK_ACCESS_TOKEN;
    $params['v']            = VK_API_VERSION;

    $url    = 'https://api.vk.com/method/' . $method;
    $result = http_request($url, [], $params);

    if ($result['code'] !== 200) {
        log_err("VK API HTTP ошибка {$result['code']} при вызове $method");
        return null;
    }

    $data = json_decode($result['body'], true);

    if (isset($data['error'])) {
        $code = $data['error']['error_code']    ?? '?';
        $msg  = $data['error']['error_msg']     ?? 'unknown';
        log_err("VK API ошибка [$code]: $msg (метод: $method)");
        return null;
    }

    return $data['response'] ?? null;
}

/**
 * Получает полную информацию о посте со стены группы
 *
 * @param int $group_id  Числовой ID группы (без минуса)
 * @param int $post_id   ID поста
 * @return array|null
 */
function vk_get_post(int $group_id, int $post_id): ?array
{
    log_info("VK: получаем пост $post_id из группы $group_id");

    $response = vk_api('wall.getById', [
        'posts'          => "-{$group_id}_{$post_id}",
        'extended'       => 0,
    ]);

    if (empty($response)) {
        log_err("VK: пост $post_id не найден");
        return null;
    }

    // Ответ может быть массивом постов или объектом {items:[...]}
    $post = isset($response['items']) ? ($response['items'][0] ?? null) : ($response[0] ?? null);

    if (!$post) {
        log_err("VK: не удалось разобрать ответ для поста $post_id");
        return null;
    }

    log_info("VK: пост $post_id получен успешно");
    return $post;
}

/**
 * Извлекает URL-ы всех изображений из вложений поста.
 * Для каждой фотографии берём максимальный по ширине размер.
 *
 * @return string[]
 */
function vk_extract_images(array $post): array
{
    $urls        = [];
    $attachments = $post['attachments'] ?? [];

    foreach ($attachments as $attach) {
        $type = $attach['type'] ?? '';

        if ($type === 'photo') {
            $url = vk_best_photo_url($attach['photo'] ?? []);
            if ($url) {
                $urls[] = $url;
                log_info("VK: найдено фото: " . substr($url, 0, 60) . '…');
            }
        }
        // Можно добавить обработку 'doc' (gif-документы) или 'album' при необходимости
    }

    log_info("VK: всего изображений в посте: " . count($urls));
    return $urls;
}

/**
 * Возвращает URL наилучшего (максимального по ширине) размера фотографии
 */
function vk_best_photo_url(array $photo): ?string
{
    $sizes = $photo['sizes'] ?? [];

    if (empty($sizes)) {
        return null;
    }

    usort($sizes, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));

    return $sizes[0]['url'] ?? null;
}

/**
 * Проверяет, является ли пост валидным для импорта:
 * - не удалён
 * - содержит текст или хотя бы одно вложение-фото
 */
function vk_post_is_valid(array $post): bool
{
    if (!empty($post['is_deleted'])) {
        log_warn("VK: пост помечен как удалённый, пропускаем");
        return false;
    }

    $has_text   = !empty(trim($post['text'] ?? ''));
    $has_photos = false;

    foreach ($post['attachments'] ?? [] as $a) {
        if ($a['type'] === 'photo') { $has_photos = true; break; }
    }

    if (!$has_text && !$has_photos) {
        log_warn("VK: пост не содержит текста и фотографий, пропускаем");
        return false;
    }

    return true;
}
