<?php
/**
 * Вспомогательные функции
 */

require_once __DIR__ . '/config.php';

/**
 * Записывает сообщение в лог файл
 */
function log_message(string $level, string $message): void
{
    if (!ENABLE_LOGGING) return;

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message" . PHP_EOL;

    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line; // тоже выводим в ответ для дебага
}

function log_info(string $msg): void  { log_message('INFO',    $msg); }
function log_ok(string $msg): void    { log_message('SUCCESS', $msg); }
function log_warn(string $msg): void  { log_message('WARNING', $msg); }
function log_err(string $msg): void   { log_message('ERROR',   $msg); }

/**
 * Выполняет HTTP запрос (GET или POST)
 *
 * @param string $url
 * @param array  $headers
 * @param array|null $post_data  null = GET, array = POST
 * @param int    $timeout
 * @return array ['body' => string, 'code' => int]
 */
function http_request(string $url, array $headers = [], ?array $post_data = null, int $timeout = 30): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($post_data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    }

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_err("cURL ошибка: $err (URL: $url)");
    }

    return ['body' => $body ?: '', 'code' => $code];
}

/**
 * Скачивает файл по URL и возвращает его содержимое (bytes)
 */
function download_file(string $url): ?string
{
    $result = http_request($url);

    if ($result['code'] !== 200 || empty($result['body'])) {
        log_err("Не удалось скачать файл: $url (HTTP {$result['code']})");
        return null;
    }

    return $result['body'];
}

/**
 * Определяет MIME-тип и расширение файла по его содержимому
 *
 * @return array ['mime' => string, 'ext' => string]
 */
function detect_image_type(string $data): array
{
    $header = substr($data, 0, 12);

    if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return ['mime' => 'image/png',  'ext' => 'png'];
    }
    if (substr($header, 0, 3) === "\xff\xd8\xff") {
        return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
    }
    if (substr($header, 0, 6) === 'GIF87a' || substr($header, 0, 6) === 'GIF89a') {
        return ['mime' => 'image/gif',  'ext' => 'gif'];
    }
    if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        return ['mime' => 'image/webp', 'ext' => 'webp'];
    }

    return ['mime' => 'image/jpeg', 'ext' => 'jpg']; // по умолчанию
}

/**
 * Извлекает все хештеги из текста
 * Пример: "Привет #новость #событие" → ['новость', 'событие']
 */
function extract_hashtags(string $text): array
{
    preg_match_all('/#([а-яёa-z0-9_]+)/iu', $text, $matches);
    return array_map('mb_strtolower', $matches[1] ?? []);
}

/**
 * Преобразует хештеги из текста в ID категорий WordPress
 *
 * @return int[]
 */
function hashtags_to_category_ids(string $text): array
{
    $hashtags   = extract_hashtags($text);
    $mapping    = TAGS_TO_CATEGORIES;
    $ids        = [];

    foreach ($hashtags as $tag) {
        if (isset($mapping[$tag])) {
            $cat_name = $mapping[$tag];
            $cat_id   = get_wp_category_id($cat_name);

            if ($cat_id) {
                $ids[] = $cat_id;
                log_info("Хештег #$tag → категория \"$cat_name\" (ID: $cat_id)");
            } else {
                log_warn("Категория \"$cat_name\" не найдена в WordPress");
            }
        }
    }

    return array_unique($ids);
}

/**
 * Кэш категорий (чтобы не делать лишние запросы)
 */
$_category_cache = [];

/**
 * Возвращает ID категории WordPress по её названию
 */
function get_wp_category_id(string $name): ?int
{
    global $_category_cache;

    if (isset($_category_cache[$name])) {
        return $_category_cache[$name];
    }

    $url    = WP_API_URL . '/categories?' . http_build_query(['search' => $name, 'per_page' => 5]);
    $result = http_request($url, ['Authorization: ' . WP_AUTH_HEADER]);

    if ($result['code'] !== 200) {
        log_err("Ошибка получения категорий: HTTP {$result['code']}");
        return null;
    }

    $cats = json_decode($result['body'], true);

    foreach ((array)$cats as $cat) {
        if (mb_strtolower($cat['name']) === mb_strtolower($name)) {
            $_category_cache[$name] = (int)$cat['id'];
            return (int)$cat['id'];
        }
    }

    log_warn("Категория не найдена: \"$name\"");
    return null;
}

/**
 * Формирует заголовок поста из текста:
 * берет первую непустую строку, обрезает до $max символов
 */
function make_post_title(string $text, int $max = 100): string
{
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            return mb_substr($line, 0, $max);
        }
    }
    return mb_substr(trim($text), 0, $max);
}

/**
 * Удаляет временные файлы из TEMP_DIR старше $seconds секунд
 */
function cleanup_temp(int $seconds = 3600): void
{
    $now = time();
    foreach (glob(TEMP_DIR . '*') as $file) {
        if (is_file($file) && ($now - filemtime($file)) > $seconds) {
            @unlink($file);
        }
    }
}
