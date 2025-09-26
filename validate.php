<?php
// /validate.php — server-side proxy: hides backend URL from the browser
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Allow same-origin; harmless if browser hits directly
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Accept-Language');
  http_response_code(204); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405); echo '{"valid":false,"message":"Method not allowed"}'; exit;
}

$BACKEND_URL = getenv('BACKEND_URL') ?: '';
$SHARED      = getenv('EDGE_SHARED_SECRET') ?: ''; // optional HMAC hardening

$raw = file_get_contents('php://input');
// Basic sanity: prevent accidental huge payloads
if (strlen($raw) > 64 * 1024) { http_response_code(413); echo '{"valid":false,"message":"Payload too large"}'; exit; }

// Short-lived HMAC to prove the request comes from your proxy
$ts  = (string) time();
$sig = '';
if ($SHARED !== '') {
  $bin = hash_hmac('sha256', $ts, $SHARED, true);
  $sig = rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$ch = curl_init($BACKEND_URL);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => array_filter([
    'Content-Type: application/json',
    'Accept-Language: ' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en'),
    'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'frontend-proxy'),
    'X-Proxy-From: frontend',          // your backend will require this
    'X-Client-Lang: ' . (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')[0]),
    $SHARED ? "X-Edge-Ts: $ts" : null, // signature pair
    $SHARED ? "X-Edge-Sig: $sig" : null,
  ]),
  CURLOPT_POSTFIELDS     => $raw,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER         => false,
  CURLOPT_TIMEOUT        => 12,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  http_response_code(502);
  echo json_encode(['valid'=>false,'message'=>'Upstream unavailable','detail'=>$err], JSON_UNESCAPED_SLASHES);
  exit;
}

// If backend didn’t return JSON, wrap with a helpful error so your UI isn’t vague
$ct = 'application/json';
if (!preg_match('~^\s*\{|\[~', ltrim((string)$resp))) { // cheap JSON check
  http_response_code($code);
  echo json_encode([
    'valid' => false,
    'code'  => 'backend_response',
    'message' => 'Upstream returned an unexpected response',
    'upstreamStatus' => $code,
    'body' => substr((string)$resp, 0, 512),
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
echo $resp;
