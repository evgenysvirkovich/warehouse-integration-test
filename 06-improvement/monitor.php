<?php

declare(strict_types=1);

/**
 * Прототип мониторинга обмена с 1С (Task 06)
 * Запуск: php monitor.php
 * Требует PHP 8.1+
 */

// --- Конфигурация ---
$config = [
    'max_delay_minutes' => 15,
    'telegram_chat_id' => '-1001234567890',
    'telegram_bot_token' => 'YOUR_BOT_TOKEN',
    'log_file_path' => __DIR__ . '/mock_exchange.log',
    // В реальном проекте здесь будет URL вашего LLM-шлюза
    'ai_api_url' => 'https://api.openai.com/v1/chat/completions', 
    'ai_api_key' => 'sk-xxxxxxxxxxxxxxxx',
];

// --- Эмуляция данных (в реальности берется из БД или файла) ---
function getLastExchangeTime(): DateTimeImmutable {
    // Эмуляция: последний обмен был 25 минут назад (сработает триггер)
    return new DateTimeImmutable('-25 minutes');
}

function getRecentErrorLog(string $filePath): string {
    // Эмуляция чтения последних строк лога
    return implode("\n", [
        "[2023-10-23 10:00:01] INFO: Начало синхронизации заказов.",
        "[2023-10-23 10:00:05] ERROR: PDOException: SQLSTATE[HY000] [2002] Connection refused",
        "[2023-10-23 10:00:05] ERROR: Не удалось подключиться к базе данных 1С по адресу 192.168.1.50:5432",
        "[2023-10-23 10:00:06] FATAL: Обмен прерван. Код завершения: 1",
    ]);
}

// --- ИИ-суммаризация (Прототип вызова API) ---
function summarizeLogWithAI(string $logSnippet, string $apiKey, string $apiUrl): string {
    $prompt = "Ты — опытный инженер технической поддержки. Проанализируй этот фрагмент лога ошибки обмена 1С. " .
              "Ответь кратко (максимум 3 предложения): 1) Суть проблемы, 2) Вероятная причина, 3) Первые 2 шага для диагностики. " .
              "Текст лога:\n" . $logSnippet;

    $payload = [
        'model' => 'gpt-4o-mini', // или локальная модель
        'messages' => [
            ['role' => 'system', 'content' => 'Ты полезный ассистент по диагностике IT-инцидентов. Отвечай по-русски, кратко и по делу.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 150,
    ];

    // В реальном прототипе здесь был бы curl_exec к $apiUrl с заголовком "Authorization: Bearer $apiKey"
    // Для демонстрации возвращаем эмулированный ответ ИИ:
    
    /* 
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = json_decode(curl_exec($ch), true);
    return $response['choices'][0]['message']['content'] ?? 'Ошибка анализа ИИ';
    */

    return "🤖 **Анализ ИИ:**\n" .
           "• **Суть:** Отказ в подключении к базе данных (Connection refused).\n" .
           "• **Причина:** Сервер БД (192.168.1.50) недоступен по порту 5432. Возможно, служба PostgreSQL остановлена или блокируется фаерволом.\n" .
           "• **Действия:** 1) Проверить статус службы PostgreSQL на сервере 1С. 2) Проверить сетевую связность (ping/telnet) с узла интеграции.";
}

// --- Отправка в Telegram ---
function sendTelegramAlert(string $message, string $chatId, string $botToken): void {
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown',
    ];
    
    // Эмуляция отправки (в реальности раскомментировать curl)
    echo "📤 Отправка уведомления в Telegram...\n";
    echo "----------------------------------------\n";
    echo $message . "\n";
    echo "----------------------------------------\n";
    
    /*
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    $result = curl_exec($ch);
    curl_close($ch);
    */
}

// --- Основная логика ---
echo "🔄 Запуск проверки обмена 1С...\n";

$lastExchange = getLastExchangeTime();
$now = new DateTimeImmutable();
$delayInterval = $now->diff($lastExchange);
$delayMinutes = ($delayInterval->days * 24 * 60) + ($delayInterval->h * 60) + $delayInterval->i;

echo "Последний обмен: " . $lastExchange->format('Y-m-d H:i:s') . "\n";
echo "Текущая задержка: {$delayMinutes} мин.\n";

if ($delayMinutes > $config['max_delay_minutes']) {
    echo "⚠️ Превышен порог задержки ({$config['max_delay_minutes']} мин). Инициируем диагностику...\n";
    
    $logSnippet = getRecentErrorLog($config['log_file_path']);
    $aiSummary = summarizeLogWithAI($logSnippet, $config['ai_api_key'], $config['ai_api_url']);
    
    $alertMessage = sprintf(
        "🚨 **ВНИМАНИЕ: Сбой обмена с 1С**\n" .
        "⏱ Простой: **%d минут**\n" .
        "🕒 Последний успешный обмен: %s\n\n" .
        "%s\n\n" .
        "🔗 [Посмотреть полный лог](http://internal-logs.mertrade.local/exchange/2023-10-23.log)",
        $delayMinutes,
        $lastExchange->format('H:i:s'),
        $aiSummary
    );
    
    sendTelegramAlert($alertMessage, $config['telegram_chat_id'], $config['telegram_bot_token']);
} else {
    echo "✅ Обмен работает в штатном режиме.\n";
}
