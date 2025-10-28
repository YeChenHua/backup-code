<?php
require_once __DIR__ . '/user_manager.php';

/*開始學習模式 - 找到用戶尚未完成的學習內容*/
function startLearning($chat_id, $unit_id = null, array $opts = []) {
    error_log("[TRACE] startLearning via=" . ($GLOBALS['__via'] ?? 'unknown') . " chat=" . $chat_id);

    global $pdo, $UNIT_ACCESS_TIMES; // 開放時間設定變數

    // 取得用戶資訊
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 尚未登入，請先使用 /login 進行登入。");
        return;
    }

    $telegram_id = $user['telegram_id'];

    // 判斷是否指定了特定單元 ID
    $targetUnit = null;
    if ($unit_id !== null && is_numeric($unit_id)) {
        // 如果有指定，直接使用該單元 ID
        $targetUnit = (int)$unit_id;
        // 額外檢查這個單元是否存在
        $stmt = $pdo->prepare("SELECT id FROM units WHERE id = ?");
        $stmt->execute([$targetUnit]);
        if (!$stmt->fetch()) {
            sendText($chat_id, "❌ 找不到指定的單元，請聯絡管理員");
            return;
        }
    } else {
        // 如果沒有指定，則執行原有的邏輯：尋找第一個未完成的單元
        // 1. 取得所有存在的 unit_id
        $stmt = $pdo->prepare("SELECT DISTINCT unit_id FROM lessons ORDER BY unit_id ASC");
        $stmt->execute();
        $allUnits = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($allUnits)) {
            sendText($chat_id, "❌ 系統中沒有找到任何學習單元，請聯絡管理員");
            return;
        }

        // 2. 找出第一個有未完成學習內容或測驗的 unit
        foreach ($allUnits as $unitId) {
            // 檢查該 unit 是否還有未完成的學習內容 (lessons)
            $stmt = $pdo->prepare("
                SELECT lessons.id FROM lessons 
                LEFT JOIN lesson_responses ul ON lessons.id = ul.lesson_id AND ul.telegram_id = ? 
                WHERE ul.id IS NULL AND lessons.unit_id = ?
                LIMIT 1
            ");
            $stmt->execute([$telegram_id, $unitId]);
            $unfinishedLesson = $stmt->fetch();

            // 檢查該 unit 是否還有未完成的測驗 (questions)
            $stmt = $pdo->prepare("
                SELECT questions.id FROM questions 
                LEFT JOIN question_results uq ON questions.id = uq.question_id AND uq.telegram_id = ? 
                WHERE uq.id IS NULL AND questions.unit_id = ?
                LIMIT 1
            ");
            $stmt->execute([$telegram_id, $unitId]);
            $unfinishedQuiz = $stmt->fetch();

            // 如果該單元還有任何未完成的 lessons 或 questions
            if ($unfinishedLesson || $unfinishedQuiz) {
                $targetUnit = $unitId;
                break; // 找到第一個未完全完成的單元，跳出迴圈
            }
        }
    }

    // 處理找到的目標單元
    if ($targetUnit === null) {
        // 所有單元都完成了
        sendText($chat_id, 
            "🎊 恭喜完成所有學習內容和測驗！\n\n" .
            "🏆 您已經掌握了所有 Python 知識\n"
        );
        return;
    }

    $class_code = $user['class_code'];   // ← 從資料庫取出 A 或 B
    $unitKey = 'unit' . $targetUnit;

    if (isset($UNIT_ACCESS_TIMES[$class_code][$unitKey])) {
        $unit_start_time = $UNIT_ACCESS_TIMES[$class_code][$unitKey];
        $current_time = time();
        
        if ($current_time < $unit_start_time) {
            $open_date = date('Y-m-d H:i:s', $unit_start_time);
            sendText($chat_id, "⚠️ 單元 {$targetUnit} 尚未開放");
            return; // 尚未開放 → 結束
        }
    }

    // 判斷要顯示學習內容還是測驗
    $stmt = $pdo->prepare("
        SELECT l.* FROM lessons l 
        LEFT JOIN lesson_responses ul ON l.id = ul.lesson_id AND ul.telegram_id = ? 
        WHERE ul.id IS NULL AND l.unit_id = ?
        ORDER BY l.id 
        LIMIT 1
    ");
    $stmt->execute([$telegram_id, $targetUnit]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lesson) {
        // 先取得 unit 名稱與描述
        $stmt = $pdo->prepare("SELECT name, description FROM units WHERE id = ?");
        $stmt->execute([$targetUnit]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC); // 取得一列資料

        $unitName = $unit['name'] ?? '未知單元';
        $unitDescription = $unit['description'] ?? '';

        // 計算該 unit 已完成的 lessons 數量
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM lesson_responses 
            WHERE telegram_id = ? 
            AND lesson_id IN (SELECT id FROM lessons WHERE unit_id = ?)
        ");
        $stmt->execute([$telegram_id, $targetUnit]);

        // 組合顯示文字
        $progressText = "📚 {$unitName} - {$unitDescription} ，學習進度：" . $lesson['title'] . "/3\n\n";

        showLearningContent($chat_id, $lesson, $progressText);
    } else {
        // 沒有未完成的 lessons，表示 lessons 都學完了，接下來顯示測驗
        showUnitCompletionOptions($chat_id, $targetUnit);
    }
}

/*顯示學習內容（圖片、情境、學習目標）*/
function showLearningContent($chat_id, $lesson) {
    global $pdo;

    // 檢查傳入的 $lesson 變數是否為陣列且非空
    if (!is_array($lesson) || empty($lesson)) {
        sendText($chat_id, "⚠️ 學習內容資料不完整或已失效，請聯絡管理員");
        error_log("showLearningContent: invalid lesson data received for chat_id=" . $chat_id);
        return;
    }

    $unit_id = $lesson['unit_id'] ?? null;
    $title = $lesson['title'] ?? '未知題號';

    if (!$unit_id) {
        sendText($chat_id, "⚠️ 無法取得單元 ID");
        return;
    }

    // 從資料庫取得單元名稱和描述
    $stmt = $pdo->prepare("SELECT name, description FROM units WHERE id = ?");
    $stmt->execute([$unit_id]);
    $unit = $stmt->fetch(PDO::FETCH_ASSOC);

    $unitName = $unit['name'] ?? "未知單元";
    $unitDescription = $unit['description'] ?? "無描述";
    
    // 組合顯示文字（Caption）
    $caption = "📚 {$unitName} - {$unitDescription} ，學習進度：" . $lesson['title'] . "/3\n\n";
    $caption .= "🔍 第{$title}題\n\n";
    $caption .= "🧩 情境\n";
    $caption .= is_array($lesson['context']) ? implode("\n", $lesson['context']) : (string)$lesson['context'];
    $caption .= "\n\n📖 學習目標\n";
    $caption .= is_array($lesson['concept']) ? implode("\n", $lesson['concept']) : (string)$lesson['concept'];

    // 準備按鈕
    $buttons = [[
        ['text' => '牛刀小試 🎯', 'callback_data' => "learn_question|{$lesson['id']}"]
    ]];

    // 取得圖片來源
    $photoSource = $lesson['image_file_id'] ?? null;
    $imageUrl = $lesson['image_url'] ?? null;
    $lessonId = $lesson['id'] ?? null;

    $success = false;

    // 1. 優先使用 file_id
    $apiResult = sendPhoto($chat_id, $photoSource, $caption, ['inline_keyboard' => $buttons]);
    $resp = is_array($apiResult) ? $apiResult : json_decode((string)$apiResult, true);
    if (!empty($resp['ok'])) {
        $success = true;
    }

    // 2. 如果 file_id 無效或不存在，則嘗試使用 URL
    if (!$success && !empty($imageUrl)) {
        $apiResult = sendPhoto($chat_id, $imageUrl, $caption, ['inline_keyboard' => $buttons]);
        $resp = is_array($apiResult) ? $apiResult : json_decode((string)$apiResult, true);
        if (!empty($resp['ok'])) {
            $success = true;
            // 取得新的 file_id（注意換成 $resp）
            $photos = $resp['result']['photo'] ?? [];
            $newFileId = $photos ? $photos[count($photos) - 1]['file_id'] ?? null : null;
            if ($newFileId && $lessonId) {
                try {
                    $stmt = $pdo->prepare("UPDATE lessons SET image_file_id = ? WHERE id = ?");
                    $stmt->execute([$newFileId, $lessonId]);
                    error_log("資料庫已更新新的 file_id: " . $newFileId);
                } catch (PDOException $e) {
                    error_log("更新資料庫失敗: " . $e->getMessage());
                }
            }
        }
    }

    // 3. 如果圖片傳送失敗，就發送純文字訊息
    if (!$success) {
        sendInlineKeyboard($chat_id, $caption, $buttons);
    }

    // === 計時開始（與 quiz.php 同步邏輯）===
    $user = getUserByTelegramId($chat_id);
    $telegram_id = $user['telegram_id'] ?? (string)$chat_id; // 後備用 chat_id
    if (function_exists('timer_start')) {
        timer_start($telegram_id, "lesson_start:{$lesson['id']}");
    }

    if (function_exists('updateUserState')) {
        updateUserState($chat_id, [
            'q_start'   => microtime(true),
            'lesson_id' => (int)$lesson['id']
        ]);
    }
}

/* 顯示學習問題和選項 */
function showLearningQuestion($chat_id, $lesson) {
    // 檢查資料完整性
    if (!is_array($lesson) || empty($lesson)) {
        sendText($chat_id, "⚠️ 問題內容不完整，請聯絡管理員");
        return;
    }

    $caption = "✍️ 請選擇正確答案：\n\n";
    $caption .= "📖 題目\n";
    $caption .= $lesson['question'] . "\n\n";

    if (!empty($lesson['code_block'])) {
        $caption .= "💻 程式碼\n";
        $caption .= "```python\n" . $lesson['code_block'] . "\n```\n\n";
    }

    // 選項文字直接顯示在訊息中
    $caption .= "A.\n" . $lesson['option_a'] . "\n\n";
    $caption .= "B.\n" . $lesson['option_b'] . "\n\n";
    $caption .= "C.\n" . $lesson['option_c'] . "\n\n";
    $caption .= "D.\n" . $lesson['option_d'] . "\n\n";

    // 建立倆倆一排的按鈕，只顯示 A/B/C/D
    $buttons = [
        [
            ['text' => 'A', 'callback_data' => "learn_answer|{$lesson['id']}|A"],
            ['text' => 'B', 'callback_data' => "learn_answer|{$lesson['id']}|B"]
        ],
        [
            ['text' => 'C', 'callback_data' => "learn_answer|{$lesson['id']}|C"],
            ['text' => 'D', 'callback_data' => "learn_answer|{$lesson['id']}|D"]
        ]
    ];

    sendInlineKeyboard($chat_id, $caption, $buttons);
}

/*處理學習的答案*/
function handleLearningAnswer($chat_id, $lesson_id, $user_answer, $message_id = null, $callback_query_id = null) {
    global $pdo;

    // 取得用戶資訊
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 請先登入");
        return;
    }
    $telegram_id = $user['telegram_id'];

    // 取得題目資料
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lesson) {
        sendText($chat_id, "❌ 找不到題目資料");
        return;
    }

    
    $current_unit = $lesson['unit_id'];

    // 檢查是否已經作答過
    $stmt = $pdo->prepare("SELECT user_answer FROM lesson_responses WHERE telegram_id = ? AND lesson_id = ?");
    $stmt->execute([$telegram_id, $lesson_id]);
    $existing_answer = $stmt->fetchColumn();

    if ($existing_answer) {
        // 查 click_log 看這個人這題是否已經提醒過
        $scope = "lesson_{$lesson_id}";
        $stmt = $pdo->prepare("SELECT id FROM click_log WHERE telegram_id=? AND scope=? LIMIT 1");
        $stmt->execute([$telegram_id, $scope]);
        $warned = $stmt->fetchColumn();

        if (!$warned) {
            // 第一次重複 → 發警告 + 插 click_log
            //sendText($chat_id, "⚠️ 你已經作答過這題囉！");
            $stmt = $pdo->prepare("INSERT INTO click_log (telegram_id, scope) VALUES (?, ?)");
            $stmt->execute([$telegram_id, $scope]);
        }
        // 之後再點 → 直接靜默，不做任何事
        return;
    }
    // === 先結束計時（秒）===
    $elapsed = function_exists('timer_stop')
        ? timer_stop($telegram_id, "lesson_start:{$lesson_id}")
        : null;

    // 換算「四捨五入的秒數」（若要毫秒，用下一段註解替換）
    $elapsed_sec = is_numeric($elapsed) ? (int) round($elapsed) : null;

    // 以下為原本的答題邏輯
    $is_correct = ($user_answer === $lesson['correct_option']);

    recordLearningProgress($chat_id, $lesson_id, $user_answer, $is_correct, $elapsed_sec);
    
    if ($is_correct) {
        sendSticker($chat_id, STICKER_CORRECT);
        $feedback = "🎉 答對了！ 答案是 {$lesson['correct_option']} 沒錯\n\n";
    } else {
        sendSticker($chat_id, STICKER_INCORRECT);
        $feedback = "😅 答錯了！ 正確答案是 {$lesson['correct_option']}\n\n";
    }

    $feedback .= "💡 解析\n{$lesson['explanation']}";
    sendText($chat_id, $feedback);

    // 顯示下一題按鈕
    $stmt = $pdo->prepare("
        SELECT l.* FROM lessons l
        LEFT JOIN lesson_responses ul ON l.id = ul.lesson_id AND ul.telegram_id = ? 
        WHERE ul.id IS NULL AND l.unit_id = ?
        ORDER BY l.id
        LIMIT 1
    ");
    $stmt->execute([$telegram_id, $current_unit]);
    $nextLesson = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($nextLesson) {
        $buttons = [[
            ['text' => '繼續下一題 ➡️', 'callback_data' => "continue_learning|{$nextLesson['id']}"]
        ]];
        sendInlineKeyboard($chat_id, "準備好繼續學習了嗎？", $buttons);
    } else {
        showUnitCompletionOptions($chat_id, $current_unit);
    }
}

/*顯示單元完成選項*/
function showUnitCompletionOptions($chat_id, $completed_unit, $mode = '') {
    global $pdo;

    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 尚未登入，請先使用 /login 進行登入。");
        return;
    }
    $telegram_id = $user['telegram_id'];

    // 取得單元名稱與描述
    $stmt = $pdo->prepare("SELECT name, description FROM units WHERE id = ?");
    $stmt->execute([$completed_unit]);
    $unitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit_name = $unitInfo['name'] ?? "未知單元";
    $unit_description = $unitInfo['description'] ?? "";

    // 優化後的查詢：一次性取得 Lessons 和 Questions 的進度
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM lessons WHERE unit_id = ?) as total_lessons,
            (SELECT COUNT(*) FROM lesson_responses WHERE telegram_id = ? AND lesson_id IN (SELECT id FROM lessons WHERE unit_id = ?)) as completed_lessons,
            (SELECT COUNT(*) FROM questions WHERE unit_id = ?) as total_questions,
            (SELECT COUNT(*) FROM question_results WHERE telegram_id = ? AND question_id IN (SELECT id FROM questions WHERE unit_id = ?)) as completed_questions,
            (SELECT COUNT(*) FROM question_results WHERE telegram_id = ? AND is_correct = 1 AND question_id IN (SELECT id FROM questions WHERE unit_id = ?)) as correct_questions
    ");
    $stmt->execute([$completed_unit, $telegram_id, $completed_unit, $completed_unit, $telegram_id, $completed_unit, $telegram_id, $completed_unit]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalLessons = $progress['total_lessons'];
    $completedLessons = $progress['completed_lessons'];
    $totalQuestions = $progress['total_questions'];
    $completedQuestions = $progress['completed_questions'];
    $correctQuestions = $progress['correct_questions'];
    
    $unfinishedQuizCount = $totalQuestions - $completedQuestions;

    $bonusMessage = '';
    $messageScore = getUserPoint($telegram_id, (int)$completed_unit);
    
    // 僅在測驗模式且測驗已完成時，才處理點數與 Bonus
    if ($mode === 'quiz' && $unfinishedQuizCount === 0) {
        // 檢查是否全部答對
        $allQuizCorrect = ($correctQuestions === $totalQuestions);
        if ($allQuizCorrect) {
            // 發放獎勵點數
            $oldScore = $messageScore;
            $messageScore = addBonusPoint($telegram_id, (int)$completed_unit, 20);
            $bonusMessage = "🎉 恭喜全部答對，獲得額外 20 點的獎勵！\n";
        } 
    }
    
    // 組合訊息內容，並統一發送
    $message = "🎊 恭喜完成「{$unit_name}：{$unit_description}」的學習內容！\n\n";
    $message .= "📚 學習進度：{$completedLessons}/{$totalLessons} " . ($completedLessons == $totalLessons ? '✅' : '') . "\n";
    $message .= "🧪 測驗進度：{$completedQuestions}/{$totalQuestions} " . ($completedQuestions == $totalQuestions ? '✅' : '') . "\n\n";
    
    $buttons = [];

    // 根據是否還有未完成測驗，決定顯示的內容與按鈕
    if ($unfinishedQuizCount > 0) {
        $message .= "請選擇下一步：";
        $buttons[] = [['text' => $unit_description . ' 測驗 🧪', 'callback_data' => "quiz|show_rule|{$completed_unit}"]];
    } else {
        // Lessons 和 Questions 都已完成
        if ($mode === 'quiz') {
            // ✅ 重新查該 id 的總和
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(score),0) FROM point WHERE telegram_id = ?");
            $stmt->execute([$telegram_id]);
            $messageScore = (int)$stmt->fetchColumn();
            
            $message .= $bonusMessage;
            $message .= "🪙 目前累積點數：{$messageScore} 點\n\n";
        }
        
        // 檢查是否有下一個單元
        $stmt = $pdo->prepare("SELECT DISTINCT id FROM units WHERE id > ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$completed_unit]);
        $nextUnit = $stmt->fetchColumn();

        if ($nextUnit) {
            //$message .= "🚀 準備好進入下一個挑戰了嗎？\n";
            // 「繼續學習」可能要考慮拿掉================================================================
            //$buttons[] = [['text' => '繼續學習 ➡️', 'callback_data' => "command_learn|{$nextUnit}"]];
            $buttons[] = [['text' => '歷史紀錄 📋', 'callback_data' => 'command_history']];
        } else {
            $message .= "🎊 您已完成所有單元內容！\n";
            $buttons[] = [['text' => '歷史紀錄 📋', 'callback_data' => 'command_history']];
        }
    }
    sendInlineKeyboard($chat_id, $message, $buttons);
}


/* 記錄學習進度到資料庫（含計時；防重複） */
function recordLearningProgress($chat_id, int $lesson_id, string $user_answer, bool $is_correct, ?int $rt_ms = null): bool {
    global $pdo;

    $user = getUserByTelegramId($chat_id);
    if (!$user) return false;
    $telegram_id = (string)$user['telegram_id'];
    if (!acquire_user_lock($pdo, $chat_id, 'answer', 2)) return false;

    // 防重複（已作答就不再寫）
    // 可選：避免同一用戶同流程重入（連點）
    if (function_exists('acquire_user_lock') && !acquire_user_lock($pdo, $chat_id, 'answer', 2)) {
        return false;
    }
    try {
        // ★★★★★ 關鍵：一次性寫入，靠 UNIQUE(telegram_id, lesson_id) 擋重複
        // schema 有這個唯一鍵 uniq_user_lesson。:contentReference[oaicite:4]{index=4}
        if ($rt_ms !== null) {
            $sql = "INSERT IGNORE INTO lesson_responses
                    (telegram_id, lesson_id, user_answer, is_correct, rt_ms, answered_at)
                    VALUES (?, ?, ?, ?, ?, NOW())";
            $params = [$telegram_id, $lesson_id, $user_answer, (int)$is_correct, (int)$rt_ms];
        } else {
            $sql = "INSERT IGNORE INTO lesson_responses
                    (telegram_id, lesson_id, user_answer, is_correct, answered_at)
                    VALUES (?, ?, ?, ?, NOW())";
            $params = [$telegram_id, $lesson_id, $user_answer, (int)$is_correct];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } finally {
        if (function_exists('release_user_lock')) release_user_lock($pdo, $chat_id, 'answer');
    }

    // 欄位偵測（有就寫，沒有就略過）
    if (!function_exists('has_column')) {
        function has_column($table, $column) {
            global $pdo;
            $q = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $q->execute([$table, $column]);
            return $q->fetchColumn() > 0;
        }
    }
    $hasRtMs = has_column('lesson_responses', 'rt_ms');
    $hasSec  = has_column('lesson_responses', 'time_spent_sec');

    // 組 SQL
    try {
        if ($hasRtMs && $time_spent_ms !== null) {
            $sql    = "INSERT INTO lesson_responses (telegram_id, lesson_id, user_answer, is_correct, rt_ms, answered_at)
                       VALUES (?, ?, ?, ?, ?, NOW())";
            $params = [$telegram_id, $lesson_id, $user_answer, $is_correct_val, (int)$time_spent_ms];
        } elseif ($hasSec && $time_spent_ms !== null) {
            $sec    = (int) round($time_spent_ms / 1000);
            $sql    = "INSERT INTO lesson_responses (telegram_id, lesson_id, user_answer, is_correct, time_spent_sec, answered_at)
                       VALUES (?, ?, ?, ?, ?, NOW())";
            $params = [$telegram_id, $lesson_id, $user_answer, $is_correct_val, $sec];
        } else {
            $sql    = "INSERT INTO lesson_responses (telegram_id, lesson_id, user_answer, is_correct, answered_at)
                       VALUES (?, ?, ?, ?, NOW())";
            $params = [$telegram_id, $lesson_id, $user_answer, $is_correct_val];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;

    } catch (PDOException $e) {
        error_log("recordLearningProgress insert error: " . $e->getMessage()
            . " params=" . json_encode([
                'tid' => $telegram_id,
                'lesson_id' => $lesson_id,
                'ans' => $user_answer,
                'is_correct' => $is_correct_val,
                'ms' => $time_spent_ms
            ], JSON_UNESCAPED_UNICODE));
        return false;
    }
}


/*繼續到下一個學習單元*/
function continueToNextLesson($chat_id, $lesson_id) {
    global $pdo;
    
    // 取得指定課程資料
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lesson) {
        showLearningContent($chat_id, $lesson);
    } else {
        sendText($chat_id, "❌ 找不到下一題");
    }
}
?>