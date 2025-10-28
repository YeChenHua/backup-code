<?php
/*真正跟 Telegram 溝通，負責「發送訊息、貼圖、編輯訊息、動畫」的函式*/

/*發送純文字訊息*/
function sendText($chat_id, $text, $keyboard = null) {
    $api = TELEGRAM_API_URL;

    if (is_array($text)) {
        $text = json_encode($text, JSON_UNESCAPED_UNICODE);
    }

    if (trim($text) === '') {
        $text = ' ';
    }

    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data),
            'ignore_errors' => true,
        ]
    ]);

    $result = @file_get_contents($api . "/sendMessage", false, $context);
    if ($result === false) {
        error_log("sendText 發送訊息失敗，chat_id={$chat_id}, text={$text}");
    }
    return $result;
}

/*發送帶有 inline keyboard 的訊息*/
function sendInlineKeyboard($chat_id, $text, $buttons) {
    $api = TELEGRAM_API_URL;
    
    // 處理陣列格式的文字
    if (is_array($text)) {
        $text = json_encode($text, JSON_UNESCAPED_UNICODE);
        error_log("sendInlineKeyboard: 將陣列轉字串 {$text}");
    }
    
    // 避免空訊息
    if (trim($text) === '') {
        $text = ' ';
    }
    
    // 準備 API 請求資料
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        'parse_mode' => 'Markdown'
    ];
    
    // 建立 HTTP 上下文
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ]);
    
    // 發送請求
    $result = @file_get_contents($api . "/sendMessage", false, $context);
    if ($result === false) {
        error_log("sendInlineKeyboard 發送訊息失敗，chat_id={$chat_id}，text={$text}");
    }
    return $result;
}

/*發送貼圖*/
function sendSticker($chat_id, $sticker_id) {
    $api = TELEGRAM_API_URL;
    
    // 準備 API 請求資料
    $data = ['chat_id' => $chat_id, 'sticker' => $sticker_id];
    
    // 建立 HTTP 上下文
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ]);
    
    // 發送請求
    $result = @file_get_contents($api . "/sendSticker", false, $context);
    if ($result === false) {
        error_log("sendSticker 發送貼圖失敗，chat_id={$chat_id}，sticker_id={$sticker_id}");
    }
    return $result;
}

/*回應 callback query（確認收到按鈕點擊）*/
function answerCallback($callback_id) {
    $api = TELEGRAM_API_URL;
    
    if (!empty($callback_id)) {
        $url = $api . "/answerCallbackQuery";
        $data = ['callback_query_id' => $callback_id];
        
        // 建立 HTTP 上下文
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true,
            ]
        ];
        
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            error_log("answerCallbackQuery API failed: callback_id={$callback_id}");
        }
        return $result;
    }
}

/* 發送圖片訊息（修正版：file_id 優先 + 本機/URL 皆可） */
function sendPhoto($chat_id, $photo, $caption = '', $keyboard = null, $parseMode = 'HTML') {
    $url = TELEGRAM_API_URL . "/sendPhoto";

    // 不要做過度的前置驗證（避免擋掉本機檔與合法的 file_id）
    // 統一先整理型別
    if (is_string($photo)) {
        $photo = trim($photo);
    }

    // 1) 判斷來源：本機檔 → URL → file_id
    if (is_string($photo) && is_readable($photo)) {
        // 本機檔要用 CURLFile，才能觸發 multipart 檔案上傳
        $post_fields = [
            'chat_id' => $chat_id,
            'photo'   => new CURLFile($photo),
        ];
    } elseif (is_string($photo) && preg_match('~^https?://~i', $photo)) {
        // 公開 URL；擋 localhost/127.0.0.1/::1（Telegram 抓不到）
        if (preg_match('~^https?://(?:localhost|127\.0\.0\.1|::1)(?:/|$)~i', $photo)) {
            error_log("sendPhoto blocked local URL: $photo");
            return json_encode(['ok' => false, 'description' => 'Local/loopback URL not accessible by Telegram']);
        }
        $post_fields = [
            'chat_id' => (string)$chat_id,
            'photo'   => $photo,
        ];
    } else {
        // 其餘情況一律當 file_id 使用（file_id 是不透明字串，不要自定格式驗證）
        if (empty($photo)) {
            error_log("sendPhoto invalid empty file_id");
            return json_encode(['ok' => false, 'description' => 'Empty file_id']);
        }
        $post_fields = [
            'chat_id' => (string)$chat_id,
            'photo'   => $photo, // file_id
        ];
    }

    // 2) caption（photo 限 ~1024 字；超出容易 400）
    if (is_string($caption) && $caption !== '') {
        if (mb_strlen($caption) > 1024) {
            $caption = mb_substr($caption, 0, 1019) . '...';
        }
        $post_fields['caption'] = $caption;
        $post_fields['parse_mode'] = $parseMode; // 需要可關閉就改 null
    }

    // 3) reply_markup
    if ($keyboard !== null) {
        $post_fields['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    // 4) cURL：不要手動設置 Content-Type，讓 cURL 自帶 boundary
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $output = curl_exec($ch);
    if ($output === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log('sendPhoto curl_exec failed: ' . $err);
        return json_encode(['ok' => false, 'description' => 'CURL error: ' . $err]);
    }
    curl_close($ch);

    // 5) 失敗時加強偵錯（區分來源類型）
    $resp = json_decode($output, true);
    if (empty($resp['ok'])) {
        $kind = (isset($post_fields['photo']) && $post_fields['photo'] instanceof CURLFile) ? 'CURLFile'
              : ((is_string($post_fields['photo']) && preg_match('~^https?://~i', $post_fields['photo'])) ? 'URL' : 'file_id');
        error_log("Telegram API error in sendPhoto(kind=$kind): " . $output);
    }

    return $output; // 保持與你原本函式相容
}


/*檢查是否為有效的 URL*/
function is_url($string) {
    return filter_var($string, FILTER_VALIDATE_URL) !== false;
}

/*檢查是否為有效的 Telegram file_id 格式*/
function is_valid_file_id($file_id) {
    // Telegram file_id 通常符合特定格式，長度通常在 20-200 字符之間
    // 並且包含字母、數字、下劃線、連字符等字符
    return preg_match('/^[A-Za-z0-9_-]{20,200}$/', $file_id);
}

/*編輯現有訊息*/
function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    $api = TELEGRAM_API_URL;
    
    if (is_array($text)) {
        $text = json_encode($text, JSON_UNESCAPED_UNICODE);
    }
    
    if (trim($text) === '') {
        $text = ' ';
    }
    
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    
    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ]);
    
    $result = @file_get_contents($api . "/editMessageText", false, $context);
    if ($result === false) {
        error_log("editMessage 編輯訊息失敗，chat_id={$chat_id}，message_id={$message_id}");
    }
    return $result;
}

function removeInlineKeyboard($chat_id, $message_id) {
    $api = TELEGRAM_API_URL;

    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'reply_markup' => ['inline_keyboard' => []] // ★ 關鍵：必須帶空鍵盤
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
        ]
    ]);

    $result = @file_get_contents($api . "/editMessageReplyMarkup", false, $context);
    if ($result === false) {
        error_log("removeInlineKeyboard 失敗，chat_id={$chat_id}, message_id={$message_id}");
    }
    return $result;
}

/*刪除訊息 #沒用到*/
function deleteMessage($chat_id, $message_id) {
    $api = TELEGRAM_API_URL;
    
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ]);
    
    $result = @file_get_contents($api . "/deleteMessage", false, $context);
    if ($result === false) {
        error_log("deleteMessage 刪除訊息失敗，chat_id={$chat_id}，message_id={$message_id}");
    }
    return $result;
}

/* 發送動畫（GIF）#沒用到*/
function sendAnimation($chat_id, $animation_url, $caption = '', $keyboard = null) {
    $api = TELEGRAM_API_URL . "/sendAnimation";

    $data = [
        'chat_id' => $chat_id,
        'animation' => $animation_url,
        'caption' => $caption
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true
        ]
    ];

    $context  = stream_context_create($options);
    $result = file_get_contents($api, false, $context);

    if ($result === false) {
        error_log("sendAnimation 發送動畫失敗，chat_id={$chat_id}，animation_url={$animation_url}");
    }

    return $result;
}

// ---- 一次性去重：避免同一事件被處理兩次 ----
if (!function_exists('dedupMessageScope')) {
function dedupMessageScope(string $telegram_id, string $scope): bool {
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO click_log (telegram_id, scope) VALUES (?, ?)');
        $stmt->execute([$telegram_id, $scope]);
        return $stmt->rowCount() > 0; // true=首次；false=已處理
    } catch (Throwable $e) {
        error_log('[DEDUP] ' . $e->getMessage());
        // 出錯時不擋流程（避免影響主功能），也可以改成 return false;
        return true;
    }
}}

?>