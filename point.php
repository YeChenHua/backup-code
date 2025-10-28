<?php
/* 點數機制相關功能模組 */

// 新增或更新用戶點數
function updateUserPoint($telegram_id, $unit, $score) {
    global $pdo;
    // 檢查是否已有紀錄
    $stmt = $pdo->prepare("SELECT id FROM point WHERE telegram_id = ? AND unit = ?");
    $stmt->execute([$telegram_id, $unit]);
    $existing = $stmt->fetch();
    if ($existing) {
        // 更新點數
        $stmt = $pdo->prepare("UPDATE point SET score = ? WHERE telegram_id = ? AND unit = ?");
        $stmt->execute([$score, $telegram_id, $unit]);
    } else {
        // 新增點數
        $stmt = $pdo->prepare("INSERT INTO point (telegram_id, unit, score) VALUES (?, ?, ?)");
        $stmt->execute([$telegram_id, $unit, $score]);
    }
}

// 取得用戶某單元點數
function getUserPoint($telegram_id, $unit) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT score FROM point WHERE telegram_id = ? AND unit = ?");
    $stmt->execute([$telegram_id, $unit]);
    $score = $stmt->fetchColumn();
    return $score !== false ? intval($score) : 0;
}

// 檢查是否解鎖進階單元 (總點數達到 70 點，即滿點 50 + Bonus 20)
function isAdvancedUnlocked($telegram_id, $unit) {
    $score = getUserPoint($telegram_id, $unit);
    return $score >= 70;
}

// 新增點數 (答對一題加 10 點)
function addPoint($telegram_id, $unit) {
    // 取得用戶當前點數
    $score = getUserPoint($telegram_id, $unit);
    // 累加 10 點
    $score += 10;
    // 使用已經寫好的函式來更新或新增點數紀錄
    updateUserPoint($telegram_id, $unit, $score);
    // 返回新的點數
    return $score;
}

// 扣點（答錯一題扣 3 點，但不低於 0 點）- 修正版本
function deductPoint($telegram_id, $unit) {
    // 取得用戶當前點數
    $score = getUserPoint($telegram_id, $unit);
    // 扣 3 點，但不低於 0
    $score = max(0, $score - 3);
    // 使用統一的更新函式
    updateUserPoint($telegram_id, $unit, $score);
    // 返回新的點數
    return $score;
}

// 累加額外 Bonus 點數 - 修正版本
function addBonusPoint($telegram_id, $unit, $bonus_score = 20) {
    // 取得用戶當前點數
    $score = getUserPoint($telegram_id, $unit);
    // 累加獎勵點數
    $score += $bonus_score;
    // 使用統一的更新函式
    updateUserPoint($telegram_id, $unit, $score);
    // 返回新的點數
    return $score;
}

// 取得所有單元點數
function getAllUnitPoints($telegram_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT unit, score FROM point WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 確保用戶在單元中有初始記錄（可選用）
function ensureUserUnitRecord($telegram_id, $unit) {
    $score = getUserPoint($telegram_id, $unit);
    if ($score === 0) {
        // 檢查是否真的沒有記錄，還是點數就是 0
        global $pdo;
        $stmt = $pdo->prepare("SELECT id FROM point WHERE telegram_id = ? AND unit = ?");
        $stmt->execute([$telegram_id, $unit]);
        if (!$stmt->fetch()) {
            // 確實沒有記錄，建立初始記錄
            updateUserPoint($telegram_id, $unit, 0);
        }
    }
}
?>