<?php
/**
 * Скрипт для проверки работы системы
 * 
 * ВАЖНО: После проверки УДАЛИТЕ этот файл с сервера!
 * Он содержит диагностику и не должен быть публично доступен.
 * 
 * Как использовать:
 * Откройте в браузере: https://chepetck.ru/vk-poster/test.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/vk-api.php';
require_once __DIR__ . '/wordpress-api.php';

header('Content-Type: text/html; charset=utf-8');

function show_result(string $label, bool $ok, string $detail = ''): void
{
    $icon  = $ok ? '✅' : '❌';
    $color = $ok ? '#155724' : '#721c24';
    $bg    = $ok ? '#d4edda' : '#f8d7da';
    echo "<div style='padding:10px;margin:6px 0;border-radius:6px;background:$bg;color:$color;font-family:monospace'>";
    echo "<strong>$icon $label</strong>";
    if ($detail) echo "<br><small>$detail</small>";
    echo "</div>";
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
<title>VK→WP Проверка</title>
<style>body{font-family:sans-serif;max-width:800px;margin:30px auto;padding:0 20px}</style>
</head><body>";
echo "<h2>🔍 Проверка системы VK → WordPress</h2>";
echo "<p style='color:#666'>Дата/время: " . date('d.m.Y H:i:s') . "</p>";

// ─── 1. Файл конфигурации ────────────────────────────────────
echo "<h3>1. Конфигурация</h3>";
show_result('config.php загружен', true);

$checks = [
    'VK_ACCESS_TOKEN'       => VK_ACCESS_TOKEN      !== 'ВСТАВЬТЕ_ТОКЕН_ЗДЕСЬ',
    'VK_APP_ID'             => VK_APP_ID            !== 'ВСТАВЬТЕ_APP_ID_ЗДЕСЬ',
    'VK_GROUP_ID'           => VK_GROUP_ID          !== 'ВСТАВЬТЕ_GROUP_ID_ЗДЕСЬ',
    'VK_CONFIRMATION_TOKEN' => VK_CONFIRMATION_TOKEN !== 'ВСТАВЬТЕ_CONFIRMATION_TOKEN_ЗДЕСЬ',
    'WP_APP_PASSWORD'       => WP_APP_PASSWORD       !== 'ВСТАВЬТЕ_APPLICATION_PASSWORD_ЗДЕСЬ',
    'WP_SITE_URL'           => !empty(WP_SITE_URL),
    'WP_USERNAME'           => !empty(WP_USERNAME),
];

foreach ($checks as $key => $ok) {
    show_result($key, $ok, $ok ? 'Заполнено ✓' : 'Нужно заполнить в config.php!');
}

// ─── 2. PHP расширения ───────────────────────────────────────
echo "<h3>2. PHP расширения</h3>";
$extensions = ['curl', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    show_result("PHP $ext", extension_loaded($ext));
}
show_result('PHP версия ' . PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);

// ─── 3. Временная папка ─────────────────────────────────────
echo "<h3>3. Временная папка</h3>";
$temp_exists   = file_exists(TEMP_DIR);
$temp_writable = $temp_exists && is_writable(TEMP_DIR);
show_result('Папка temp/ существует', $temp_exists, TEMP_DIR);
show_result('Папка temp/ доступна для записи', $temp_writable);

if (!$temp_exists) {
    if (mkdir(TEMP_DIR, 0755, true)) {
        show_result('Папка temp/ создана автоматически', true);
    } else {
        show_result('Не удалось создать папку temp/', false, 'Создайте папку temp/ вручную через FTP');
    }
}

// ─── 4. WordPress API ────────────────────────────────────────
echo "<h3>4. Подключение к WordPress</h3>";
$wp_user = wp_check_connection();
show_result(
    'WordPress REST API',
    (bool)$wp_user,
    $wp_user ? "Пользователь: $wp_user" : 'Проверьте WP_USERNAME и WP_APP_PASSWORD в config.php'
);

// ─── 5. Категории WordPress ──────────────────────────────────
echo "<h3>5. Категории WordPress</h3>";
foreach (TAGS_TO_CATEGORIES as $tag => $cat_name) {
    $cat_id = get_wp_category_id($cat_name);
    show_result(
        "#$tag → \"$cat_name\"",
        (bool)$cat_id,
        $cat_id ? "ID категории: $cat_id" : "Категория не найдена — создайте её в WordPress"
    );
}

// ─── 6. VK API ───────────────────────────────────────────────
echo "<h3>6. VK API</h3>";
if (VK_ACCESS_TOKEN !== 'ВСТАВЬТЕ_ТОКЕН_ЗДЕСЬ') {
    $group_info = vk_api('groups.getById', ['group_id' => VK_GROUP_ID]);
    $group_data = $group_info[0] ?? ($group_info['groups'][0] ?? null);
    show_result(
        'VK API подключение',
        !empty($group_data),
        $group_data
            ? "Группа: {$group_data['name']} (ID: {$group_data['id']})"
            : 'Проверьте VK_ACCESS_TOKEN и VK_GROUP_ID в config.php'
    );
} else {
    show_result('VK API', false, 'Заполните VK_ACCESS_TOKEN в config.php');
}

// ─── 7. Итог ─────────────────────────────────────────────────
echo "<h3>7. Webhook URL</h3>";
$webhook_url = 'https://' . $_SERVER['HTTP_HOST'] . str_replace('test.php', 'webhook.php', $_SERVER['REQUEST_URI']);
echo "<div style='padding:12px;background:#fff3cd;border-radius:6px;font-family:monospace'>";
echo "📌 Вставьте этот URL в настройки Callback API ВКонтакте:<br>";
echo "<strong>$webhook_url</strong>";
echo "</div>";

echo "<br><div style='padding:12px;background:#cce5ff;border-radius:6px'>";
echo "⚠️ <strong>После проверки удалите файл test.php с сервера!</strong>";
echo "</div>";

echo "</body></html>";
