<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';
use Smalot\PdfParser\Parser;

try {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!isset($input['file'])) throw new Exception('No file specified.');

    $filename = basename($input['file']);
    $cleanFile = pathinfo($filename, PATHINFO_FILENAME);
    $filePath = __DIR__ . '/uploads/' . $filename;

    if (!file_exists($filePath)) throw new Exception("File not found: $filePath");

   
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $pages = $pdf->getPages();

    
    $apiKey = 'EUniydFgiUsxVeojRO8DH3Kd6Mzs6ILZrATQr5VO'; 

    function embedText($text, $apiKey) {
    $payload = json_encode([
        'model' => 'embed-english-v3.0',
        'texts' => [$text]  ,            
        'input_type' => 'search_document' 
    ]);

    $ch = curl_init('https://api.cohere.ai/v1/embed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $apiKey"
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['embeddings'][0] ?? [];
}

   
    $contextData = [];
    $dumpFile = __DIR__ . '/page_dump.txt';
    file_put_contents($dumpFile, ""); 

    foreach ($pages as $i => $page) {
        $pageNumber = $i + 1;
        $text = trim($page->getText());

       
        file_put_contents($dumpFile, "----- Page $pageNumber -----\n" . $text . "\n\n", FILE_APPEND);

        if (strlen($text) < 10) {
            continue;
        }

        $embedding = embedText($text, $apiKey);
        if (empty($embedding)) {
            continue;
        }

        $contextData[] = [
            'page' => $pageNumber,
            'text' => $text,
            'embedding' => $embedding
        ];
    }

    // ✅ 5. Save context
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $savePath = $dir . "/context_{$cleanFile}.json";

    file_put_contents($savePath, json_encode(['pages' => $contextData], JSON_PRETTY_PRINT));

    echo json_encode([
        'status' => 'done',
        'file' => $filename,
        'pages' => count($contextData),
        'saved_to' => basename($savePath),
        'summary' => 'Context extracted and saved successfully.'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
