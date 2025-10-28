<?php
error_reporting(E_ALL); // 顯示所有錯誤
ini_set('display_errors', 1); // 在開發環境中顯示錯誤

//正式測驗要改為 0

// 引入必要的模組
require_once 'config.php';          // 配置設定
require_once 'database.php';        // 資料庫連接
require_once 'telegram_api.php';    // Telegram API 功能
require_once 'user_manager.php';    // 用戶管理功能
require_once 'learning.php';        // 學習功能
require_once 'quiz.php';            // 測驗功能
require_once 'point.php';           // 點數功能
require_once 'login.php';           // 登入功能
require_once 'result.php';          // 成績功能
require_once 'completed.php';      // 完成名單功能


try {
    // 從 Telegram 發送到 Webhook URL 的 POST 請求中，讀取原始資料
    $content = file_get_contents("php://input");
    
    // 將 JSON 資料解碼為 PHP 陣列
    $update = json_decode($content, true);

    // 檢查資料是否存在且格式正確
    if ($update) {
        // 呼叫核心處理函式
        processUpdate($update);
    } else {
        // 如果請求內容為空或格式錯誤，記錄錯誤
        error_log("接收到無效的 Webhook 請求: " . $content);
    }
    
} catch (Exception $e) {
    // 如果處理更新時發生致命錯誤，記錄下來
    error_log("Webhook 處理錯誤: " . $e->getMessage());
}

// 處理 Telegram 更新訊息
function processUpdate($update) {
        // 資料庫連線
        global $pdo;
        
       try {
            // 先判斷按鈕（callback）
            if (isset($update['callback_query'])) {
                handleCallback($update['callback_query']);
                return; // <== 收尾
            }

            // 再判斷一般訊息（message）
            if (isset($update['message'])) {
                $message = $update['message'];
                $chat_id = $message['chat']['id'];
                $text    = trim($message['text'] ?? '');

                handleCommand($chat_id, $text, $message); // 第三參數保持傳 $message
                return; // <== 收尾
            }

            // 其他型別（edited_message, channel_post...）視需求擴充
        } catch (Throwable $e) { // <== 改成 Throwable
            error_log('處理更新時發生錯誤: ' . $e->getMessage());
            $chatForError =
                $update['message']['chat']['id'] ??
                ($update['callback_query']['message']['chat']['id'] ?? null);
        }
}

// 處理 Telegram 回傳的按鈕點擊事件(全部按鈕)
function handleCallback($callback) {
    global $pdo;

    $msg = $callback['message'] ?? null;
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id']; 
    $callback_id = $callback['id'];
    $data_raw = $callback['data'];

    // ----------------------------------------------------
    // A) 原子去重（同一則訊息只處理第一次）
    //    想改成「每顆按鈕只能各點一次」→ 把 scope 換為：
    //    $scope = "btn:{$message_id}:{$data_raw}";
    // ----------------------------------------------------
    $scope = "btn:{$message_id}:{$data_raw}";

    if ($chat_id && $message_id) {
        // 先插 click_log 嘗試去重
        $stmt = $pdo->prepare("INSERT IGNORE INTO click_log (telegram_id, scope) VALUES (?, ?)");
        $stmt->execute([$chat_id, $scope]);

        // 插不進去 = 已處理過 → 只停止 loading，直接結束
        if ($stmt->rowCount() === 0) {
            if ($callback_id) answerCallback($callback_id, '', false);
            http_response_code(200);
            echo 'OK';
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            return; // ★★★ 關鍵：一定要結束，不要再往下跑
        }
    }

    // ----------------------------------------------------
    // B) 第一次才會跑到這：先收鍵盤、停 loading、回 200
    // ----------------------------------------------------
    if ($chat_id && $message_id) removeInlineKeyboard($chat_id, $message_id);
    if ($callback_id) answerCallback($callback_id, '', false);

    http_response_code(200);
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // 非 FPM 的保險處理
        while (ob_get_level()) { ob_end_clean(); }
        header('Connection: close');
        header('Content-Length: 2');
        flush();
    }

    // 檢查是否是 command_ 開頭的按鈕
    if (strpos($data_raw, 'command_') === 0) {
        $command = substr($data_raw, 8);
        handleCommandButton($chat_id, $command);
        return;
    }

    // 處理其他格式的按鈕（包含 | 分隔符的）
    $data = explode('|', $data_raw);
    
    if (count($data) < 2) {
        sendText($chat_id, "❌ 按鈕資料格式錯誤");
        error_log("錯誤按鈕回傳格式: " . $data_raw);
        return;
    }

    $first_part = $data[0];
    switch ($first_part) {
        case 'learn':
            $GLOBALS['__via'] = 'callback:learn_pipe';
            startLearning($chat_id, null, ['prefer_edit' => true, 'message_id' => $message_id]);
            break;
        case 'quiz':
            // 處理 quiz 按鈕
            $sub_action = $data[1] ?? null;
            
            if ($sub_action === 'show_rule') {
                $specific_unit = (int)$data[2];
                showQuizRules($chat_id, $specific_unit);
            } else if ($sub_action === 'start') {
                $specific_unit = (int)$data[2];
                handleStartQuizButton($chat_id, $specific_unit);
            } else {
                // 處理測驗作答按鈕： quiz|question_id|user_answer
                $question_id = $sub_action;
                $user_answer = $data[2] ?? null;

                if ($question_id !== null && $user_answer !== null) {
                    // 將所有參數傳遞給 handleQuizAnswer
                    handleQuizAnswer($chat_id, $question_id, $user_answer, $message_id, $callback_id);
                } else {
                    sendText($chat_id, "❌ 測驗按鈕資料格式錯誤");
                }
            }
            break;
        case 'learn_question':
            if (isset($data[1])) {
                $lesson_id = (int)$data[1];
                $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
                $stmt->execute([$lesson_id]);
                $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($lesson) {
                    showLearningQuestion($chat_id, $lesson);
                } else {
                    sendText($chat_id, "❌ 找不到問題資料，請聯絡管理員");
                    error_log("找不到 lesson_id: " . $lesson_id);
                }
            } else {
                sendText($chat_id, "❌ 缺少問題參數");
            }
            break;
        case 'learn_answer':
            if (count($data) >= 3) {
                handleLearningAnswer($chat_id, $data[1], $data[2], $message_id);
            } else {
                sendText($chat_id, "❌ 缺少參數");
            }
            break;
        case 'continue_learning':
            if (isset($data[1])) {
                continueToNextLesson($chat_id, $data[1], $message_id);
            } else {
                sendText($chat_id, "❌ 缺少參數");
            }
            break;
        default:
            sendText($chat_id, "❌ 未知的按鈕操作");
            error_log("未知的回傳動作: " . $first_part);
    }
}

// 處理按鈕型指令
function handleCommandButton($chat_id, $command) {
    // 處理其他格式的按鈕（包含 | 分隔符的）
    $parts = explode('|', $command);
    $main_command = $parts[0];
    $parameter = isset($parts[1]) ? $parts[1] : null;

    switch ($main_command) {
        case 'login':
            startLogin($chat_id);
            break;
        case 'learn':
            $GLOBALS['__via'] = 'callback:command_learn';
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                $unit_id = $parameter ? (int)$parameter : null;
                startLearning($chat_id, $unit_id);
            }
            break;
        case 'quiz':
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                $unit_id = $parameter ? (int)$parameter : null;
                startQuiz($chat_id, $unit_id);
            }
            break;
        case 'history':
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                showHistory($chat_id);
            }
            break;
        case 'done':  // 👈 新增這個
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                cmdDone($chat_id); // 呼叫剛剛寫好的 /done 功能
            }
            break;
        default:
            sendText($chat_id, "❌ 未知的指令");
            error_log("未知的指令按鈕: " . $command);
    }
}

/*處理用戶在特殊狀態中的輸入（登入 / 綁定流程）*/
if (!function_exists('handleUserState')) {
    function handleUserState($chat_id, $text, $state) {
    switch ($state['step'] ?? '') {
        case 'login_username':
            handleLoginUsername($chat_id, $text);
            return;

        // 舊流程相容（如果曾經用過）
        case 'login_wait_sid':
            handleLoginUsername($chat_id, $text);
            return;
        case 'login_password':
            clearUserState($chat_id);
            sendText($chat_id, "登入流程已簡化：只需輸入學號。\n請輸入 /login 重新開始。");
            return;

        default:
            clearUserState($chat_id);
            sendText($chat_id, "⚠️ 未知的操作狀態，已重置。\n輸入 /login 開始登入。");
            return;
    }
}

}

/*使用者輸入內容*/
function handleUserInput($message) {
    if (!$message || !isset($message['chat']['id'])) return;

    $chat_id = $message['chat']['id'];

    if (isset($message['text'])) {
        sendText($chat_id, "📄 你輸入了:" . $message['text']);
    } elseif (isset($message['sticker'])) {
        sendText($chat_id, "📄 你傳送了:貼圖");
    } elseif (isset($message['animation'])) {
        sendText($chat_id, "📄 你傳送了:動畫");
    } elseif (isset($message['video'])) {
        sendText($chat_id, "📄 你傳送了:影片");
    } elseif (isset($message['document'])) {
        sendText($chat_id, "📄 你傳送了:文件");
    } elseif (isset($message['audio'])) {
        sendText($chat_id, "📄 你傳送了:音訊");
    } elseif (isset($message['voice'])) {
        sendText($chat_id, "📄 你傳送了:語音訊息");
    } elseif (isset($message['contact'])) {
        sendText($chat_id, "📄 你傳送了:聯絡資訊");
    } elseif (isset($message['location'])) {
        sendText($chat_id, "📄 你傳送了:位置訊息");
    } elseif (isset($message['venue'])) {
        sendText($chat_id, "📄 你傳送了:地點訊息");
    } elseif (isset($message['photo'])) {
        sendText($chat_id, "📄 你傳送了:照片");
    } elseif (isset($message['poll'])) {
        sendText($chat_id, "📄 你傳送了:投票");
    } elseif (isset($message['dice'])) {
        sendText($chat_id, "📄 你傳送了:骰子");
    } else {
        sendText($chat_id, "📄 你輸入了:未知內容");
    }
}

/**
 * 處理一般 Bot 指令
 */
function handleCommand($chat_id, $text = null, $message = null) {
    global $pdo;

    // 防呆：若呼叫端沒傳 $text，自己從 $message 補
    if ($text === null && is_array($message)) {
        $text = trim($message['text'] ?? '');
    }
    if ($text === null) { $text = ''; } // 確保變數已定義

    // 這裡才寫你的 /learn 判斷
    if ($text === '/learn' || $text === 'learn') {
        $mid = $message['message_id'] ?? null;
        if ($mid !== null && function_exists('dedupMessageScope')) {
            $scope = "cmd:{$mid}:/learn";
            if (!dedupMessageScope((string)$chat_id, $scope)) {
                return; // 同一則 /learn 已處理過
            }
        }
        startLearning($chat_id);
        return;
    }

    // A) 讀 state（穩定判斷：看 step 有沒有值）
    $user_state = getUserState($chat_id) ?: [];
    $has_step = isset($user_state['step']) && $user_state['step'] !== '';

    if ($has_step) {
        handleUserState($chat_id, $text, $user_state);
        return;
    }

    // B) 未登入導流：沒有 state、也還沒登入 → 先處理學號或引導進登入狀態
    if (!isUserLoggedIn($chat_id)) {
        // 不是指令（不以 / 開頭），且有文字輸入
        if ($text !== '' && $text[0] !== '/') {
            // 長得像學號就直接當學號綁定（規則你可調，這裡示範 5~20 碼數字）
            if (preg_match('/^\d{8}$/', $text)) {
                if (function_exists('handleLoginUsername')) {
                    handleLoginUsername($chat_id, $text);
                } else if (function_exists('handleLoginText')) {
                    handleLoginText($chat_id, $text);
                } else {
                    sendText($chat_id, "請輸入 /login 開始登入。");
                }
                return;
            }
            // 不是學號 → 引導進登入狀態（會 setState 並提示輸入學號）
            if (function_exists('startLogin')) {
                startLogin($chat_id, null);
            } else {
                sendText($chat_id, "請輸入 /login，然後依指示輸入學號完成登入。");
            }
            return;
        }
    }

    $parts = explode(' ', $text);
    $command = strtolower($parts[0]);
    $args = array_slice($parts, 1);

    switch ($command) {
        case '/start':
            sendWelcomeMessage($chat_id);
            break;
        case '/help':
            sendHelpMessage($chat_id);
            break;
        case '/login':
            startLogin($chat_id, $text);
            break;
        case '/learn':
            $GLOBALS['__via'] = 'command:/learn';
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                startLearning($chat_id);
            }
            break;
        case '/quiz':
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                startQuiz($chat_id);
            }
            break;
        case '/history':
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                showHistory($chat_id);
            }
            break;
        case '/qrcode':
            // 處理新的 /qrcode 指令
            $urlToEncode = isset($args[0]) ? $args[0] : "https://t.me/CodeWarmBot"; // 如果沒有指定 URL，則使用預設值
            
            // 驗證 URL 格式
            if (filter_var($urlToEncode, FILTER_VALIDATE_URL) === FALSE) {
                sendText($chat_id, "請提供有效的網址來生成 QR Code。例如: /qrcode https://www.google.com");
            } else {
                // 使用 Google Chart API 或其他 QR Code 服務來建立圖片 URL
                $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($urlToEncode);
                
                // 傳送 QR Code 圖片和連結文字
                $caption = $urlToEncode . "\n掃描圖片或點擊連結前往";
                sendPhoto($chat_id, $qrImageUrl, $caption);
            }
            break;
        case '/done':
            if (!isUserLoggedIn($chat_id)) {
                sendLoginRequired($chat_id);
            } else {
                cmdDone($chat_id);
            }
            break;
        default:
            if ($message) {
                handleUserInput($message);
            }
            break;
        }
}

// 歡迎訊息
function sendWelcomeMessage($chat_id) {
    $welcomeText = "👋 歡迎使用 CodeWarm！\n\n";
    $welcomeText .= "可點選 /help 查看所有指令";

    sendText($chat_id, $welcomeText);
}

function sendHelpMessage($chat_id) {
    // 用既有的登入判斷，避免每次都查整筆
    $loggedIn = isUserLoggedIn($chat_id);

    if (!$loggedIn) {
        // 未登入：只提供登入相關說明與按鈕
        $helpText = "📘 指令列表\n\n"
                  . "請先登入帳號點選以下按鈕。\n\n";
        $keyboard = [
            'inline_keyboard' => [
                [ ['text' => '🔑 登入帳號', 'callback_data' => 'command_login'] ],
            ]
        ];
        sendText($chat_id, $helpText, $keyboard);
        return;
    }

    // 已登入：顯示完整功能清單與按鈕
    // （可選）帶出學號/姓名
    global $pdo;
    $q = $pdo->prepare("SELECT username, full_name FROM users WHERE telegram_id = :tid LIMIT 1");
    $q->execute([':tid' => (string)$chat_id]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    $who = $u ? ($u['full_name'] ? "{$u['username']}（{$u['full_name']}）" : $u['username']) : '已登入';

    $helpText = "📘 指令列表\n\n請點擊以下按鈕執行對應指令";

    $keyboard = [
        'inline_keyboard' => [
            [ ['text' => '開始學習', 'callback_data' => 'command_learn'] ],
            //[ ['text' => '進行測驗', 'callback_data' => 'command_quiz'] ],
            [ ['text' => '歷史紀錄', 'callback_data' => 'command_history'] ],
            [ ['text' => '完成名單', 'callback_data' => 'command_done'] ],
        ]
    ];
    sendText($chat_id, $helpText, $keyboard);
}


// 發送登入要求訊息
function sendLoginRequired($chat_id) {
    $loginText = "⚠️ 尚未登入，請先使用 /login 進行登入。";
    sendText($chat_id, $loginText);
}

// PHP 錯誤與異常處理
function handleError($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
    return false;
}

function handleException($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
}

set_error_handler('handleError');
set_exception_handler('handleException');

?>