<?php
// completed.php — /done：各單元完成名單（截至目前）
// 測試員：分兩則訊息（B=日間部、A=夜間部），只列已開放單元
// 學生：只看自己班、已開放單元；不顯示班別標籤；不顯示時間與編號

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/user_manager.php';
require_once __DIR__ . '/point.php';

// 建議統一時區
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Taipei');
}

/* =========================================
 * 工具：顯示用班別名稱與點數存取
 * =======================================*/

/** A/B 對應顯示名稱（A=夜間部、B=日間部） */
function displayClassLabel(string $code): string {
    return $code === 'A' ? '夜間部' : ($code === 'B' ? '日間部' : $code);
}

/** 安全呼叫 getUserPoint（相容 1 或 2 參數版本；目前本檔未使用，保留以備用） */
function safeGetUserPoint(string $telegram_id, ?PDO $pdo = null): int {
    if (!function_exists('getUserPoint')) return 0;
    try {
        $ref = new ReflectionFunction('getUserPoint');
        $req = $ref->getNumberOfRequiredParameters();
        $params = $ref->getParameters();

        if ($req === 1) {
            return (int) getUserPoint($telegram_id);
        } elseif ($req >= 2) {
            $p0 = $params[0] ?? null; $p1 = $params[1] ?? null;
            $isP0PDO = $p0 && ( ($p0->hasType() && (string)$p0->getType() === PDO::class) || strtolower($p0->getName()) === 'pdo' );
            $isP1PDO = $p1 && ( ($p1->hasType() && (string)$p1->getType() === PDO::class) || strtolower($p1->getName()) === 'pdo' );
            if     ($isP0PDO) return (int) getUserPoint($pdo, $telegram_id);
            elseif ($isP1PDO) return (int) getUserPoint($telegram_id, $pdo);
            else              return (int) getUserPoint($telegram_id, $pdo);
        }
    } catch (Throwable $e) { }
    return 0;
}

/* =========================================
 * 取得「某班別在現在時點已開放」的單元清單
 * =======================================*/

/**
 * 依據 config.php 的 $UNIT_ACCESS_TIMES 與 $nowTs，
 * 回傳指定班別（'A' 或 'B'）目前已開放的單元列表（id, name）。
 * 若未設定開放表，則退回所有單元。
 */
function getUnitsOpenForClass(PDO $pdo, string $classCode, int $nowTs): array {
    if (isset($GLOBALS['UNIT_ACCESS_TIMES'])
        && is_array($GLOBALS['UNIT_ACCESS_TIMES'])
        && isset($GLOBALS['UNIT_ACCESS_TIMES'][$classCode])
        && is_array($GLOBALS['UNIT_ACCESS_TIMES'][$classCode])) {

        $ids = [];
        foreach ($GLOBALS['UNIT_ACCESS_TIMES'][$classCode] as $unitKey => $ts) {
            if (preg_match('/^unit(\d+)$/', $unitKey, $m)) {
                $uid = (int)$m[1];
                if ((int)$ts <= $nowTs) $ids[] = $uid;
            }
        }

        if ($ids) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name, description FROM units WHERE id IN ($ph) ORDER BY id ASC");
            $stmt->execute($ids);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return []; // 尚未開放任何單元
    }

    // 未設定開放表：退回所有單元（帶 name/description）
    $stmt = $pdo->query("SELECT id, name, description FROM units ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* =========================================
 * 查詢：依「此刻 $nowTs」為截止的完成名單（固定用 answered_at）
 * =======================================*/

/**
 * 回傳在指定單元 $unitId、班別過濾 $classFilter（'A'/'B'/'ALL'）、
 * 截止時間 $nowTs（answered_at <= now）下，已「完成（看完所有 lesson + 作答所有 quiz）」的學生清單。
 * 回傳欄位：username（學號）、full_name（姓名）
 */
function fetchCompletedListAsOfNow(PDO $pdo, int $unitId, string $classFilter, int $nowTs) : array {
    $nowStr = date('Y-m-d H:i:s', $nowTs);

    $sql = <<<SQL
SELECT 
  u.username,      -- 學號
  u.full_name      -- 姓名
FROM users u
JOIN (
  SELECT lr.telegram_id, COUNT(DISTINCT l.id) AS lesson_done
  FROM lesson_responses lr
  JOIN lessons l ON l.id = lr.lesson_id
  WHERE l.unit_id = :u1
    AND lr.answered_at IS NOT NULL
    AND lr.answered_at <= :now1
  GROUP BY lr.telegram_id
) AS ul ON ul.telegram_id = u.telegram_id
JOIN (
  SELECT qr.telegram_id, COUNT(DISTINCT q.id) AS quiz_done
  FROM question_results qr
  JOIN questions q ON q.id = qr.question_id
  WHERE q.unit_id = :u2
    AND qr.answered_at IS NOT NULL
    AND qr.answered_at <= :now2
  GROUP BY qr.telegram_id
) AS uq ON uq.telegram_id = u.telegram_id
CROSS JOIN (
  SELECT 
    (SELECT COUNT(*) FROM lessons   WHERE unit_id = :u3) AS need_lessons,
    (SELECT COUNT(*) FROM questions WHERE unit_id = :u4) AS need_quizzes
) need
WHERE 
  ul.lesson_done >= need.need_lessons
  AND uq.quiz_done >= need.need_quizzes
  AND u.status = 'active'
  AND u.is_tester = 0
  AND (:c1 = 'ALL' OR u.class_code = :c2)
ORDER BY u.username ASC
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':u1',   $unitId,  PDO::PARAM_INT);
    $stmt->bindValue(':u2',   $unitId,  PDO::PARAM_INT);
    $stmt->bindValue(':u3',   $unitId,  PDO::PARAM_INT);
    $stmt->bindValue(':u4',   $unitId,  PDO::PARAM_INT);
    $stmt->bindValue(':c1',   $classFilter, PDO::PARAM_STR);
    $stmt->bindValue(':c2',   $classFilter, PDO::PARAM_STR);
    $stmt->bindValue(':now1', $nowStr, PDO::PARAM_STR);
    $stmt->bindValue(':now2', $nowStr, PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================
 * 格式化輸出：把一份名單排成文字
 * =======================================*/

/**
 * 把「學號/姓名」名單排成文字（不顯示時間、無編號）
 * 例：11361118  測試員
 */
function formatCompletedBlock(array $rows): string {
    if (!$rows) {
        return "\n目前還沒有人完成。\n—— 合計：0 人 ——";
    }
    $out = '';
    foreach ($rows as $r) {
        $out .= sprintf("%-10s %s\n", $r['username'], $r['full_name']);
    }
    $out .= "\n—— 合計：" . count($rows) . " 人 ——";
    return $out;
}

/* =========================================
 * 指令處理：/done
 * =======================================*/

/**
 * /done：依 $UNIT_ACCESS_TIMES 只顯示「已開放」單元
 * - 測試員：分兩則訊息（先日間部B、再夜間部A），每則只列該班已開放單元
 * - 學生：僅列出自己班已開放單元（不顯示班別文字）
 */
function cmdDone($chat_id) {
    global $pdo;

    $me = getUserByTelegramId($chat_id);
    if (!$me) { sendText($chat_id, "⚠️ 尚未登入，請先 /login"); return; }

    $isTester = !empty($me['is_tester']);
    $myClass  = $me['class_code'] ?? null;
    $nowTs    = time();

    // 內部小工具：實際送出一個「單元」的訊息
    $sendUnitMsg = function($unit, string $classCode) use ($chat_id, $pdo, $nowTs, $isTester) {
        // 允許傳入「陣列」或「純 id」
        if (is_array($unit)) {
            $unitRow = $unit;
        } else {
            $unitId = (int)$unit;
            $stmt = $pdo->prepare("SELECT id, name, description FROM units WHERE id = ? LIMIT 1");
            $stmt->execute([$unitId]);
            $unitRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $unitId, 'name' => null, 'description' => null];
        }

        $unitId = (int)$unitRow['id'];
        $title  = trim((string)($unitRow['name'] ?? ''));          // units.name
        $desc   = trim((string)($unitRow['description'] ?? ''));   // units.description

        // ▶ testers 有班別標籤；學生沒有
        $header = $isTester
            ? "📊 截至目前為止完成名單（" . displayClassLabel($classCode) . "）"
            : "📊 截至目前為止完成名單";

        $list = fetchCompletedListAsOfNow($pdo, $unitId, $classCode, $nowTs);

        // 組訊息：標題行 + 單元名稱/描述 + 名單
        $msg  = $header . "\n\n";
        $msg .= ($title !== '' ? $title : "{$unitId}");
        $msg .= ($desc !== '' ? "： {$desc}\n\n" : "\n");
        $msg .= formatCompletedBlock($list);  // 無編號、無時間

        if (function_exists('sendLongText')) sendLongText($chat_id, rtrim($msg));
        else sendText($chat_id, rtrim($msg));
    };

    if ($isTester) {
        // 先夜間部(A)，後日間部(B)
        $unitsA = getUnitsOpenForClass($pdo, 'A', $nowTs);
        $unitsB = getUnitsOpenForClass($pdo, 'B', $nowTs);

        // 夜間部 (A)
        if (!$unitsA) {
            $msg = "📊 截至目前為止完成名單（" . displayClassLabel('A') . "）\n（目前尚未開放任何單元）";
            function_exists('sendLongText') ? sendLongText($chat_id, $msg) : sendText($chat_id, $msg);
        } else {
            foreach ($unitsA as $u) {
                $sendUnitMsg($u, 'A');
            }
        }

        // 日間部 (B)
        if (!$unitsB) {
            $msg = "📊 截至目前為止完成名單（" . displayClassLabel('B') . "）\n（目前尚未開放任何單元）";
            function_exists('sendLongText') ? sendLongText($chat_id, $msg) : sendText($chat_id, $msg);
        } else {
            foreach ($unitsB as $u) {
                $sendUnitMsg($u, 'B');
            }
        }
        return;
    }

    // 學生端
    if ($myClass !== 'A' && $myClass !== 'B') {
        sendText($chat_id, "你的班別未設定，請聯絡助教。");
        return;
    }

    $units = getUnitsOpenForClass($pdo, $myClass, $nowTs);
    if (!$units) {
        $msg = "📊 截至目前為止完成名單\n（你的班目前尚未開放任何單元）";
        function_exists('sendLongText') ? sendLongText($chat_id, $msg) : sendText($chat_id, $msg);
        return;
    }

    foreach ($units as $u) {
        $sendUnitMsg($u, $myClass);
    }
}


/* =========================================
 * 其它可能在專案中會用到的工具（保留）
 * =======================================*/

/** 判斷是否完成某單元（lesson 全看 + quiz 全作答） */
function userFinishedUnit(PDO $pdo, string $telegram_id, int $unitId): bool {
    $needLessons = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE unit_id = {$unitId}")->fetchColumn();
    $needQuizzes = (int)$pdo->query("SELECT COUNT(*) FROM questions WHERE unit_id = {$unitId}")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT l.id)
                           FROM lesson_responses lr
                           JOIN lessons l ON l.id = lr.lesson_id
                           WHERE lr.telegram_id = ? AND l.unit_id = ?");
    $stmt->execute([$telegram_id, $unitId]);
    $gotLessons = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT q.id)
                           FROM question_results qr
                           JOIN questions q ON q.id = qr.question_id
                           WHERE qr.telegram_id = ? AND q.unit_id = ?");
    $stmt->execute([$telegram_id, $unitId]);
    $gotQuizzes = (int)$stmt->fetchColumn();

    return ($gotLessons >= $needLessons) && ($gotQuizzes >= $needQuizzes);
}

/** 解析「最新單元」：目前本檔未使用，保留以備不時之需 */
function resolveLatestUnitId(PDO $pdo, array $me): int {
    $hasOrder = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM units LIKE 'order_index'")->fetch(PDO::FETCH_ASSOC);
        $hasOrder = (bool)$chk;
    } catch (Throwable $e) { }

    if (!empty($me['is_tester'])) {
        $sql = $hasOrder
            ? "SELECT id FROM units ORDER BY order_index DESC, id DESC LIMIT 1"
            : "SELECT id FROM units ORDER BY id DESC LIMIT 1";
        $u = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return (int)($u['id'] ?? 0);
    }

    if (isset($GLOBALS['UNIT_ACCESS_TIMES']) && is_array($GLOBALS['UNIT_ACCESS_TIMES'])) {
        $class = $me['class_code'] ?? null; // A/B
        if ($class && isset($GLOBALS['UNIT_ACCESS_TIMES'][$class])) {
            $now = time();
            $latest = null;
            foreach ($GLOBALS['UNIT_ACCESS_TIMES'][$class] as $unitKey => $ts) {
                if (preg_match('/^unit(\d+)$/', $unitKey, $m)) {
                    $uid = (int)$m[1];
                    if ((int)$ts <= $now) $latest = max($latest ?? 0, $uid);
                }
            }
            if ($latest !== null) return (int)$latest;
        }
    }

    $sql = $hasOrder
        ? "SELECT id FROM units ORDER BY order_index DESC, id DESC LIMIT 1"
        : "SELECT id FROM units ORDER BY id DESC LIMIT 1";
    $u = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return (int)($u['id'] ?? 0);
}