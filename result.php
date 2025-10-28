<?php
/* 檢查資料表是否有指定欄位 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/* 將長訊息分割成多個部分 */
function sendLongText($chat_id, $text, $max_length = 4096) {
    // 根據換行符號將訊息分割成行
    $lines = explode("\n", $text);
    $current_message = "";

    foreach ($lines as $line) {
        // 如果加入這行會超過長度限制
        if (mb_strlen($current_message . $line . "\n", 'UTF-8') > $max_length) {
            if (!empty($current_message)) {
                sendText($chat_id, $current_message);
                sleep(1); // 確保每個部分都獨立發送
                $current_message = "";
            }
        }
        $current_message .= $line . "\n";
    }

    // 發送最後一個部分
    if (!empty($current_message)) {
        sendText($chat_id, $current_message);
    }
}


/* 按照指定版型顯示錯題歷史 (批次版) */
function showHistory($chat_id, $offset = 0) {
    global $pdo;
    
    // 1) 登入檢查
    $user = getUserByTelegramId($chat_id);
    if (!$user) {
        sendText($chat_id, "⚠️ 尚未登入，請先使用 /login 進行登入。");
        return;
    }
    $telegram_id = (string)$user['telegram_id'];

    // 2) 只有在第一次呼叫時發送總覽報告
    if ($offset == 0) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(score),0) FROM point WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $points = $stmt->fetchColumn() ?? 0;

        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT q.unit_id) 
            FROM question_results qr
            JOIN questions q ON qr.question_id = q.id
            WHERE qr.telegram_id = ?
        ");
        $stmt->execute([$telegram_id]);
        $completedQuizzes = $stmt->fetchColumn() ?? 0;
        
        sendText($chat_id, "📊 你的測驗總覽\n\n• 累積點數：{$points} 點\n• 已完成單元數：{$completedQuizzes} 個\n");
        sleep(1); // 確保總覽訊息獨立
    }

    // 3) 準備查詢語句 - 錯題的 Lessons
    $sqlWrongLessons = "
        SELECT 
            l.id AS lesson_id,
            l.title,
            lr.user_answer,
            l.question,
            l.correct_option,
            l.option_a,
            l.option_b,
            l.option_c,
            l.option_d
        FROM lesson_responses lr
        JOIN lessons l ON l.id = lr.lesson_id
        WHERE lr.telegram_id = ? AND l.unit_id = ? AND (lr.is_correct = 0 OR lr.is_correct IS NULL)
        ORDER BY l.title ASC
    ";

    // 4) 準備查詢語句 - 錯題的 Questions
    $sqlWrongQuestions = "
        SELECT
            q.id AS question_id,
            q.title,
            qr.user_answer,
            q.question AS question_text,
            q.correct_option,
            q.option_a,
            q.option_b,
            q.option_c,
            q.option_d
        FROM question_results qr
        JOIN questions q ON q.id = qr.question_id
        WHERE qr.telegram_id = ? AND q.unit_id = ? AND (qr.is_correct = 0 OR qr.is_correct IS NULL)
        ORDER BY q.title ASC
    ";

    $stmtWrongLessons = $pdo->prepare($sqlWrongLessons);
    $stmtWrongQuestions = $pdo->prepare($sqlWrongQuestions);

    // 5) 批次處理邏輯
    $limit = 5; // 每次處理 5 個單元
    $messageSent = false;
    
    // 找出有錯題的單元 (更穩定的批次查詢方式)
    $sqlUnits = "
        SELECT u.id AS unit_id, u.name AS unit_name, u.order_index
        FROM lesson_responses lr
        JOIN lessons l ON l.id = lr.lesson_id
        JOIN units u ON u.id = l.unit_id
        WHERE lr.telegram_id = ? AND (lr.is_correct = 0 OR lr.is_correct IS NULL)
        GROUP BY u.id
        UNION
        SELECT u.id AS unit_id, u.name AS unit_name, u.order_index
        FROM question_results qr
        JOIN questions q ON q.id = qr.question_id
        JOIN units u ON u.id = q.unit_id
        WHERE qr.telegram_id = ? AND (qr.is_correct = 0 OR qr.is_correct IS NULL)
        GROUP BY u.id
        ORDER BY order_index ASC, unit_id ASC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmtU = $pdo->prepare($sqlUnits);
    $stmtU->execute([$telegram_id, $telegram_id]);
    $units = $stmtU->fetchAll(PDO::FETCH_ASSOC);

    // 5.2) 逐單元處理並發送訊息
    foreach ($units as $u) {
        $unitId = (int)$u['unit_id'];
        $unitName = $u['unit_name'] ?? "";
        
        $numberToChinese = [
            1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五',
            6 => '六', 7 => '七', 8 => '八', 9 => '九', 10 => '十'
        ];
        $chineseNumber = $numberToChinese[$unitId] ?? $unitId;
        $unitDisplayName = !empty($unitName) ? $unitName : "第{$chineseNumber}單元";
        
        $msg = "📝 作答錯誤歷史\n\n📚 單元：{$unitDisplayName}\n\n";
        $hasWrongItems = false;

        // 處理學習錯題
        $stmtWrongLessons->execute([$telegram_id, $unitId]);
        $wrongLessons = $stmtWrongLessons->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($wrongLessons)) {
            $msg .= "📖 學習(錯題清單)\n";
            foreach ($wrongLessons as $lesson) {
                $lessonNum = $lesson['title'] ?? ($lesson['id'] ?? '??');
                $userAnswer = strtoupper($lesson['user_answer'] ?? '');
                
                $msg .= "— — — — —\n";
                $msg .= "第 {$lessonNum} 題\n";
                
                if (isset($lesson['question']) && !empty(trim($lesson['question']))) {
                    $msg .= "題目：".trim($lesson['question'])."\n";
                }
                
                $options = [
                    'A' => $lesson['option_a'] ?? '', 'B' => $lesson['option_b'] ?? '',
                    'C' => $lesson['option_c'] ?? '', 'D' => $lesson['option_d'] ?? ''
                ];
                
                $userAnswerText = $options[$userAnswer] ?? '';
                $msg .= "你的答案：{$userAnswer}";
                if ($userAnswerText) $msg .= "．{$userAnswerText}";
                $msg .= "\n";
                
                $correctKey = strtoupper($lesson['correct_option'] ?? '');
                $correctText = $options[$correctKey] ?? '';
                $msg .= "正確答案：{$correctKey}";
                if ($correctText) $msg .= "．{$correctText}";
                $msg .= "\n";
            }
            $hasWrongItems = true;
        }

        // 處理測驗錯題
        $stmtWrongQuestions->execute([$telegram_id, $unitId]);
        $wrongQuestions = $stmtWrongQuestions->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($wrongQuestions)) {
            if ($hasWrongItems) {
                $msg .= "\n";
            }
            $msg .= "🧪 測驗(錯題清單)\n";
            foreach ($wrongQuestions as $question) {
                $questionNum = $question['title'] ?? ($question['id'] ?? '??');
                $userAnswer = strtoupper($question['user_answer'] ?? '');
                
                $msg .= "— — — — —\n";
                $msg .= "第 {$questionNum} 題\n";
                
                if (isset($question['question_text']) && !empty(trim($question['question_text']))) {
                    $msg .= "題目：".trim($question['question_text'])."\n";
                }
                
                $options = [
                    'A' => $question['option_a'] ?? '', 'B' => $question['option_b'] ?? '',
                    'C' => $question['option_c'] ?? '', 'D' => $question['option_d'] ?? ''
                ];
                
                $userAnswerText = $options[$userAnswer] ?? '';
                $msg .= "你的答案：{$userAnswer}";
                if ($userAnswerText) $msg .= "．{$userAnswerText}";
                $msg .= "\n";
                
                $correctKey = strtoupper($question['correct_option'] ?? '');
                $correctText = $options[$correctKey] ?? '';
                $msg .= "正確答案：{$correctKey}";
                if ($correctText) $msg .= "．{$correctText}";
                $msg .= "\n";
            }
            $hasWrongItems = true;
        }

        if ($hasWrongItems) {
            sendLongText($chat_id, $msg);
            $messageSent = true;
        }
    }
    
    // 如果還有其他單元，提供下一批次的指令
    $nextOffset = $offset + $limit;
    $totalCountStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT unit_id) FROM (
            SELECT l.unit_id FROM lesson_responses lr JOIN lessons l ON l.id = lr.lesson_id WHERE lr.telegram_id = ? AND (lr.is_correct = 0 OR lr.is_correct IS NULL)
            UNION ALL
            SELECT q.unit_id FROM question_results qr JOIN questions q ON q.id = qr.question_id WHERE qr.telegram_id = ? AND (qr.is_correct = 0 OR qr.is_correct IS NULL)
        ) AS all_wrong_units;
    ");
    $totalCountStmt->execute([$telegram_id, $telegram_id]);
    $totalUnits = $totalCountStmt->fetchColumn();

    if ($nextOffset < $totalUnits) {
        $remaining = $totalUnits - $nextOffset;
        sendText($chat_id, "還有 {$remaining} 個單元待顯示。請輸入 `/history {$nextOffset}` 以繼續顯示。");
    } else if (!$messageSent && $offset == 0) {
        sendText($chat_id, "🎉 恭喜！目前沒有任何錯題！");
    }
}
?>