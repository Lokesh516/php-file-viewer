<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    if (!isset($_FILES['pdf'])) {
        throw new Exception('No file uploaded.');
    }

    $file = $_FILES['pdf'];
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $targetPath = $uploadDir . basename($file['name']);

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to move uploaded file.');
    }

    
    $contextUrl = 'http://localhost/notebooklm-clone/backend/build_pdf_context.php';
    $postData = ['file' => $file['name']];

    $ch = curl_init($contextUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        throw new Exception('Failed to call build_pdf_context.');
    }

    $contextData = json_decode($response, true);
    if (!$contextData || !isset($contextData['status'])) {
        throw new Exception('Malformed context response.');
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'File uploaded and context built.',
        'filename' => basename($file['name']),
        'context' => $contextData
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
