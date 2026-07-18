<?php
date_default_timezone_set('America/Sao_Paulo');
// Segredos ficam FORA do Git, em /dados/config.php (não versionado).
$cfg = __DIR__ . '/../dados/config.php';
if (file_exists($cfg)) require $cfg;
if (!defined('RESEND_API_KEY')) { http_response_code(500); exit('Config ausente: defina RESEND_API_KEY em /dados/config.php'); }
define('FROM_EMAIL', 'gabriel.luz@noticiasdafe.com.br');
define('FROM_NAME', 'Gabriel Luz – Roteiro Divino das 12 Palavras');
define('FILA_FILE', __DIR__ . '/../dados/fila.json');
define('TEMPLATES_DIR', __DIR__ . '/emails/');

if (!file_exists(FILA_FILE)) exit('Fila vazia');

// Mesma trava do webhook.php — evita gravar por cima de um webhook simultâneo.
$lock_fp = fopen(__DIR__ . '/../dados/webhook.lock', 'c');
if ($lock_fp) { flock($lock_fp, LOCK_EX); }

$fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
$agora = time();
$alterou = false;

foreach ($fila as &$item) {
    if ($item['enviado']) continue;
    if (($item['status'] ?? '') === 'pago') continue;
    // Lead sem e-mail (recuperação por WhatsApp) — não há o que enviar por e-mail.
    if (empty($item['email'])) continue;
    if ($agora < $item['enviar_em']) continue;

    $template_path = TEMPLATES_DIR . $item['template'] . '.html';
    if (!file_exists($template_path)) continue;

    $html = file_get_contents($template_path);
    $html = str_replace('{{nome}}', htmlspecialchars($item['nome']), $html);

    $assuntos = [
        'email1-30min'       => '✨ Sua bênção ainda está reservada, ' . $item['nome'],
        'email2-24h'         => '⚠️ Última chance — sua bênção ainda está aqui',
        'email-boleto-30min' => '📄 Seu boleto está esperando, ' . $item['nome'],
        'email-boleto-24h'   => '⚠️ Seu boleto vence em breve — não perca sua bênção',
        'email-abandono'     => '🙏 ' . $item['nome'] . ', o que aconteceu?',
        'email-picpay-30min' => '📲 Seu PicPay ainda está esperando, ' . $item['nome'],
        'email-picpay-24h'   => '⚠️ Última chance — sua bênção ainda está aqui',
        'email-nubank-30min' => '💜 Seu pagamento Nubank ainda está aberto, ' . $item['nome'],
        'email-nubank-24h'   => '⚠️ ' . $item['nome'] . ', sua vaga ainda está reservada',
    ];

    $assunto = $assuntos[$item['template']] ?? 'Mensagem importante';

    $ok = enviarEmail($item['email'], $assunto, $html);

    if ($ok) {
        $item['enviado'] = true;
        $item['status'] = 'enviado';
        $item['enviado_em'] = date('Y-m-d H:i:s');
        $alterou = true;
    }
}

if ($alterou) {
    file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT));
}

if ($lock_fp) { flock($lock_fp, LOCK_UN); fclose($lock_fp); }

function enviarEmail($para, $assunto, $html) {
    $payload = json_encode([
        'from'    => FROM_NAME . ' <' . FROM_EMAIL . '>',
        'to'      => [$para],
        'subject' => $assunto,
        'html'    => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
    ]);

    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    file_put_contents(__DIR__ . '/../dados/log.txt', date('Y-m-d H:i:s') . " para=$para status=$status result=$result\n", FILE_APPEND);

    return $status === 200;
}
