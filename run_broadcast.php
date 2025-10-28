<?php
// 用法：php run_broadcast.php <unit_id_now> [--dry]
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
date_default_timezone_set('Asia/Taipei');

[$_, $UNIT_ID_NOW] = array_pad($argv, 2, null);
$DRY = in_array('--dry', $argv, true);
if (!ctype_digit((string)$UNIT_ID_NOW)) {
  fwrite(STDERR, "用法：php run_broadcast.php <unit_id_now> [--dry] [--only=A|B|TEST] [--testers|--no-testers]\n");
  exit(1);
}
$UNIT_ID_NOW = (int)$UNIT_ID_NOW;

// 將整串參數串起來，給 regex 用（很重要）
$ARGSTR = implode(' ', $argv);

// 旗標：只送某一族群 --only=A|B|TEST
$ONLY = null;
if (preg_match('/--only=(A|B|TEST)/i', $ARGSTR, $m)) {
  $ONLY = strtoupper($m[1]);
}

// 旗標：是否連同測試員
$WITH_TESTERS = in_array('--testers', $argv, true);
$NO_TESTERS   = in_array('--no-testers', $argv, true);
// 旗標：僅送「進度提醒」（不宣告新單元開放）
$REMIND_ONLY = in_array('--remind', $argv, true);

// 根據 --only 決定這輪要不要跑各段
if ($ONLY !== null) {
  // ★★ 關掉全部，之後只打開指定的那一段 ★★
  $DO_A = $DO_B = $DO_TEST = false;
  if ($ONLY === 'A')    $DO_A   = true;
  if ($ONLY === 'B')    $DO_B   = true;
  if ($ONLY === 'TEST') $DO_TEST= true;
  // 若你要「只送 A/B 但測試員也一起收」，就加 --testers
  if ($WITH_TESTERS && $ONLY !== 'TEST') $DO_TEST = true;
} else {
  // 沒有 --only：預設三段都開
  $DO_A = $DO_B = $DO_TEST = true;
  if ($NO_TESTERS) $DO_TEST = false;
}

$nowTs = time();

// ---------- 開放時間（config.php） ----------
function resolveOpenTsForClass(PDO $pdo, string $class, int $unitId): ?int {
  // 只讀 config.php
  global $UNIT_ACCESS_TIMES;
  $key = "unit{$unitId}";
  return !empty($UNIT_ACCESS_TIMES[$class][$key]) ? (int)$UNIT_ACCESS_TIMES[$class][$key] : null;
}

function getOpenUnitsForClass(PDO $pdo, string $class): array {
  $now = time(); $open=[];
  foreach ($pdo->query("SELECT id FROM units ORDER BY id") as $r) {
    $uid=(int)$r['id']; $t=resolveOpenTsForClass($pdo,$class,$uid);
    if ($t!==null && $t <= $now) $open[$uid]=$t;
  }
  return $open; // [unit_id=>ts]
}

function getOpenUnitsForTester(PDO $pdo): array {
  $now = time(); $open=[];
  foreach ($pdo->query("SELECT id FROM units ORDER BY id") as $r) {
    $uid=(int)$r['id']; $cand=[];
    foreach (['A','B'] as $c){ $t=resolveOpenTsForClass($pdo,$c,$uid); if($t!==null) $cand[]=$t; }
    if ($cand){ $t=min($cand); if ($t <= $now) $open[$uid]=$t; }
  }
  return $open;
}
$openNowAts = [
  'A' => resolveOpenTsForClass($pdo,'A',$UNIT_ID_NOW),
  'B' => resolveOpenTsForClass($pdo,'B',$UNIT_ID_NOW),
];
// 是否已到各班「本期單元」開放時間
$A_now_open = ($openNowAts['A'] !== null && $openNowAts['A'] <= $nowTs);
$B_now_open = ($openNowAts['B'] !== null && $openNowAts['B'] <= $nowTs);

// ★ 本輪給「測試員抬頭標籤」用的時間：只把「本輪有宣布的班別」留下，其他設為 null
//    （這裡用 DO_A / DO_B 來反映 --only= 的選擇）
$tsA_for_label = ($DO_A && $A_now_open) ? $openNowAts['A'] : null;
$tsB_for_label = ($DO_B && $B_now_open) ? $openNowAts['B'] : null;


// ---------- 準備各族群的「已開放單元」 ----------
$openTester = getOpenUnitsForTester($pdo);
$openA      = getOpenUnitsForClass($pdo, 'A');
$openB      = getOpenUnitsForClass($pdo, 'B');
$openNowAts = [
  'A' => resolveOpenTsForClass($pdo,'A',$UNIT_ID_NOW),
  'B' => resolveOpenTsForClass($pdo,'B',$UNIT_ID_NOW),
];
if (!$openTester && !$openA && !$openB) { echo "目前沒有任何單元處於開放狀態。\n"; exit; }

// ---------- 題數總表（便於跳過完全沒題目的單元） ----------
$allUnitIds = array_values(array_unique(array_merge(
  array_keys($openTester), array_keys($openA), array_keys($openB)
)));
$totL = []; $totQ = [];
if ($allUnitIds) {
  $ids = implode(',', array_map('intval', $allUnitIds));
  foreach ($pdo->query("SELECT unit_id, COUNT(*) c FROM lessons   WHERE unit_id IN ($ids) GROUP BY unit_id") as $r) $totL[(int)$r['unit_id']] = (int)$r['c'];
  foreach ($pdo->query("SELECT unit_id, COUNT(*) c FROM questions WHERE unit_id IN ($ids) GROUP BY unit_id") as $r) $totQ[(int)$r['unit_id']] = (int)$r['c'];
}

// ---------- 完成判定：是否有作答該單元「最後一題」 ----------
function fetchIncompleteUnits(PDO $pdo, string $telegramId, array $unitIds, array $totL, array $totQ): array {
  if (!$unitIds) return [];

  $ph = implode(',', array_fill(0, count($unitIds), '?'));
  $sqlLast = "
    SELECT q.unit_id, q.id AS last_qid
      FROM questions q
      JOIN (
            SELECT unit_id, MAX(CAST(title AS UNSIGNED)) AS mt
              FROM questions
             WHERE unit_id IN ($ph)
             GROUP BY unit_id
           ) t ON t.unit_id = q.unit_id AND CAST(q.title AS UNSIGNED) = t.mt
  ";
  $stLast = $pdo->prepare($sqlLast);
  $stLast->execute($unitIds);

  $lastQidByUnit = []; $lastQids = [];
  while ($r = $stLast->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$r['unit_id']; $qid = (int)$r['last_qid'];
    $lastQidByUnit[$uid] = $qid; $lastQids[] = $qid;
  }
  if (!$lastQids) return []; // 沒題目的單元，不提醒

  $phQ = implode(',', array_fill(0, count($lastQids), '?'));
  $stAns = $pdo->prepare("
    SELECT DISTINCT qr.question_id
      FROM question_results qr
     WHERE qr.telegram_id = ?
       AND qr.question_id IN ($phQ)
  ");
  $args = array_merge([$telegramId], $lastQids);
  $stAns->execute($args);

  $answeredLast = [];
  while ($r = $stAns->fetch(PDO::FETCH_ASSOC)) $answeredLast[(int)$r['question_id']] = true;

  $incomplete = [];
  foreach ($unitIds as $uid) {
    if (!isset($lastQidByUnit[$uid])) continue;
    $lastQ = $lastQidByUnit[$uid];
    if (empty($answeredLast[$lastQ])) $incomplete[] = $uid;
  }
  return $incomplete;
}

// ---------- 發送 & DRY ----------
function tgSend($chat_id, $text){
  $url = TELEGRAM_API_URL . "/sendMessage";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
    CURLOPT_POSTFIELDS=>['chat_id'=>$chat_id, 'text'=>$text]
  ]);
  $res=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
  if ($err) return false; $j=json_decode($res,true); return !empty($j['ok']);
}
function maybeSend($tid, $text, $dry, $grp='') {
  if ($dry) {
    $tag = $grp ? "/$grp" : '';
    echo "[DRY{$tag}→{$tid}]\n{$text}\n";
    return true;
  }
  return tgSend($tid, $text);
}

// ---------- 文案（測試員：含本次單元；同一行串列） ----------
function buildMessageTester(int $unitNow, ?int $tsA, ?int $tsB, array $incompleteUnitIds): string {
  $now = time(); 
  $labels = [];
  if ($tsA !== null && $tsA <= $now) $labels[] = '夜間部';
  if ($tsB !== null && $tsB <= $now) $labels[] = '日間部';
  $suff = $labels ? '（' . implode('、', $labels) . '）' : '';

  $header = "🔔第{$unitNow}單元已開放，現在就來挑戰吧！{$suff}";
  if (!$incompleteUnitIds) return $header . "\n點選 /learn 開始練習";

  // 用「、」把單元名接成同一行，例如：第1單元、 第2單元
  $joined = implode('、 ', array_map(fn($u) => "第{$u}單元", $incompleteUnitIds));

  return $header
       . "\n趕緊把{$joined}補上，跟上最新進度吧💪\n"
       . "點選 /learn 開始練習";
}

// A/B 班：當期一定宣布；若有「舊單元」未完成，鼓勵補齊
function buildMessageClass(int $unitNow, array $backlogUnitIds): string {
  $header = "🔔第{$unitNow}單元已開放，現在就來挑戰吧！";
  if (!$backlogUnitIds) return $header . "\n點選 /learn 開始練習";

  // 讓單元清單在同一行，用「、」串起來
  $joined = implode('、 ', array_map(fn($u) => "第{$u}單元", $backlogUnitIds));

  return $header
       . "\n趕緊把{$joined}補上，跟上最新進度吧💪\n"
       . "點選 /learn 開始練習";
}

// 僅「進度提醒」用：若有 backlog，提醒補齊
function buildMessageBacklog(array $backlogUnitIds): ?string {
  if (!$backlogUnitIds) return null;
  $joined = implode('、 ', array_map(fn($u) => "第{$u}單元", $backlogUnitIds));
  return "📌 進度提醒\n你還有尚未完成的單元：{$joined}\n點選 /learn 開始學習。";
}

// ---------- 去重 ----------
$insLog = $pdo->prepare("INSERT IGNORE INTO notification_log (class_code, unit_id, telegram_id, kind)
                         VALUES (?, ?, ?, ?)");

// ---------- 統計 ----------
$stat = [
  'TEST'=>['sent'=>0,'skip'=>0,'fail'=>0],
  'A'   =>['sent'=>0,'skip'=>0,'fail'=>0],
  'B'   =>['sent'=>0,'skip'=>0,'fail'=>0],
];

// ---------- 測試員（一定發） ----------
$users = $pdo->query("
  SELECT telegram_id FROM users
   WHERE status='active'
     AND telegram_id IS NOT NULL
     AND is_tester=1
     AND (class_code IS NULL OR class_code='')
   ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($users) {
  $openIds = array_keys($openTester); // 以 A/B 較早開放集合
  if ($DO_TEST) {
  foreach ($users as $u) {
    $tid = $u['telegram_id'];

    if ($REMIND_ONLY) {
    // 提醒模式：抓「所有已開放」中的未完成
    $backlog = fetchIncompleteUnits($pdo, $tid, array_keys($openTester), $totL, $totQ);
    if (!$backlog) { $stat['TEST']['skip']++; continue; }

    if (!$DRY) {
      // 提醒用獨立 kind，unit_id 用 0 當占位（僅作記錄；去重靠唯一鍵+日期/小時桶）
      $insLog->execute(['TEST', 0, $tid, 'remind_test']);
      if ($insLog->rowCount() === 0) { $stat['TEST']['skip']++; continue; }
    }

    $text = buildMessageBacklog($backlog);
    if (maybeSend($tid, $text, $DRY, 'TEST')) $stat['TEST']['sent']++; else $stat['TEST']['fail']++;
    usleep(300000);
    continue;
  }

    // 先抓所有已開放單元的未完成
    $incompAll = fetchIncompleteUnits($pdo, $tid, $openIds, $totL, $totQ);
    // ❗排除「本次開放的單元」，避免出現「趕緊把第N單元補上」
    $incomp = array_values(array_diff($incompAll, [$UNIT_ID_NOW]));

    if (!$DRY) {
      $insLog->execute(['TEST', $UNIT_ID_NOW, $tid, 'notify_test']);
      if ($insLog->rowCount() === 0) { $stat['TEST']['skip']++; continue; }
    }

    $text = buildMessageTester($UNIT_ID_NOW, $tsA_for_label, $tsB_for_label, $incomp);
    if (maybeSend($tid, $text, $DRY, 'TEST')) $stat['TEST']['sent']++; else $stat['TEST']['fail']++;
    usleep(300000);
    }
  }
}

// ---------- A 班：僅在本次單元到時才送，且不把本次列入 backlog ----------
$A_now_open = ($openNowAts['A'] !== null && $openNowAts['A'] <= $nowTs);
if ($DO_A) {
    if ($REMIND_ONLY) {
    if ($openA) {
      $openIds = array_keys($openA);
      $stmtA = $pdo->query("
        SELECT telegram_id FROM users
        WHERE status='active'
          AND telegram_id IS NOT NULL
          AND is_tester=0
          AND class_code='A'
        ORDER BY id ASC
      ");
      while ($u = $stmtA->fetch(PDO::FETCH_ASSOC)) {
        $tid = $u['telegram_id'];
        $backlog = fetchIncompleteUnits($pdo, $tid, $openIds, $totL, $totQ);
        if (!$backlog) { $stat['A']['skip']++; continue; }

        if (!$DRY) {
          $insLog->execute(['A', 0, $tid, 'remind']);
          if ($insLog->rowCount()===0){ $stat['A']['skip']++; continue; }
        }
        $text = buildMessageBacklog($backlog);
        if (maybeSend($tid, $text, $DRY, 'A')) $stat['A']['sent']++; else $stat['A']['fail']++;
        usleep(300000);
      }
    } else {
      echo "⏭️ A 班目前沒有已開放單元可提醒\n";
    }
  } else {
    // ======= 原本的「宣告新單元開放」流程（保留） =======
        if ($openA && $A_now_open) {
        $openIds = array_keys($openA);
        $openIdsExceptCurrent = array_values(array_diff($openIds, [$UNIT_ID_NOW]));
        $stmtA = $pdo->query("
            SELECT telegram_id FROM users
            WHERE status='active'
            AND telegram_id IS NOT NULL
            AND is_tester=0
            AND class_code='A'
            ORDER BY id ASC
        ");
        while ($u = $stmtA->fetch(PDO::FETCH_ASSOC)) {
            $tid = $u['telegram_id'];
            $backlog = $openIdsExceptCurrent
            ? fetchIncompleteUnits($pdo, $tid, $openIdsExceptCurrent, $totL, $totQ)
            : [];
            if (!$DRY) {
            $insLog->execute(['A', $UNIT_ID_NOW, $tid, 'notify']);
            if ($insLog->rowCount()===0){ $stat['A']['skip']++; continue; }
            }
            $text = buildMessageClass($UNIT_ID_NOW, $backlog);
            if (maybeSend($tid, $text, $DRY, 'A')) $stat['A']['sent']++; else $stat['A']['fail']++;
            usleep(300000);
        }
        } else {
        echo "⏭️ A 班第{$UNIT_ID_NOW}尚未開放，略過 A 班通知\n";
        }
    }
}
// ---------- B 班：僅在本次單元到時才送，且不把本次列入 backlog ----------
$B_now_open = ($openNowAts['B'] !== null && $openNowAts['B'] <= $nowTs);
if ($DO_B) {
    if ($REMIND_ONLY) {
    if ($openB) {
      $openIds = array_keys($openB);
      $stmtB = $pdo->query("
        SELECT telegram_id FROM users
        WHERE status='active'
          AND telegram_id IS NOT NULL
          AND is_tester=0
          AND class_code='B'
        ORDER BY id ASC
      ");
      while ($u = $stmtB->fetch(PDO::FETCH_ASSOC)) {
        $tid = $u['telegram_id'];
        $backlog = fetchIncompleteUnits($pdo, $tid, $openIds, $totL, $totQ);
        if (!$backlog) { $stat['B']['skip']++; continue; }

        if (!$DRY) {
          $insLog->execute(['B', 0, $tid, 'remind']);
          if ($insLog->rowCount()===0){ $stat['B']['skip']++; continue; }
        }
        $text = buildMessageBacklog($backlog);
        if (maybeSend($tid, $text, $DRY, 'B')) $stat['B']['sent']++; else $stat['B']['fail']++;
        usleep(300000);
      }
    } else {
      echo "⏭️ B 班目前沒有已開放單元可提醒\n";
    }
  } else {
    // ======= 原本的「宣告新單元開放」流程（保留） =======
        if ($openB && $B_now_open) {
        $openIds = array_keys($openB);
        $openIdsExceptCurrent = array_values(array_diff($openIds, [$UNIT_ID_NOW]));
        $stmtB = $pdo->query("
            SELECT telegram_id FROM users
            WHERE status='active'
            AND telegram_id IS NOT NULL
            AND is_tester=0
            AND class_code='B'
            ORDER BY id ASC
        ");
        while ($u = $stmtB->fetch(PDO::FETCH_ASSOC)) {
            $tid = $u['telegram_id'];
            $backlog = $openIdsExceptCurrent
            ? fetchIncompleteUnits($pdo, $tid, $openIdsExceptCurrent, $totL, $totQ)
            : [];
            if (!$DRY) {
            $insLog->execute(['B', $UNIT_ID_NOW, $tid, 'notify']);
            if ($insLog->rowCount()===0){ $stat['B']['skip']++; continue; }
            }
            $text = buildMessageClass($UNIT_ID_NOW, $backlog);
            if (maybeSend($tid, $text, $DRY, 'B')) $stat['B']['sent']++; else $stat['B']['fail']++;
            usleep(300000);
        }
        } else {
        echo "⏭️ B 班第{$UNIT_ID_NOW}尚未開放，略過 B 班通知\n";
        }
    }
}

// ---------- 統計輸出 ----------
foreach (['TEST','A','B'] as $k){
  echo "{$k}：送出={$stat[$k]['sent']}，略過(已送過)={$stat[$k]['skip']}，失敗={$stat[$k]['fail']}\n";
}
if ($DRY) {
  echo "⚠️ 測試模式，未實際發送\n";
} else {
  try {
    // 👇 新增：依模式寫入 kind；提醒模式把 unit_id 記 0（當日總提醒）
    $runKind = $REMIND_ONLY ? 'remind' : 'notify';

    foreach (['TEST','A','B'] as $k){
      $sent = $stat[$k]['sent'];
      if ($sent > 0){
        $pdo->prepare("INSERT INTO broadcast_log (class_code, unit_id, kind, sent_count)
                       VALUES (?, ?, ?, ?)")
            ->execute([$k, $REMIND_ONLY ? 0 : $UNIT_ID_NOW, $runKind, $sent]);
      }
    }
    echo "✅ 已完成推播記錄\n";
  } catch (Throwable $e) {
    echo "✅ 推播完成（統計未寫入：" . $e->getMessage() . ")\n";
  }
}