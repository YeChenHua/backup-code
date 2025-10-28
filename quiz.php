<?php
session_start();
require_once(__DIR__ . '/point.php');
require_once(__DIR__ . '/telegram_api.php');

/*開始測驗模式 - 找到用戶尚未完成的測驗*/
function startQuiz($chat_id, $specific_unit = null) {
    global $pdo;

    // 檢查用戶登入狀態
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 尚未登入，請先使用 /login 進行登入。");
        return;
    }

    $telegram_id = $user['telegram_id'];

    // 1. 取得所有存在的 unit_id
    $stmt = $pdo->prepare("SELECT DISTINCT unit_id FROM questions ORDER BY unit_id ASC");
    $stmt->execute();
    $allUnits = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allUnits)) {
        sendText($chat_id, "❌ 系統中沒有找到任何測驗單元，請聯絡管理員");
        return;
    }

    // 2. 檢查是否有未完成學習的單元
    foreach ($allUnits as $unitId) {
        // 查詢該 unit 是否還有未完成的學習內容
        $stmt = $pdo->prepare("
            SELECT l.* FROM lessons l 
            LEFT JOIN lesson_responses ul ON l.id = ul.lesson_id AND ul.telegram_id = ? 
            WHERE ul.id IS NULL AND l.unit_id = ?
            ORDER BY l.id 
            LIMIT 1
        ");
        $stmt->execute([$telegram_id, $unitId]);
        $unfinishedLesson = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($unfinishedLesson) {
            // 取得單元名稱與描述
            $stmt = $pdo->prepare("SELECT name, description FROM units WHERE id = ?");
            $stmt->execute([$unitId]);
            $unitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $unit_name = $unitInfo['name'] ?? "未知單元";
            $unit_description = $unitInfo['description'] ?? "";

            // 找到未完成的學習內容，引導去學習
            sendText($chat_id, 
                "📚 請先完成「{$unit_name}：{$unit_description}」的學習內容再進行測驗！\n\n" .
                "🔍 您還有未完成的學習題目\n" .
                "請點選 /learn 繼續學習"
            );
            return;
        }
    }

    // 3. 找出第一個有未完成測驗的 unit
    $targetUnit = null;
    foreach ($allUnits as $unitId) {
        // 查詢該 unit 是否還有未完成的測驗
        $stmt = $pdo->prepare("
            SELECT q.* FROM questions q 
            LEFT JOIN question_results uq ON q.id = uq.question_id AND uq.telegram_id = ? 
            WHERE uq.id IS NULL AND q.unit_id = ?
            ORDER BY q.id 
            LIMIT 1
        ");
        $stmt->execute([$telegram_id, $unitId]);
        $unfinishedQuiz = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($unfinishedQuiz) {
            $targetUnit = $unitId;
            break;
        }
    }

    if ($targetUnit === null) {
        sendText($chat_id, "🎊 恭喜完成所有測驗題目！\n\n📊 你可以點選 /result 查看成績");
        return;
    }

    // 這裡，將規則顯示和開始測驗的邏輯交給另一個函式
    showQuizRules($chat_id, $targetUnit);
}

/* 顯示測驗規則並引導至測驗 */
function showQuizRules($chat_id, $targetUnit) {
    $rules = "📋 測驗規則說明\n\n" .
        "1️⃣ 本測驗共有 5 題，每題答對可獲得 10 點。\n" .
        "2️⃣ 答錯會扣 3 點，點數不會低於 0。\n" .
        "3️⃣ 全部答對將獲得額外 20 點獎勵！\n" .
        "4️⃣ 點數將持續累積。\n" .
        "5️⃣ 請注意每次只有一次的作答機會\n" .
        "\n💡 請仔細作答，祝你挑戰成功！";
    $buttons = [[
        ['text' => '進行測驗 ▶️', 'callback_data' => 'quiz|start|' . $targetUnit]
    ]];
    sendInlineKeyboard($chat_id, $rules, $buttons);
}

/*處理『進行測驗』按鈕 callback*/
function handleStartQuizButton($chat_id, $specific_unit) {
    global $pdo;
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 尚未登入，請先使用 /login 進行登入。");
        return;
    }
    $telegram_id = $user['telegram_id'];

    // 取得單元名稱與描述
    $stmt = $pdo->prepare("SELECT name, description FROM units WHERE id = ?");
    $stmt->execute([$specific_unit]);
    $unitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit_name = $unitInfo['name'] ?? "未知單元";
    $unit_description = $unitInfo['description'] ?? "";

    // 先判斷該 unit 的 lesson 是否全部完成
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lessons l 
        LEFT JOIN lesson_responses ul ON l.id = ul.lesson_id AND ul.telegram_id = ? 
        WHERE ul.id IS NULL AND l.unit_id = ?
    ");
    $stmt->execute([$telegram_id, $specific_unit]);
    $unfinishedLessons = $stmt->fetchColumn();

    if ($unfinishedLessons > 0) {
        sendText($chat_id, 
            "⚠️ 請先完成「{$unit_name}：{$unit_description}」的所有學習內容，才能進行測驗！\n\n" .
            "📚 您還有 {$unfinishedLessons} 題學習內容未完成\n" .
            "請點選 /learn 完成學習內容。"
        );
        return;
    }

    // 查詢該 unit_id 的未完成 quiz 題目
    $stmt = $pdo->prepare("
        SELECT q.* FROM questions q 
        LEFT JOIN question_results ua ON q.id = ua.question_id AND ua.telegram_id = ? 
        WHERE ua.id IS NULL AND q.unit_id = ?
        ORDER BY q.id 
        LIMIT 1
    ");
    $stmt->execute([$telegram_id, $specific_unit]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$question) {
        sendText($chat_id, "🎊 恭喜完成「{$unit_name}：{$unit_description}」的所有測驗題目！\n請等待下一個單元開放。");
        return;
    }

    // 獲取該單元的總題目數
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE unit_id = ?");
    $stmt->execute([$specific_unit]);
    $totalQuestions = $stmt->fetchColumn();
    
    // 顯示當前進度
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM question_results WHERE telegram_id = ? AND question_id IN (SELECT id FROM questions WHERE unit_id = ?)");
    $stmt->execute([$telegram_id, $specific_unit]);
    $completedCount = $stmt->fetchColumn();
    
    $progressText = "📌 測驗進度：" . ($completedCount + 1) . "/" . $totalQuestions . "\n\n";
    
    showQuizQuestion($chat_id, $question, $progressText);
}

/*顯示測驗題目*/
function showQuizQuestion($chat_id, $question, $progressText = '') {
    // 組合題目文字
    $questionText = $progressText . "第" . (is_array($question['title']) ? json_encode($question['title'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $question['title']) . "題"."\n\n";
    $questionText .= (is_array($question['question']) ? json_encode($question['question'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $question['question']) . "\n\n";
    
    // 如果有程式碼區塊，加入程式碼
    if (!empty($question['code_block'])) {
        $questionText .= "```python\n" . $question['code_block'] . "\n```\n\n";
    }
    
    // 檢查選項欄位是否存在，避免未定義錯誤
    $optionA = isset($question['option_a']) ? $question['option_a'] : '';
    $optionB = isset($question['option_b']) ? $question['option_b'] : '';
    $optionC = isset($question['option_c']) ? $question['option_c'] : '';
    $optionD = isset($question['option_d']) ? $question['option_d'] : '';

    // 判斷是否為多行（或疑似程式碼），決定排版
    function formatOption($label, $content) {
        if (strpos($content, "\n") !== false || strpos($content, ";") !== false || strpos($content, "print") !== false) {
            // 多行或像程式碼 → 用 code block
            return "{$label}.\n```python\n{$content}\n```\n";
        } else {
            // 單行純文字 → 直接顯示
            return "{$label}. {$content}\n";
        }
    }

    $questionText .= formatOption("A", $optionA);
    $questionText .= formatOption("B", $optionB);
    $questionText .= formatOption("C", $optionC);
    $questionText .= formatOption("D", $optionD);

    // 建立按鈕：AB 一排，CD 一排
    $buttons = [
        [
            ['text' => 'A', 'callback_data' => "quiz|{$question['id']}|A"],
            ['text' => 'B', 'callback_data' => "quiz|{$question['id']}|B"]
        ],
        [
            ['text' => 'C', 'callback_data' => "quiz|{$question['id']}|C"],
            ['text' => 'D', 'callback_data' => "quiz|{$question['id']}|D"]
        ]
    ];
    
    sendInlineKeyboard($chat_id, $questionText, $buttons);

    // 建立按鈕後送出前，記錄開始時間
    $user = getUserByTelegramId($chat_id);
    $telegram_id = $user['telegram_id'] ?? (string)$chat_id; // 後備用 chat_id
    timer_start($telegram_id, "quiz_start:{$question['id']}");
}

/*處理測驗作答結果*/
function handleQuizAnswer($chat_id, $question_id, $user_answer, $message_id = null, $callback_query_id = null) {
    global $pdo;

    //  先回收按鈕（保證按鈕消失，不管點幾次）
    if ($message_id) removeInlineKeyboard($chat_id, $message_id);
    if ($callback_query_id) answerCallback($callback_query_id);

    // 1️⃣ 取得用戶資訊
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 請先登入");
        return;
    }
    
    $telegram_id = $user['telegram_id'] ?? (string)$chat_id;
    if (!$telegram_id) { sendText($chat_id, "⚠️ 請先登入或綁定帳號"); return; }


    // 2️⃣ 判斷是否已作答 → 有就直接靜默 return
    $stmt = $pdo->prepare("SELECT 1 FROM question_results WHERE telegram_id = ? AND question_id = ? LIMIT 1");
    $stmt->execute([$telegram_id, $question_id]);
    if ($stmt->fetch()) {
        return; // 已作答過，不做任何事
    }

    // 先結束計時（秒）
    $elapsed = timer_stop($telegram_id, "quiz_start:{$question_id}");
    $rt_ms = is_numeric($elapsed) ? (int) round($elapsed) : null;
    
    // 3️⃣ 取得題目資料
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->execute([$question_id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$question) {
        sendText($chat_id, "❌ 找不到題目資料");
        return;
    }

    // 4️⃣ 取得單元資訊
    $current_unit_id = $question['unit_id'];
    $stmt = $pdo->prepare("SELECT name FROM units WHERE id = ?");
    $stmt->execute([$current_unit_id]);
    $unitInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit_name = $unitInfo['name'] ?? '';

    // 5️⃣ 判斷是否答對
    $is_correct = ($user_answer === $question['correct_option']);

    // 6️⃣ 點數邏輯
    if ($is_correct) {
        $current_score = addPoint($telegram_id, (int)$current_unit_id);
        sendText($chat_id, "🎉");
        $feedback = "🎉 答對了！ 答案是 {$question['correct_option']} 沒錯\n\n";
    } else {
        $current_score = deductPoint($telegram_id, (int)$current_unit_id);
        sendText($chat_id, "😅");
        $feedback = "😅 答錯了！ 正確答案是 {$question['correct_option']}\n\n";
    }

    // ✅ 在這裡重新查「總分」
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(score),0) FROM point WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $current_score = (int)$stmt->fetchColumn();

    $feedback .= "🪙 目前累積點數：{$current_score} 點\n\n";
    $feedback .= "💡 解析\n\n{$question['explanation']}";
    sendText($chat_id, $feedback);

    // 7️⃣ 記錄測驗結果，只寫一次
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO question_results
            (telegram_id, question_id, user_answer, is_correct, rt_ms, answered_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$telegram_id, $question_id, $user_answer, $is_correct ? 1 : 0, $rt_ms]);

    // 8️⃣ 檢查是否有下一題
    $stmt = $pdo->prepare("
        SELECT q.* FROM questions q 
        LEFT JOIN question_results ua ON q.id = ua.question_id AND ua.telegram_id = ? 
        WHERE ua.id IS NULL AND q.unit_id = ?
        ORDER BY q.id 
        LIMIT 1
    ");
    $stmt->execute([$telegram_id, $current_unit_id]);
    $nextQuestion = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($nextQuestion) {
        $buttons = [[
            ['text' => '下一題 ➡️', 'callback_data' => "quiz|start|{$current_unit_id}"]
        ]];
        sendInlineKeyboard($chat_id, "準備好繼續挑戰了嗎？", $buttons);
    } else {
        require_once(__DIR__ . '/learning.php');
        showUnitCompletionOptions($chat_id, $current_unit_id, 'quiz');
    }
}


/*記錄測驗結果到資料庫*/
function recordQuizResult($chat_id, $question_id, $user_answer, $is_correct) {
    global $pdo;
    
    // 取得用戶資訊
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        error_log("recordQuizResult: 找不到用戶 chat_id=$chat_id");
        return false;
    }
    
    $telegram_id = $user['telegram_id'] ?? $chat_id;
    
    // 驗證參數類型，防止陣列傳入
    if (is_array($question_id) || is_array($user_answer) || is_array($is_correct)) {
        error_log("recordQuizResult: 參數類型錯誤");
        return false;
    }
    
    $is_correct_val = $is_correct ? 1 : 0;
    
    try {
        // 嘗試插入，若已存在則忽略
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO question_results (telegram_id, question_id, user_answer, is_correct, answered_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$telegram_id, $question_id, $user_answer, $is_correct_val]);

        // rowCount() == 1 → 新增成功 (第一次作答)
        // rowCount() == 0 → 已存在紀錄，這次就被忽略，不覆蓋
        return $stmt->rowCount() === 1;

    } catch (PDOException $e) {
        error_log("Error recording quiz result: " . $e->getMessage() . 
                  " params: telegram_id={$telegram_id}, question_id={$question_id}, user_answer={$user_answer}, is_correct={$is_correct_val}");
        return false;
    }
}

/*檢查測驗進度（未使用的函數）*/
function checkQuizProgress($chat_id) {
    global $pdo;
    
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 請先登入");
        return;
    }
    
    $telegram_id = $user['telegram_id'];
    
    // 查詢下一題未作答的題目
    $stmt = $pdo->prepare("
        SELECT q.* FROM questions q 
        LEFT JOIN question_results ua ON q.id = ua.question_id AND ua.telegram_id = ? 
        WHERE ua.id IS NULL 
        ORDER BY q.title, q.id 
        LIMIT 1
    ");
    $stmt->execute([$telegram_id]);
    $nextQuestion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($nextQuestion) {
        $buttons = [[
            ['text' => '下一題 ➡️', 'callback_data' => "quiz|{$nextQuestion['id']}"]
        ]];
        sendInlineKeyboard($chat_id, ' ', $buttons);
    } else {
        sendText($chat_id, "🎊 恭喜完成所有測驗題目！\n\n📊 你可以點選 /result 查看成績");
    }
}
?>