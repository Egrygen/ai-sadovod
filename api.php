<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function send_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, [
        'success' => false,
        'error' => 'Разрешён только POST-запрос.'
    ]);
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    send_json(500, [
        'success' => false,
        'error' => 'На сервере отсутствует config.php.'
    ]);
}

$config = require $configFile;
$apiKey = trim((string)($config['groq_api_key'] ?? ''));

if ($apiKey === '' || str_contains($apiKey, 'PASTE_YOUR')) {
    send_json(500, [
        'success' => false,
        'error' => 'Не настроен ключ Groq в config.php.'
    ]);
}

$body = file_get_contents('php://input');
$data = json_decode($body ?: '', true);

if (!is_array($data) || empty($data['garden_image'])) {
    send_json(400, [
        'success' => false,
        'error' => 'Фотография не получена.'
    ]);
}

$base64Image = (string)$data['garden_image'];

// script.js отправляет уже очищенный Base64 без префикса data:image/...
if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $base64Image)) {
    send_json(400, [
        'success' => false,
        'error' => 'Получены повреждённые данные изображения.'
    ]);
}

// Groq сейчас ограничивает запрос с изображением 20 МБ.
// Проверяем с запасом, чтобы не отправлять заведомо слишком большой запрос.
if (strlen($base64Image) > 16 * 1024 * 1024) {
    send_json(413, [
        'success' => false,
        'error' => 'Фотография слишком большая для анализа.'
    ]);
}

$prompt = <<<'PROMPT'
Ты опытный агроном и помощник садовода. Изучи фото растения очень внимательно.

Определи:
1. название растения;
2. видимую болезнь, вредителя или другую проблему;
3. что дачнику сделать прямо сейчас.

Не выдавай предположение за достоверный диагноз. Если по фотографии нельзя надёжно определить растение или проблему, прямо укажи это и объясни, какое дополнительное фото было бы полезно.

Ответ должен быть строго в формате JSON (JavaScript Object Notation) и содержать ровно три поля:
{
  "name": "Название растения на русском языке",
  "disease": "Болезнь, вредитель, повреждение или 'Не удалось определить'",
  "action": "Понятные практические рекомендации для дачника"
}
PROMPT;

$groqPayload = [
    'model' => 'qwen/qwen3.8-27b',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $prompt
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:image/jpeg;base64,' . $base64Image
                    ]
                ]
            ]
        ]
    ],
    'temperature' => 0.1,
    'response_format' => [
        'type' => 'json_object'
    ],
    'reasoning_effort' => 'none'
];

$postFields = json_encode($groqPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($postFields === false) {
    send_json(500, [
        'success' => false,
        'error' => 'Не удалось сформировать запрос к Groq.'
    ]);
}

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 90,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    send_json(502, [
        'success' => false,
        'error' => 'Не удалось связаться с Groq: ' . ($curlError ?: 'неизвестная ошибка')
    ]);
}

$groqResponse = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    $message = $groqResponse['error']['message'] ?? 'Groq вернул ошибку.';
    send_json($httpCode >= 400 ? $httpCode : 502, [
        'success' => false,
        'error' => (string)$message
    ]);
}

$content = $groqResponse['choices'][0]['message']['content'] ?? '';

if (is_array($content)) {
    $parts = [];
    foreach ($content as $part) {
        if (is_array($part) && isset($part['text'])) {
            $parts[] = (string)$part['text'];
        }
    }
    $content = implode("\n", $parts);
}

$content = trim((string)$content);
$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
$content = preg_replace('/\s*```$/', '', $content);
$content = trim($content);

$result = json_decode($content, true);

if (!is_array($result)) {
    $start = strpos($content, '{');
    $end = strrpos($content, '}');

    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($content, $start, $end - $start + 1);
        $result = json_decode($candidate, true);
    }
}

if (!is_array($result)) {
    send_json(502, [
        'success' => false,
        'error' => 'ИИ вернул ответ в неожиданном формате.'
    ]);
}

$name = trim((string)($result['name'] ?? ''));
$disease = trim((string)($result['disease'] ?? ''));
$action = trim((string)($result['action'] ?? ''));

if ($name === '') {
    $name = 'Не удалось определить';
}
if ($disease === '') {
    $disease = 'Не удалось определить';
}
if ($action === '') {
    $action = 'Рекомендации не получены';
}

echo json_encode([
    'success' => true,
    'result' => [
        'name' => $name,
        'disease' => $disease,
        'action' => $action
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
