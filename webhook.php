<?php
date_default_timezone_set('America/Sao_Paulo');

// Pasta FORA do public_html — nunca apagada por deploy
$dados_dir = __DIR__ . '/../dados';
if (!is_dir($dados_dir)) mkdir($dados_dir, 0755, true);

// Segredos ficam FORA do Git, em /dados/config.php (não versionado).
$cfg = $dados_dir . '/config.php';
if (file_exists($cfg)) require $cfg;
if (!defined('WEBHOOK_SECRET')) { http_response_code(500); exit('Config ausente: defina WEBHOOK_SECRET em /dados/config.php'); }

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

// ── Trava global: serializa o read-modify-write pra não perder registros ──────
// Sem isso, dois webhooks simultâneos leem o mesmo JSON e um sobrescreve o outro.
define('LOCK_FILE', $dados_dir . '/webhook.lock');
$lock_fp = fopen(LOCK_FILE, 'c');
if ($lock_fp) { flock($lock_fp, LOCK_EX); }

$evento   = $data['event'] ?? '';
$customer = $data['data']['customer'] ?? [];
$nome     = $customer['name']  ?? 'Amigo(a)';
$email    = $customer['email'] ?? '';
$telefone = $customer['phone'] ?? '';

file_put_contents(LOG_FILE, date('Y-m-d H:i:s') . " evento=$evento email=$email tel=$telefone\n", FILE_APPEND);

// Precisa de PELO MENOS um canal de contato: e-mail OU telefone.
if (empty($email) && empty($telefone)) { http_response_code(200); exit('No contact'); }

// Identificador do lead (e-mail se tiver; senão, telefone) — usado na deduplicação.
$ident = $email !== '' ? $email : $telefone;
// Canal de recuperação: e-mail se tiver e-mail; senão, WhatsApp.
$canal = $email !== '' ? 'email' : 'whatsapp';

$fila    = file_exists(FILA_FILE)     ? (json_decode(file_get_contents(FILA_FILE),     true) ?? []) : [];
$clientes = file_exists(CLIENTES_FILE) ? (json_decode(file_get_contents(CLIENTES_FILE), true) ?? []) : [];
$agora   = time();

function jaExisteFila($fila, $ident, $tipo, $agora) {
    foreach ($fila as $item) {
        $item_ident = ($item['email'] ?? '') !== '' ? $item['email'] : ($item['telefone'] ?? '');
        if ($item_ident === $ident && ($item['tipo'] ?? '') === $tipo) {
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
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $ident, 'pix', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>'pix','template'=>'email1-30min',   'enviar_em'=>$agora+(30*60),    'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>'pix','template'=>'email2-24h',     'enviar_em'=>$agora+(24*60*60), 'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── PicPay / Nubank gerado (mesmo fluxo do Pix) ─────────────────────────────
} elseif (in_array($evento, ['picpay_gerado','nubank_gerado'])) {
    $tipo_evento = str_replace('_gerado', '', $evento); // 'picpay' ou 'nubank'
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $ident, $tipo_evento, $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>$tipo_evento,'template'=>"email-{$tipo_evento}-30min",'enviar_em'=>$agora+(30*60),    'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>$tipo_evento,'template'=>"email-{$tipo_evento}-24h", 'enviar_em'=>$agora+(24*60*60), 'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── Boleto gerado ────────────────────────────────────────────────────────────
} elseif ($evento === 'boleto_gerado') {
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $ident, 'boleto', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>'boleto','template'=>'email-boleto-30min','enviar_em'=>$agora+(30*60),   'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>'boleto','template'=>'email-boleto-24h', 'enviar_em'=>$agora+(24*60*60),'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
    }

// ── Abandono de checkout ─────────────────────────────────────────────────────
} elseif (in_array($evento, ['checkout_abandonment','abandono_de_checkout','checkout_abandonado'])) {
    if (!jaEhCliente($clientes, $email) && !jaExisteFila($fila, $ident, 'abandono', $agora)) {
        $fila[] = ['nome'=>$nome,'email'=>$email,'telefone'=>$telefone,'canal'=>$canal,'tipo'=>'abandono','template'=>'email-abandono','enviar_em'=>$agora+(20*60),'enviado'=>false,'status'=>'aguardando','criado_em'=>date('Y-m-d H:i:s')];
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

// Libera a trava só depois de gravar tudo.
if ($lock_fp) { flock($lock_fp, LOCK_UN); fclose($lock_fp); }

http_response_code(200);
echo 'OK';
