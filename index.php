<?php

// --------------------------------------------------
// 1. Resolve Host (Azure Front Door safe)
// --------------------------------------------------

$host = $_SERVER['HTTP_X_FORWARDED_HOST']
    ?? $_SERVER['HTTP_HOST']
    ?? '';

$host = strtolower(explode(':', explode(',', $host)[0])[0]);

if (!$host) {
    http_response_code(400);
    exit("Invalid host");
}






// --------------------------------------------------
// 2. Lookup form base URL from Sharkdom API (FIXED)
// --------------------------------------------------

$lookupUrl = "https://prod.sharkdomapi.com/v1/users/dns/form-url?customDomain=" . urlencode($host);

$ch = curl_init($lookupUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Accept: application/hal+json",
    ],
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    http_response_code(502);
    exit("DNS lookup failed");
}

$data = json_decode($response, true);

if (empty($data['data'])) {
    http_response_code(404);
    exit("Form not found1");
}

// --------------------------------------------------
// 3. Build Target URL
// --------------------------------------------------

$targetBase = rtrim($data['data'], '/');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

$targetUrl = $targetBase . $requestUri;

// --------------------------------------------------
// 4. Proxy request to target
// --------------------------------------------------

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => [
        "Accept: text/html",
        "Accept-Encoding: identity", // critical to avoid gzip issues
    ],
]);

$response   = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    exit("Upstream request failed");
}

$headersRaw = substr($response, 0, $headerSize);
$body       = substr($response, $headerSize);

// --------------------------------------------------
// 5. Inject <base> tag for relative assets
// --------------------------------------------------

$baseTag = '<base href="' . htmlspecialchars($targetBase . '/', ENT_QUOTES, 'UTF-8') . '">';

if (stripos($body, '<head') !== false) {
    $body = preg_replace(
        '/<head[^>]*>/i',
        '$0' . PHP_EOL . $baseTag,
        $body,
        1
    );
}

// --------------------------------------------------
// 6. Send response
// --------------------------------------------------

header_remove("Content-Encoding");
header("Content-Type: text/html; charset=UTF-8");
header("X-Sharkdom-Domain: {$host}");

echo $body;
exit;
