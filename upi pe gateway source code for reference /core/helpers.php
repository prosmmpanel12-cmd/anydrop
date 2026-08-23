<?php
function success(array $data = [], string $msg = 'Success'): void {
    echo json_encode(array_merge(['status'=>'success','message'=>$msg], $data),
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['status'=>'error','message'=>$msg], $extra),
        JSON_UNESCAPED_SLASHES);
    exit;
}

function httpPost(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function httpGet(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $res = curl_exec($ch); curl_close($ch);
    return $res ? json_decode($res, true) : null;
}
