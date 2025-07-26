<?php
require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;


header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $filename = $input['file'] ?? ($_GET['file'] ?? '');

    if (!$filename) {
        throw new Exception('No filename provided.');
    }

    $pdfPath = __DIR__ . '/uploads/' . basename($filename);
    if (!file_exists($pdfPath)) {
        throw new Exception("File not found: $filename");
    }

   
    $parser = new Parser();
    $pdf = $parser->parseFile($pdfPath);
    $pages = $pdf->getPages();

    $pageTexts = [];
    foreach ($pages as $i => $page) {
        $text = $page->getText();
        $pageTexts[] = [
            'page' => $i + 1,
            'text' => $text
        ];
    }

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
