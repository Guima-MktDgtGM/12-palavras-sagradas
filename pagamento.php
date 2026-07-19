<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

// Segredos ficam FORA do Git, em /dados/config.php
$dados_dir = __DIR__ . '/../dados';
$cfg = $dados_dir . '/config.php';
if (file_exists($cfg)) require $cfg;
if (!defined('CAKTO_CLIENT_ID') || !defined('CAKTO_CLIENT_SECRET')) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuracao ausente no servidor (CAKTO_CLIENT_ID/SECRET).']);
    exit;
}

define('CAKTO_BASE', 'https://api.cakto.com.br');
define('TOKEN_CACHE', $dados_dir . '/cakto_token.json');
define('CAKTO_PRODUCT_ID', '944bd7e8-aac1-4a19-9864-0d81c4f90cbc'); // produto R$67

function uuidv4() {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// Obtem (com cache de ~10h) o Bearer token via OAuth2.
function caktoToken() {
    if (file_exists(TOKEN_CACHE)) {
        $c = json_decode(@file_get_contents(TOKEN_CACHE), true);
        if ($c && !empty($c['access_token']) && time() < (($c['expires_at'] ?? 0) - 60)) {
            return $c['access_token'];
        }
    }
    $ch = curl_init(CAKTO_BASE . '/public_api/token/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => CAKTO_CLIENT_ID,
            'client_secret' => CAKTO_CLIENT_SECRET,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($res, true);
    if ($code !== 200 || empty($d['access_token'])) return null;
    file_put_contents(TOKEN_CACHE, json_encode([
        'access_token' => $d['access_token'],
        'expires_at'   => time() + intval($d['expires_in'] ?? 3600),
    ]));
    return $d['access_token'];
}

$token = caktoToken();
if (!$token) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao autenticar com a Cakto. Confira client_id/secret e os escopos da chave.']);
    exit;
}

// ─── Consultar status de um pedido (polling do Pix) ──────────────────────────
if (isset($_GET['status'])) {
    $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['status']);
    $ch = curl_init(CAKTO_BASE . '/public_api/orders/' . $id . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    http_response_code($code);
    echo $res ?: json_encode(['error' => 'sem resposta']);
    exit;
}

// ─── MODO DEBUG: cria um Pix de teste e mostra a resposta CRUA da Cakto ───────
// Uso: /pagamento.php?debug=pix  (nao afeta o checkout dos clientes)
if (isset($_GET['debug']) && $_GET['debug'] === 'pix') {
    $payload = [
        'paymentMethod' => 'pix',
        'customer'      => [
            'name' => 'Teste Debug', 'email' => 'teste@teste.com',
            'phone' => '5511999999999', 'fingerprint' => 'debug-ref-123',
            'docType' => 'cpf', 'docNumber' => '12345678909',
        ],
        'items' => [['offerId' => ($_GET['offer'] ?? '96yyuuz')]],
    ];
    $ch = curl_init(CAKTO_BASE . '/public_api/payments/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token,'Content-Type: application/json','X-Idempotency-Key: '.uuidv4()],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 40,
    ]);
    $r = curl_exec($ch); $co = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo json_encode(['http_code'=>$co, 'payload_enviado'=>$payload, 'resposta_cakto'=>json_decode($r, true)], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Criar cobranca (Pix ou Cartao/3DS) ──────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalido.']);
    exit;
}

$c       = $input['customer'] ?? [];
$offerId = $input['offerId'] ?? '';
$ref     = $input['antifraudRef'] ?? '';
$method  = $input['method'] ?? 'pix';

$customer = [
    'name'        => trim($c['name'] ?? ''),
    'email'       => trim($c['email'] ?? ''),
    'phone'       => preg_replace('/\D/', '', $c['phone'] ?? ''),
    'fingerprint' => $ref,
    'docType'     => 'cpf',
    'docNumber'   => preg_replace('/\D/', '', $c['cpf'] ?? ''),
];

if ($method === 'pix') {
    $payload = [
        'paymentMethod' => 'pix',
        'customer'      => $customer,
        'items'         => [['offerId' => $offerId]],
    ];
} elseif (!empty($input['threeDSecure'])) {
    // Cartão COM 3DS (paymentMethod threeDs). O antifraude + 3DS aumentam MUITO a aprovação.
    // O campo antifraud_profiling_attempt_reference é obrigatorio na cobranca de cartao.
    $payload = [
        'offerId'       => $offerId,
        'paymentMethod' => 'threeDs',
        'customer'      => $customer,
        'cardToken'     => $input['cardToken'] ?? '',
        'threeDSecure'  => $input['threeDSecure'],
        'antifraud_profiling_attempt_reference' => $ref,
        'installments'  => intval($input['installments'] ?? 1),
    ];
} else {
    // Cartão SEM 3DS (credit_card) — estrutura que a Cakto ja aceitava (items + card),
    // agora COM a ref antifraude obrigatoria que faltava antes.
    $payload = [
        'paymentMethod' => 'credit_card',
        'customer'      => $customer,
        'items'         => [['offerId' => $offerId]],
        'card'          => [
            'token'        => $input['cardToken'] ?? '',
            'installments' => intval($input['installments'] ?? 1),
        ],
        'antifraud_profiling_attempt_reference' => $ref,
    ];
}

$ch = curl_init(CAKTO_BASE . '/public_api/payments/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . uuidv4(),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 40,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ─── Captura do lead REAL (email/telefone do cliente) pro nosso banco ─────────
// Salva TODO mundo que preenche o checkout, mesmo quem nao finaliza (recuperacao).
$resp_dec = json_decode($res, true);
$lead = [
    'data'    => date('Y-m-d H:i:s'),
    'nome'    => $customer['name'],
    'email'   => $customer['email'],
    'telefone'=> $customer['phone'],
    'cpf'     => $customer['docNumber'],
    'metodo'  => $method,
    'oferta'  => $offerId,
    'status'  => is_array($resp_dec) && isset($resp_dec['status']) ? $resp_dec['status'] : ('http_' . $code),
    'order_id'=> is_array($resp_dec) && isset($resp_dec['id']) ? $resp_dec['id'] : '',
];
$leads_file = $dados_dir . '/checkout_leads.json';
$lf = fopen($leads_file, 'c+');
if ($lf) {
    if (flock($lf, LOCK_EX)) {
        $raw = stream_get_contents($lf);
        $leads = $raw ? json_decode($raw, true) : [];
        if (!is_array($leads)) $leads = [];
        $leads[] = $lead;
        ftruncate($lf, 0);
        rewind($lf);
        fwrite($lf, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($lf);
        flock($lf, LOCK_UN);
    }
    fclose($lf);
}

http_response_code($code);
echo $res ?: json_encode(['error' => 'Sem resposta da Cakto.']);
