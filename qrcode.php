<?php
// 設定 Telegram Bot 的連結
$botUsername = "CodeWarmBot"; // 不加 @ 也可以
$telegramLink = "https://t.me/$botUsername";

// 將 Telegram 連結編碼為 QR Code 圖片 URL（使用 Google Chart API）
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($telegramLink);

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>Telegram Bot</title>
    <style>
        body {
            font-family: "Noto Sans TC", sans-serif;
            background-color: #f4f4f4;
            text-align: center;
            padding: 50px;
        }
        .qrcode-container {
            background-color: #fff;
            padding: 20px;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .qrcode-container img {
            width: 300px;
            height: 300px;
        }
        .btn {
            margin-top: 20px;
            padding: 10px 25px;
            background-color: #0088cc;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            text-decoration: none;
        }
        .btn:hover {
            background-color: #006fa1;
        }
    </style>
</head>
<body>

<div class="qrcode-container">
    <h2>📱 掃描 QR Code 加入 Telegram Bot</h2>
    <img src="<?= $qrUrl ?>" alt="Telegram QR Code">
    <p>或點擊以下按鈕直接前往</p>
    <a class="btn" href="<?= $telegramLink ?>" target="_blank">前往 @<?= $botUsername ?></a>
</div>

</body>
</html>