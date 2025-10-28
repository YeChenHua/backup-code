<?php
/*用戶管理模組*/

/*根據 Telegram ID 取得用戶資料*/
function getUserByTelegramId($chat_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*檢查用戶是否已登入*/
function isUserLoggedIn($chat_id) {
    global $pdo;
    $q = $pdo->prepare("SELECT 1 FROM users WHERE telegram_id = :tid LIMIT 1");
    $q->execute([':tid' => (string)$chat_id]);
    return (bool)$q->fetchColumn();
}

/*取得當前用戶的用戶名稱*/
function getCurrentUsername($chat_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT username FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    return $stmt->fetchColumn();
}

/* 用戶級鎖：避免同一用戶同流程重入（learn/quiz/answer/points） */
function acquire_user_lock(PDO $pdo, $chat_id, string $scope, int $timeoutSec = 2): bool {
    $key = "lock:{$scope}:{$chat_id}";
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, ?)");
    $stmt->execute([$key, $timeoutSec]);
    return (int)$stmt->fetchColumn() === 1;
}
function release_user_lock(PDO $pdo, $chat_id, string $scope): void {
    $key = "lock:{$scope}:{$chat_id}";
    $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
    $stmt->execute([$key]);
}

/* 小交易重試（處理死鎖/鎖等待超時） */
function with_txn_retry(PDO $pdo, callable $fn, int $maxRetry = 2) {
    $attempt = 0;
    while (true) {
        try {
            $pdo->beginTransaction();
            $res = $fn($pdo);
            $pdo->commit();
            return $res;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = (int)($e->errorInfo[1] ?? 0);
            if (($code === 1213 || $code === 1205) && $attempt < $maxRetry) {
                usleep(100000 * ($attempt + 1)); // 0.1s、0.2s
                $attempt++;
                continue;
            }
            throw $e;
        }
    }
}

?>