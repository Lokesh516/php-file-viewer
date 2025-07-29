<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}


$data = json_decode(file_get_contents('php://input'), true);
$question = $data['question'] ?? '';
$filename = $data['file'] ?? '';


$cohereApiKey = 'EUniydFgiUsxVeojRO8DH3Kd6Mzs6ILZrATQr5VO';


function fetchContext($question, $filename) {
    $url = 'https://viewer-app-4t7c.onrender.com/match_context.php';
    $payload = json_encode(['question' => $question, 'file' => $filename]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !$response) {
        return ['error' => 'Error fetching context', 'debug' => $error];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['context'])) {
        return ['error' => 'Invalid context response', 'raw' => $response];
    }

    return $data['context'];
}

$chunks = fetchContext($question, $filename);
if (isset($chunks['error'])) {
    exit();
}


$stampedChunks = [];
foreach ($chunks as $chunk) {
    $stampedChunks[] = $chunk;
}
$contextText = implode("\n\n", $stampedChunks);


$prompt = "Answer the following question using the given context:\n\nContext:\n$contextText\n\nQuestion:\n$question\n\nAt the end of your answer, add page citations in the format <pgX><pgY>.";
$coherePayload = json_encode([
    "model" => "command-r-plus",
    "prompt" => $prompt,
    "max_tokens" => 300,
    "temperature" => 0.5,
    "stop_sequences" => ["--END--"]
]);

$ch = curl_init('https://api.cohere.ai/v1/generate');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Authorization: Bearer $cohereApiKey"
    ],
    CURLOPT_POSTFIELDS => $coherePayload,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => 0
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error || !$response) {
    echo json_encode(['answer' => 'Error contacting Cohere API', 'debug' => $error]);
    exit();
}

$responseData = json_decode($response, true);
$answerText = trim($responseData['generations'][0]['text'] ?? '');

if (!$answerText) {
    echo json_encode(['answer' => '⚠️ Invalid response from Cohere.', 'raw' => $response]);
    exit();
}

$answerText = preg_replace('/<(\d+)>/', '<pg$1>', $answerText);
preg_match_all('/<pg(\d+)>/', $answerText, $matches);
$usedPages = array_unique(array_map('intval', $matches[1]));


$sanitizedAnswer = preg_replace('/<pg\d+>/', '', $answerText);
$finalAnswer = rtrim($sanitizedAnswer);
if (!preg_match('/\.\s*$/', $finalAnswer)) {
    $finalAnswer .= ".";
}

$filteredCitations = array_map(fn($pg) => ['page' => $pg], $usedPages);

echo json_encode([
    'answer' => $finalAnswer,
    'citations' => $filteredCitations,
    'debug_context' => $contextText
]);
