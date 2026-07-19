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
define('LEADS_FILE',    $dados_dir . '/checkout_leads.json');
define('LOG_FILE',      $dados_dir . '/log.txt');
define('APP_LOGIN_URL', 'https://app.appsell.ai/roteiro-divino-das-12-palavras-161/login');

// Envia NOSSO email oficial de acesso (via Resend) — instantaneo, no pagamento aprovado.
function enviarEmailAcesso($para, $nome, $login) {
    if (!defined('RESEND_API_KEY') || $para === '') return;
    $nome = htmlspecialchars($nome ?: 'Amigo(a)');
    $login = htmlspecialchars($login);
    $url  = APP_LOGIN_URL;
    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#1f2937;">'
      . '<h2 style="color:#c9a84c;">Acesso Liberado! 🙏</h2>'
      . '<p>Oi, ' . $nome . '! Sua compra foi confirmada e seu acesso ao <strong>Roteiro Divino das 12 Palavras</strong> já está liberado.</p>'
      . '<div style="background:#f7f3e6;border-radius:10px;padding:18px;margin:18px 0;">'
      . '<p style="margin:0 0 6px;"><strong>🔑 Seus dados de acesso</strong></p>'
      . '<p style="margin:0;">Login (email): <strong>' . $login . '</strong></p>'
      . '<p style="margin:6px 0 0;">Senha: <em>Defina no primeiro acesso</em></p>'
      . '</div>'
      . '<p style="text-align:center;margin:24px 0;"><a href="' . $url . '" style="background:#c9a84c;color:#111;font-weight:bold;padding:14px 28px;border-radius:8px;text-decoration:none;">Acessar Meu Aplicativo</a></p>'
      . '<p style="font-size:13px;color:#6b7280;">Use o email acima para fazer login e crie sua senha no primeiro acesso.</p>'
      . '</div>';
    $body = json_encode([
        'from'    => 'Gabriel Luz <gabriel.luz@noticiasdafe.com.br>',
        'to'      => [$para],
        'subject' => 'Acesso Liberado — Roteiro Divino das 12 Palavras',
        'html'    => $html,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch); curl_close($ch);
}

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

    // O email que a Cakto manda pode ser um ALIAS. Mapeia pro contato REAL do nosso checkout.
    $emailReal = $email; $telReal = $telefone; $loginApp = $email;
    $leadsChk = file_exists(LEADS_FILE) ? (json_decode(@file_get_contents(LEADS_FILE), true) ?: []) : [];
    for ($i = count($leadsChk) - 1; $i >= 0; $i--) {
        $L = $leadsChk[$i];
        if (($L['email_cakto'] ?? '') === $email || ($L['alias'] ?? '') === $email || ($L['email'] ?? '') === $email) {
            $emailReal = ($L['email'] ?? '') ?: $email;
            $telReal   = ($L['telefone'] ?? '') ?: $telefone;
            $loginApp  = ($L['email_cakto'] ?? '') ?: $email; // login = o email que a Appsell recebeu
            break;
        }
    }

    if (!jaEhCliente($clientes, $emailReal)) {
        $clientes[] = [
            'nome'        => $nome,
            'email'       => $emailReal,
            'telefone'    => $telReal,
            'comprado_em' => date('Y-m-d H:i:s'),
        ];
        file_put_contents(CLIENTES_FILE, json_encode($clientes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // NOSSO email oficial de acesso, pro email REAL do cliente (uma vez só).
        enviarEmailAcesso($emailReal, $nome, $loginApp);
    }
}

file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Libera a trava só depois de gravar tudo.
if ($lock_fp) { flock($lock_fp, LOCK_UN); fclose($lock_fp); }

http_response_code(200);
echo 'OK';
