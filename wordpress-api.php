<?php
/**
 * Работа с WordPress REST API
 */

require_once __DIR__ . '/functions.php';

/**
 * Выполняет запрос к WordPress REST API
 */
function wp_api(string $endpoint, array $body = [], string $method = 'POST', array $extra_headers = []): ?array
{
    $url = WP_API_URL . $endpoint;

    $headers = array_merge([
        'Authorization: ' . WP_AUTH_HEADER,
        'Content-Type: application/json',
    ], $extra_headers);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);

    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response_body = curl_exec($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error    = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        log_err("WordPress API cURL: $curl_error");
        return null;
    }

    if ($http_code < 200 || $http_code >= 300) {
        log_err("WordPress API ошибка HTTP $http_code для $endpoint: " . substr($response_body, 0, 300));
        return null;
    }

    $data = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_err("WordPress API: не удалось разобрать JSON ответ");
        return null;
    }

    return $data;
}

/**
 * Загружает изображение в медиатеку WordPress
 */
function wp_upload_image(string $image_data, string $filename): ?int
{
    $type     = detect_image_type($image_data);
    $fullname = $filename . '.' . $type['ext'];

    log_info("WordPress: загружаем изображение \"$fullname\" ({$type['mime']})");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => WP_API_URL . '/media',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $image_data,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . WP_AUTH_HEADER,
            'Content-Type: ' . $type['mime'],
            'Content-Disposition: attachment; filename="' . $fullname . '"',
        ],
    ]);

    $response_body = curl_exec($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error    = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        log_err("Медиа upload cURL: $curl_error");
        return null;
    }

    if ($http_code < 200 || $http_code >= 300) {
        log_err("Медиа upload HTTP $http_code: " . substr($response_body, 0, 300));
        return null;
    }

    $data     = json_decode($response_body, true);
    $media_id = (int)($data['id'] ?? 0);

    if (!$media_id) {
        log_err("Медиа upload: не удалось получить ID загруженного файла");
        return null;
    }

    log_ok("WordPress: изображение загружено, ID = $media_id");
    return $media_id;
}

/**
 * Создаёт новый пост в WordPress.
 * Все картинки (кроме обложки) вставляются прямо в тело поста через <img> теги.
 */
function wp_create_post(
    string $title,
    string $content,
    array  $category_ids   = [],
    ?int   $featured_image = null,
    string $status         = 'publish',
    array  $extra_media_ids = []  // ← дополнительные картинки для вставки в тело
): ?int {
    log_info("WordPress: создаём пост \"" . mb_substr($title, 0, 60) . "\"");

    // Форматируем текст — переносы строк → <br>
    $formatted_content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));

    // Добавляем дополнительные картинки прямо в тело поста
    if (!empty($extra_media_ids)) {
        $formatted_content .= "\n\n<!-- Изображения из ВК -->\n";
        foreach ($extra_media_ids as $media_id) {
            $media_url = wp_get_media_url($media_id);
            if ($media_url) {
                $formatted_content .= '<figure class="wp-block-image">';
                $formatted_content .= '<img src="' . esc_url($media_url) . '" />';
                $formatted_content .= '</figure>' . "\n";
            }
        }
    }

    $body = [
        'title'   => $title,
        'content' => $formatted_content,
        'status'  => $status,
    ];

    if (!empty($category_ids)) {
        $body['categories'] = $category_ids;
    }

    if ($featured_image) {
        $body['featured_media'] = $featured_image;
    }

    $data    = wp_api('/posts', $body);
    $post_id = (int)($data['id'] ?? 0);

    if (!$post_id) {
        log_err("WordPress: не удалось создать пост");
        return null;
    }

    $link = $data['link'] ?? '—';
    log_ok("WordPress: пост создан (ID=$post_id), ссылка: $link");
    return $post_id;
}

/**
 * Получает публичный URL медиафайла по его ID
 */
function wp_get_media_url(int $media_id): ?string
{
    $data = wp_api("/media/$media_id", [], 'GET');
    return $data['source_url'] ?? null;
}

/**
 * Экранирует URL для вставки в HTML
 */
function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/**
 * Привязывает медиафайлы к посту (обновляет post_parent)
 */
function wp_attach_images_to_post(int $post_id, array $media_ids): void
{
    foreach ($media_ids as $media_id) {
        $result = wp_api("/media/$media_id", ['post' => $post_id], 'POST');
        if ($result) {
            log_ok("WordPress: медиа $media_id привязано к посту $post_id");
        }
    }
}

/**
 * Проверяет подключение к WordPress REST API
 */
function wp_check_connection(): ?string
{
    $data = wp_api('/users/me', [], 'GET');
    return $data['name'] ?? null;
}