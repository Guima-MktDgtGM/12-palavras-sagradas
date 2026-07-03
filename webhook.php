<?php
define('WEBHOOK_SECRET', '519879a1-a743-425a-9a2b-af20ed3d92ff');
define('FILA_FILE', __DIR__ . '/emails/fila.json');
define('CLIENTES_FILE', __DIR__ . '/emails/clientes.json');

$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

$header_secret = $_SERVER['HTTP_X_CAKTO_SECRET'] ?? $_SERVER['HTTP_X_SECRET'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body_secret = $data['secret'] ?? '';

if ($header_secret !== WEBHOOK_SECRET && $body_secret !== WEBHOOK_SECRET) {
    file_put_contents(__DIR__ . '/emails/log.txt', date('Y-m-d H:i:s') . " 401 header=$header_secret body=$body_secret\n", FILE_APPEND);
    http_response_code(401);
    exit('Unauthorized');
}

$evento   = $data['event'] ?? '';
$customer = $data['data']['customer'] ?? [];
$nome     = $customer['name']  ?? 'Amigo(a)';
$email    = $customer['email'] ?? '';
$telefone = $customer['phone'] ?? '';

if (empty($email)) {
    http_response_code(200);
    exit('No email');
}

$fila = [];
if (file_exists(FILA_FILE)) {
    $fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
}

$agora = time();

// Anti-duplicata: verifica se já existe entrada do mesmo email+tipo nos últimos 5 minutos
function jaExiste($fila, $email, $tipo, $agora) {
    foreach ($fila as $item) {
        if ($item['email'] === $email && ($item['tipo'] ?? '') === $tipo) {
            $criado = strtotime($item['criado_em'] ?? '0');
            if (($agora - $criado) < 300) return true; // 5 minutos
        }
    }
    return false;
}

if ($evento === 'pix_gerado') {
    if (!jaExiste($fila, $email, 'pix', $agora)) {
        $fila[] = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'tipo' => 'pix', 'template' => 'email1-30min', 'enviar_em' => $agora + (30 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
        $fila[] = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'tipo' => 'pix', 'template' => 'email2-24h',  'enviar_em' => $agora + (24 * 60 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
    }

} elseif ($evento === 'boleto_gerado') {
    if (!jaExiste($fila, $email, 'boleto', $agora)) {
        $fila[] = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'tipo' => 'boleto', 'template' => 'email-boleto-30min', 'enviar_em' => $agora + (30 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
        $fila[] = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'tipo' => 'boleto', 'template' => 'email-boleto-24h',  'enviar_em' => $agora + (24 * 60 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
    }

} elseif ($evento === 'abandono_de_checkout' || $evento === 'checkout_abandonado') {
    if (!jaExiste($fila, $email, 'abandono', $agora)) {
        $fila[] = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'tipo' => 'abandono', 'template' => 'email-abandono', 'enviar_em' => $agora + (20 * 60), 'enviado' => false, 'status' => 'aguardando', 'criado_em' => date('Y-m-d H:i:s')];
    }

} elseif ($evento === 'purchase_approved' || $evento === 'compra_aprovada') {
    foreach ($fila as &$item) {
        if ($item['email'] === $email && !$item['enviado']) {
            $item['status'] = 'pago';
            $item['enviado'] = true;
            $item['enviado_em'] = date('Y-m-d H:i:s');
        }
    }

    // Salva no banco de clientes (persistente, separado da fila)
    $clientes = [];
    if (file_exists(CLIENTES_FILE)) {
        $clientes = json_decode(file_get_contents(CLIENTES_FILE), true) ?? [];
    }
    $jaCliente = false;
    foreach ($clientes as $c) {
        if ($c['email'] === $email) { $jaCliente = true; break; }
    }
    if (!$jaCliente) {
        $clientes[] = [
            'nome'       => $nome,
            'email'      => $email,
            'telefone'   => $telefone,
            'comprado_em'=> date('Y-m-d H:i:s'),
            'evento'     => $evento,
        ];
        file_put_contents(CLIENTES_FILE, json_encode($clientes, JSON_PRETTY_PRINT));
    }
}

file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT));

http_response_code(200);
echo 'OK';
