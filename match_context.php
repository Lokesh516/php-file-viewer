<?php
require 'vendor/autoload.php';

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

$input = json_decode(file_get_contents('php://input'), true);
$question = $input['question'] ?? '';
$filename = $input['file'] ?? '';

if (!$question || !$filename) {
    echo json_encode(['context' => ['⚠️ Missing question or filename']]);
    exit();
}

$cleanFile = pathinfo($filename, PATHINFO_FILENAME);
$contextFile = __DIR__ . "/data/context_$cleanFile.json";

if (!file_exists($contextFile)) {
    echo json_encode(['context' => ["❌ Context file not found for $filename"]]);
    exit();
}

$pageData = json_decode(file_get_contents($contextFile), true);
$apiKey = 'EUniydFgiUsxVeojRO8DH3Kd6Mzs6ILZrATQr5VO'; // Replace with your real API key

function embedText($text, $apiKey, $inputType = 'search_document') {
    $payload = json_encode([
        'model' => 'embed-english-v3.0',
        'texts' => [$text],
        'input_type' => $inputType
    ]);

    $ch = curl_init('https://api.cohere.ai/v1/embed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,

        CURLOPT_POSTFIELDS => $payload
    ]);

    $response = curl_exec($ch);
    if (!$response || curl_errno($ch)) {
        curl_close($ch);
        return [];
    }

    curl_close($ch);
    $data = json_decode($response, true);
    return $data['embeddings'][0] ?? [];
}

$queryEmbedding = embedText($question, $apiKey, 'search_query');
if (!is_array($queryEmbedding) || count($queryEmbedding) < 10) {
    echo json_encode(['context' => ['⚠️ Failed to generate embedding for query.']]);
    exit();
}

function cosineSimilarity($vec1, $vec2) {
    $dot = 0; $mag1 = 0; $mag2 = 0;
    foreach ($vec1 as $i => $v) {
        $dot += $v * $vec2[$i];
        $mag1 += $v * $v;
        $mag2 += $vec2[$i] * $vec2[$i];
    }
    $denominator = sqrt($mag1) * sqrt($mag2);
    return $denominator === 0 ? 0 : $dot / $denominator;
}

$results = [];
foreach ($pageData['pages'] as $page) {
    $pageEmbedding = !empty($page['embedding']) ? $page['embedding'] : embedText($page['text'], $apiKey);
    if (empty($pageEmbedding)) continue;

    $score = cosineSimilarity($queryEmbedding, $pageEmbedding);
    $results[] = [
        'score' => round($score, 4),
        'text' => $page['text'],
        'page' => $page['page']
    ];
}

usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
$topMatches = array_slice($results, 0, 2);


if (empty($topMatches)) {
    echo json_encode(['context' => ['⚠️ No matching context found.']]);
    exit();
}

echo json_encode([
    'context' => array_map(fn($match) => "[Page {$match['page']}] ({$match['score']}): " . $match['text'], $topMatches),
    'best_match_page' => $topMatches[0]['page']
]);
