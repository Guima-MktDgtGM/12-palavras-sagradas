<?php
// Configurações
define('WEBHOOK_SECRET', 'Jguimajo2@');
define('FILA_FILE', __DIR__ . '/emails/fila.json');

// Recebe o payload
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Valida secret
if (!isset($data['secret']) || $data['secret'] !== WEBHOOK_SECRET) {
    http_response_code(401);
    exit('Unauthorized');
}

// Só processa pix_gerado
if (!isset($data['event']) || $data['event'] !== 'pix_gerado') {
    http_response_code(200);
    exit('Ignored');
}

$customer = $data['data']['customer'] ?? [];
$nome  = $customer['name']  ?? 'Amigo(a)';
$email = $customer['email'] ?? '';

if (empty($email)) {
    http_response_code(200);
    exit('No email');
}

// Carrega fila existente
$fila = [];
if (file_exists(FILA_FILE)) {
    $fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
}

$agora = time();

// Adiciona os dois agendamentos
$fila[] = [
    'nome'       => $nome,
    'email'      => $email,
    'template'   => 'email1-30min',
    'enviar_em'  => $agora + (30 * 60), // 30 minutos
    'enviado'    => false,
];
$fila[] = [
    'nome'       => $nome,
    'email'      => $email,
    'template'   => 'email2-24h',
    'enviar_em'  => $agora + (24 * 60 * 60), // 24 horas
    'enviado'    => false,
];

file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT));

http_response_code(200);
echo 'OK';
