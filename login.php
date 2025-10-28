<?php
// login.php — 單步：輸入學號 → 綁定 + 登入（回覆學號與姓名）

/* ===== 依專案相容的小工具 ===== */
function _setState($id, array $st){ if(function_exists('setUserState')){ setUserState($id,$st); } elseif(function_exists('updateUserState')){ updateUserState($id,$st); } }
function _getState($id){ return function_exists('getUserState') ? (getUserState($id) ?: []) : []; }
function _clearState($id){ if(function_exists('clearUserState')){ clearUserState($id); } else { _setState($id,[]); } }
function _isLoggedIn($id){
    if(function_exists('isUserLoggedIn')) return isUserLoggedIn($id);
    global $pdo; $q=$pdo->prepare("SELECT 1 FROM users WHERE telegram_id=:tid LIMIT 1");
    $q->execute([':tid'=>(string)$id]); return (bool)$q->fetchColumn();
}

/* ===== /login：提示輸入學號 ===== */
if (!function_exists('startLogin')) {
function startLogin($chat_id, $raw_text=null){
    // 若已登入就直接回報學號/姓名
    if (_isLoggedIn($chat_id)) {
        global $pdo;
        $q=$pdo->prepare("SELECT username,full_name FROM users WHERE telegram_id=:tid LIMIT 1");
        $q->execute([':tid'=>(string)$chat_id]);
        if($u=$q->fetch(PDO::FETCH_ASSOC)){
            $extra = $u['full_name'] ? "（{$u['full_name']}）" : "";
            sendText($chat_id, "ℹ️ 你已登入：{$u['username']}{$extra}");
        } else {
            sendText($chat_id, "ℹ️ 你已登入。");
        }
        return;
    }
    // 支援 /login 直接綁定；否則設定狀態請他輸入
    if ($raw_text){
        $parts = preg_split('/\s+/', trim($raw_text));
        if (count($parts)>=2 && strtolower($parts[0])==='/login'){ handleLoginUsername($chat_id, $parts[1]); return; }
    }
    _clearState($chat_id);
    _setState($chat_id, ['step'=>'login_username']);
    sendText($chat_id, "🔐 請輸入學號：");
}}

/* ===== 收到學號 → 綁定 + 登入（回覆姓名） ===== */
if (!function_exists('handleLoginUsername')) {
function handleLoginUsername($chat_id, $text){
    global $pdo;

    //（允許沒狀態也能直接用學號登入，避免卡關）
    $sid = trim($text);
    if ($sid==='' || strlen($sid)>50) { sendText($chat_id,"⚠️ 學號格式不正確（1–50字）。"); return; }

    // 查是否有此學號
    $q=$pdo->prepare("SELECT username,full_name,telegram_id FROM users WHERE username=:sid LIMIT 1");
    $q->execute([':sid'=>$sid]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row){ sendText($chat_id,"❌ 查無此學號：{$sid}"); return; }

    // 若被他人綁定 → 拒絕
    if (!empty($row['telegram_id']) && (string)$row['telegram_id'] !== (string)$chat_id) {
        sendText($chat_id, "⚠️ 此學號已被其他 Telegram 帳號綁定。");
        return;
    }

    try {
        $pdo->beginTransaction();

        // A) 清掉這個 Telegram 帳號在其他學號的舊綁定
        $stmt = $pdo->prepare("
            UPDATE users
            SET telegram_id = NULL
            WHERE telegram_id = :tid_old
            AND username <> :sid
        ");
        $stmt->execute([
            ':tid_old' => (string)$chat_id,
            ':sid'     => $sid,
        ]);

        // B) 原子綁定：注意不要重複用同名參數（:tid_set ≠ :tid_chk）
        $u = $pdo->prepare("
            UPDATE users
            SET telegram_id = :tid_set
            WHERE username = :sid
            AND (telegram_id IS NULL OR telegram_id = :tid_chk)
            LIMIT 1
        ");
        $u->execute([
            ':tid_set' => (string)$chat_id,
            ':sid'     => $sid,
            ':tid_chk' => (string)$chat_id,
        ]);

        if ($u->rowCount() !== 1) {
            $pdo->rollBack();
            sendText($chat_id, "⚠️ 綁定失敗，請再試一次。");
            return;
        }

        $pdo->commit();

        clearUserState($chat_id);
        // 顯示姓名
        $qq = $pdo->prepare("SELECT full_name FROM users WHERE username = :sid LIMIT 1");
        $qq->execute([':sid' => $sid]);
        $name = $qq->fetch(PDO::FETCH_COLUMN) ?: '';
        $extra = $name ? "{$name}" : "";
        sendText($chat_id, "✅ 登入成功。歡迎你，{$extra}！ \n\n 點選 /learn 開始學習。");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('LOGIN_BIND_ERROR: '.$e->getMessage());
        sendText($chat_id, "系統錯誤，請稍後再試。");
        return;
    }
}}

/* ===== 相容：若別處呼叫 handleLoginText，就轉過來 ===== */
if (!function_exists('handleLoginText')) {
function handleLoginText($chat_id, $text){ handleLoginUsername($chat_id,$text); }}
