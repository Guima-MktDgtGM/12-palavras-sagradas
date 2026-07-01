<?php
define('WEBHOOK_SECRET', 'Jguimajo2@');
define('FILA_FILE', __DIR__ . '/emails/fila.json');

$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!isset($data['secret']) || $data['secret'] !== WEBHOOK_SECRET) {
    http_response_code(401);
    exit('Unauthorized');
}

$evento = $data['event'] ?? '';
$customer = $data['data']['customer'] ?? [];
$nome  = $customer['name']  ?? 'Amigo(a)';
$email = $customer['email'] ?? '';

if (empty($email)) {
    http_response_code(200);
    exit('No email');
}

$fila = [];
if (file_exists(FILA_FILE)) {
    $fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
}

$agora = time();

if ($evento === 'pix_gerado') {
    $fila[] = ['nome' => $nome, 'email' => $email, 'tipo' => 'pix', 'template' => 'email1-30min', 'enviar_em' => $agora + (30 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
    $fila[] = ['nome' => $nome, 'email' => $email, 'tipo' => 'pix', 'template' => 'email2-24h',  'enviar_em' => $agora + (24 * 60 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];

} elseif ($evento === 'boleto_gerado') {
    $fila[] = ['nome' => $nome, 'email' => $email, 'tipo' => 'boleto', 'template' => 'email-boleto-30min', 'enviar_em' => $agora + (30 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
    $fila[] = ['nome' => $nome, 'email' => $email, 'tipo' => 'boleto', 'template' => 'email-boleto-24h',  'enviar_em' => $agora + (24 * 60 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];

} elseif ($evento === 'abandono_de_checkout' || $evento === 'checkout_abandonado') {
    $fila[] = ['nome' => $nome, 'email' => $email, 'tipo' => 'abandono', 'template' => 'email-abandono', 'enviar_em' => $agora + (20 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];

} elseif ($evento === 'purchase_approved' || $evento === 'compra_aprovada') {
    // Marca todos os emails pendentes desse email como cancelados
    foreach ($fila as &$item) {
        if ($item['email'] === $email && !$item['enviado']) {
            $item['status'] = 'pago';
            $item['enviado'] = true;
            $item['enviado_em'] = date('Y-m-d H:i:s');
        }
    }
}

file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT));

http_response_code(200);
echo 'OK';
