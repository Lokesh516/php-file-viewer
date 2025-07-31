<?php
// CORS and JSON headers
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

// Cohere API Key
$cohereApiKey = 'EUniydFgiUsxVeojRO8DH3Kd6Mzs6ILZrATQr5VO';

// 1. Fetch matched context from context server
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
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['context'] ?? [];
}

// 2. Extract text per page using Smalot/pdfparser
function extractPdfPages($pdfPath) {
    require_once 'vendor/autoload.php';
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($pdfPath);
    $pages = $pdf->getPages();
    return array_map(fn($page) => $page->getText(), $pages);
}

// 3. Normalize for fuzzy matching
function normalizeText($text) {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $text)));
}

// 4. Match response fragments to PDF pages with majority voting
function matchPagesFromAnswer($answerText, $filename) {
    $pdfPath = __DIR__ . "/uploads/$filename";
    $pages = extractPdfPages($pdfPath);
    $fragments = preg_split('/(?<=[.!?])\s+|,\s+|[\r\n]+|•\s*/', $answerText);
    $pageHits = [];

    foreach ($fragments as $frag) {
        $normFrag = normalizeText($frag);
        if ($normFrag === '') continue;

        foreach ($pages as $i => $pgText) {
            $normPage = normalizeText($pgText);
            if (strpos($normPage, $normFrag) !== false) {
                $pageHits[] = $i + 1;
                break;
            }
        }
    }

    // Count frequency and sort highest first
    $counts = array_count_values($pageHits);
    arsort($counts);
    $topPages = array_keys($counts);

    return $topPages;
}


// 5. Prepare context and prompt
$chunks = fetchContext($question, $filename);
if (!$chunks) {
    echo json_encode(['answer' => 'No context available.']);
    exit();
}

$contextText = implode("\n\n", $chunks);
$prompt = "Answer the following question using the given context:\n\nContext:\n$contextText\n\nQuestion:\n$question\n\nProvide a clear and concise answer based only on the context.";

// 6. Query Cohere API
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
curl_close($ch);

$responseData = json_decode($response, true);
$answerText = trim($responseData['generations'][0]['text'] ?? '');
if (!$answerText) {
    echo json_encode(['answer' => 'AI response unavailable.']);
    exit();
}

// 7. Clean final response
$finalAnswer = preg_replace('/<pg\d+>/', '', $answerText);
$finalAnswer = rtrim($finalAnswer);
if (!preg_match('/\.\s*$/', $finalAnswer)) {
    $finalAnswer .= ".";
}

// 8. Match answer against PDF pages
$pageHits = matchPagesFromAnswer($finalAnswer, $filename);
$pageNumbers = array_map(fn($pg) => ['page' => $pg], $pageHits);

// 9. Send final response
echo json_encode([
    'answer' => $finalAnswer,
    'citations' => $pageNumbers
]);
