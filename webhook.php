<?php
define('WEBHOOK_SECRET', '519879a1-a743-425a-9a2b-af20ed3d92ff');

// Pasta FORA do public_html — nunca apagada por deploy
$dados_dir = __DIR__ . '/../dados';
if (!is_dir($dados_dir)) mkdir($dados_dir, 0755, true);

define('FILA_FILE',     $dados_dir . '/fila.json');
define('CLIENTES_FILE', $dados_dir . '/clientes.json');
define('LOG_FILE',      $dados_dir . '/log.txt');

$payload = file_get_contents('php://input');
$data    = json_decode($payload, true);

$header_secret = $_SERVER['HTTP_X_CAKTO_SECRET'] ?? $_SERVER['HTTP_X_SECRET'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body_secret   = $data['secret'] ?? '';

if ($header_secret !== WEBHOOK_SECRET && $body_secret !== WEBHOOK_SECRET) {
    file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " 401 header=$header_secret body=$body_secret\n", FILE_APPEND);
    http_response_code(401);
    exit('Unauthorized');
}

$evento   = $data['event'] ?? '';
$customer = $data['data']['customer'] ?? [];
$nome     = $customer['name']  ?? 'Amigo(a)';
$email    = $customer['email'] ?? '';
$telefone = $customer['phone'] ?? '';

file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " evento=$evento email=$email\n", FILE_APPEND);

if (empty($email)) { http_response_code(200); exit('No email'); }

$fila    = file_exists(FILA_FILE)     ? (json_decode(file_get_contents(FILA_FILE),     true) ?? []) : [];
$clientes = file_exists(CLIENTES_FILE) ? (json_decode(file_get_contents(CLIENTES_FILE), true) ?? []) : [];
$agora   = time();

function jaExisteFila($fila, $email, $tipo, $agora) {
    foreach ($fila as $item) {
        if ($item['email'] === $email && ($item['tipo'] ?? '') === $tipo) {
            $criado = strtotime($item['criado_em'] ?? '0');
            if (($agora - $criado) < 300) return true;
        }
    }
    return false;
}

function jaEhCliente($clientes, $email) {
    foreach ($clientes as $c) {
        if ($c['email'] === $email) return true;
    }
    return false;
}

// ── Pix gerado: agenda 2 emails de recuperação (só se não for cliente) ──────
if ($evento === 'pix_gerado') {
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $email, 'pix', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>'pix','template'=>'email1-30min',   'enviar_em'=>$agora+(30*60),    'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>'pix','template'=>'email2-24h',     'enviar_em'=>$agora+(24*60*60), 'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── PicPay / Nubank gerado (mesmo fluxo do Pix) ─────────────────────────────
} elseif (in_array($evento, ['picpay_gerado','nubank_gerado'])) {
    $tipo_evento = str_replace('_gerado', '', $evento); // 'picpay' ou 'nubank'
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $email, $tipo_evento, $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>$tipo_evento,'template'=>'email1-30min',   'enviar_em'=>$agora+(30*60),    'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>$tipo_evento,'template'=>'email2-24h',     'enviar_em'=>$agora+(24*60*60), 'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── Boleto gerado ────────────────────────────────────────────────────────────
} elseif ($evento === 'boleto_gerado') {
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $email, 'boleto', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>'boleto','template'=>'email-boleto-30min','enviar_em'=>$agora+(30*60),   'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>'boleto','template'=>'email-boleto-24h', 'enviar_em'=>$agora+(24*60*60),'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── Abandono de checkout ─────────────────────────────────────────────────────
} elseif (in_array($evento, ['abandono_de_checkout','checkout_abandonado'])) {
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $email, 'abandono', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'tipo'=>'abandono','template'=>'email-abandono','enviar_em'=>$agora+(20*60),'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── Compra aprovada: cancela emails pendentes + salva como cliente ───────────
} elseif (in_array($evento, ['purchase_approved','compra_aprovada'])) {
    foreach ($fila as &$item) {
        if ($item['email'] === $email && !$item['enviado']) {
            $item['status']     = 'pago';
            $item['enviado']    = true;
            $item['enviado_em'] = date('Y-m-d H:i:s');
        }
    }
    unset($item);

    if (!jaEhCliente($clientes, $email)) {
        $clientes[] = [
            'nome'        => $nome,
            'email'       => $email,
            'telefone'    => $telefone,
            'comprado_em' => date('Y-m-d H:i:s'),
        ];
        file_put_contents(CLIENTES_FILE, json_encode($clientes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

http_response_code(200);
echo 'OK';
