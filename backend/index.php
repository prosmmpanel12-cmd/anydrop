<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'data' => ['message' => 'Anydrop API is running', 'version' => 'phase1'],
    'error' => null,
]);
