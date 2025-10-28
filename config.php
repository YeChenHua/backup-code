<?php
/*只放「設定值」和「常數」，沒發送訊息的功能*/

// Telegram Bot Token
define('TELEGRAM_BOT_TOKEN', '7862498050:AAGq2IzngOmIhV-9AoDl_n9cgSJYvh0ny_o');

// Telegram API 基礎 URL
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);

// 資料庫主機
define('DB_HOST', 'localhost');

// 資料庫名稱
define('DB_NAME', 'learning');

// 資料庫用戶名
define('DB_USER', 'root');

// 資料庫密碼
define('DB_PASS', '');

// 資料庫字符集
define('DB_CHARSET', 'utf8');

// PDO DSN 字符串
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);

// 系統時區
define('SYSTEM_TIMEZONE', 'Asia/Taipei');

// 日誌文件路径
define('LOG_FILE', __DIR__ . '/logs/bot.log');

// 是否啟用調試模式
define('DEBUG_MODE', true);

// 是否記錄用戶活動
define('LOG_USER_ACTIVITY', true);

// 是否啟用系統狀態監控
define('ENABLE_SYSTEM_MONITOR', true);

// 貼圖 ID 設定
define('STICKER_CORRECT', 'CAACAgIAAxkBAAE6BOxoqeFDX1OKwN2GrVcQFXEKvxbFfAAC_gADVp29CtoEYTAu-df_NgQ');
define('STICKER_INCORRECT', 'CAACAgIAAxkBAAE6BPRoqeF39yFoeyZpjEJEJEDG63-wiAAC8wADVp29Cmob68TH-pb-NgQ');

// animation 常數定義
//define('GIF_SUCCESS', 'CgACAgUAAxkBAAE6BChoqc5yzAABo3dBY-oM4TYUW0aGqaAAAgkaAAIzplFVmY1-0mvzHrw2BA');
//define('GIF_FAIL', 'CgACAgUAAxkBAAE6BC1oqc6tOuz_wl9dfsAMYdYMkLp8MwACChoAAjOmUVUe1KepdL7h7jYE');

// 密碼最小長度
define('MIN_PASSWORD_LENGTH', 4);

// 用戶名最小長度
define('MIN_USERNAME_LENGTH', 2);

// 用戶名最大長度
define('MAX_USERNAME_LENGTH', 20);

// Webhook 相關設定
define('WEBHOOK_SECRET_TOKEN', ''); // 建議設定一個隨機字符串作為驗證
define('WEBHOOK_MAX_CONNECTIONS', 40); // 最大並發連接數
define('ALLOWED_UPDATES', json_encode(['message', 'callback_query'])); // 允許的更新類型

// 設定時區
date_default_timezone_set('Asia/Taipei');

// A班 / B班 各自的開放時間
$UNIT_ACCESS_TIMES = [
    'A' => [
        'unit1' => strtotime('2025-09-16 00:00:00'),
        'unit2' => strtotime('2025-09-20 00:00:00'),
        'unit3' => strtotime('2025-10-11 00:00:00'),
        'unit4' => strtotime('2025-11-08 00:00:00'),
        'unit5' => strtotime('2025-11-15 00:00:00'),
        'unit6' => strtotime('2025-11-22 00:00:00'),
    ],
    'B' => [
        'unit1' => strtotime('2025-09-17 00:00:00'),
        'unit2' => strtotime('2025-09-24 00:00:00'),
        'unit3' => strtotime('2025-10-01 00:00:00'),
        'unit4' => strtotime('2025-11-05 00:00:00'),
        'unit5' => strtotime('2025-11-12 00:00:00'),
        'unit6' => strtotime('2025-11-19 00:00:00'),
    ],
];

// 創建日誌目錄（如果不存在）
if (!file_exists(dirname(LOG_FILE))) {
    mkdir(dirname(LOG_FILE), 0755, true);
}

/**
 * 檢查系統環境是否滿足運行要求
 */
function checkSystemRequirements() {
    $errors = [];
    
    // 檢查 PHP 版本
    if (version_compare(PHP_VERSION, '7.0.0', '<')) {
        $errors[] = 'PHP 版本必須 >= 7.0.0，當前版本：' . PHP_VERSION;
    }
    
    // 檢查必要的 PHP 擴展
    $required_extensions = ['pdo', 'pdo_mysql', 'curl', 'json'];
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "缺少必要的 PHP 擴展：{$ext}";
        }
    }
    
    // 檢查寫入權限
    if (!is_writable(dirname(LOG_FILE))) {
        $errors[] = '日誌目錄沒有寫入權限：' . dirname(LOG_FILE);
    }
    
    return $errors;
}

/**
 * 驗證配置設定是否正確
 */
function validateConfig() {
    $errors = [];
    
    // 檢查 Telegram Token 格式
    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', TELEGRAM_BOT_TOKEN)) {
        $errors[] = 'Telegram Bot Token 格式不正確';
    }
    
    // 檢查資料庫配置
    if (empty(DB_HOST) || empty(DB_NAME)) {
        $errors[] = '資料庫配置不完整';
    }
    
    return $errors;
}

/**
 * 設置 Telegram Webhook
 */
function setWebhook($webhook_url) {
    $api_url = TELEGRAM_API_URL . "/setWebhook";
    
    $data = [
        'url' => $webhook_url,
        'max_connections' => WEBHOOK_MAX_CONNECTIONS,
        'allowed_updates' => json_decode(ALLOWED_UPDATES, true)
    ];
    
    // 如果有設定 secret token，加入驗證
    if (!empty(WEBHOOK_SECRET_TOKEN)) {
        $data['secret_token'] = WEBHOOK_SECRET_TOKEN;
    }
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ]);
    
    $result = file_get_contents($api_url, false, $context);
    return json_decode($result, true);
}

/**
 * 刪除 Telegram Webhook
 */
function deleteWebhook() {
    $api_url = TELEGRAM_API_URL . "/deleteWebhook";
    
    $data = ['drop_pending_updates' => true];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ]);
    
    $result = file_get_contents($api_url, false, $context);
    return json_decode($result, true);
}

/**
 * 獲取 Webhook 資訊
 */
function getWebhookInfo() {
    $api_url = TELEGRAM_API_URL . "/getWebhookInfo";
    $result = file_get_contents($api_url);
    return json_decode($result, true);
}

if (DEBUG_MODE) {
    // 檢查系統需求
    $system_errors = checkSystemRequirements();
    if (!empty($system_errors)) {
        foreach ($system_errors as $error) {
            error_log("System Requirement Error: " . $error);
        }
    }
    
    // 驗證配置
    $config_errors = validateConfig();
    if (!empty($config_errors)) {
        foreach ($config_errors as $error) {
            error_log("Config Validation Error: " . $error);
        }
    }
}

// ===== Timer helpers =====
if (!function_exists('timer_start')) {
    function timer_start($telegram_id, $scope) {
        global $pdo;
        // scope 例如: "quiz_start:12" 或 "lesson_start:3"
        $stmt = $pdo->prepare("
            INSERT INTO click_log (telegram_id, scope) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP()
        ");
        $stmt->execute([$telegram_id, $scope]);
    }
}

if (!function_exists('timer_stop')) {
    function timer_stop($telegram_id, $scope, $clear_after = true) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT created_at FROM click_log WHERE telegram_id=? AND scope=? LIMIT 1");
        $stmt->execute([$telegram_id, $scope]);
        $start = $stmt->fetchColumn();
        if (!$start) return null;

        $elapsed = max(0, time() - strtotime($start));

        if ($clear_after) {
            $stmt = $pdo->prepare("DELETE FROM click_log WHERE telegram_id=? AND scope=?");
            $stmt->execute([$telegram_id, $scope]);
        }
        return $elapsed; // 秒數
    }
}

if (!function_exists('has_column')) {
    function has_column($table, $column) {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() > 0;
    }
}


?>