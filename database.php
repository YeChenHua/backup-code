<?php
/* 資料庫連線、操作檔和使用者狀態管理*/

require_once 'config.php';

$pdo = null;

/*建立資料庫連線。*/
function connectDatabase() {
    global $pdo;
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("[DB] Connection failed: " . $e->getMessage());
        if (DEBUG_MODE) {
            die("Database connection failed: " . $e->getMessage());
        } else {
            die("Database connection failed. Please check system configuration.");
        }
    }
}

/*執行 SQL 查詢並返回所有結果。*/
function executeQuery($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("[DB] Query failed: " . $e->getMessage() . " SQL: " . $sql);
        return false;
    }
}

/*取得使用者當前的狀態。*/
function getUserState($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT state FROM users WHERE telegram_id = :telegram_id");
    $stmt->execute([':telegram_id' => $telegram_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['state']) {
        return json_decode($result['state'], true);
    }
    return null;
}

/*更新使用者狀態。*/
function updateUserState($telegram_id, $state) {
    global $pdo;
    $json_data = json_encode($state);
    $stmt = $pdo->prepare("UPDATE users SET state = :state WHERE telegram_id = :telegram_id");
    return $stmt->execute([':state' => $json_data, ':telegram_id' => $telegram_id]);
}

/*清除使用者狀態。*/
function clearUserState($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET state = NULL WHERE telegram_id = :telegram_id");
    return $stmt->execute([':telegram_id' => $telegram_id]);
}

// 程式啟動時自動建立資料庫連線
try {
    connectDatabase();
} catch (Exception $e) {
    error_log("[DB] Initialization failed: " . $e->getMessage());
}
?>