<?php
// Configurações
define('RESEND_API_KEY', 're_P1HxxW7j_6wZEHYKmM5tG6nfndehc2Dtp');
define('FROM_EMAIL', 'gabriel.luz@noticiasdafe.com.br');
define('FROM_NAME', 'Gabriel Luz – Roteiro Divino das 12 Palavras');
define('FILA_FILE', __DIR__ . '/emails/fila.json');
define('TEMPLATES_DIR', __DIR__ . '/emails/');

if (!file_exists(FILA_FILE)) exit('Fila vazia');

$fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
$agora = time();
$alterou = false;

foreach ($fila as &$item) {
    if ($item['enviado']) continue;
    if ($agora < $item['enviar_em']) continue;

    $template_path = TEMPLATES_DIR . $item['template'] . '.html';
    if (!file_exists($template_path)) continue;

    $html = file_get_contents($template_path);
    $html = str_replace('{{nome}}', htmlspecialchars($item['nome']), $html);

    $assunto = $item['template'] === 'email1-30min'
        ? '✨ Sua bênção ainda está reservada, ' . $item['nome']
        : '⚠️ Última chance — sua bênção ainda está aqui';

    $response = enviarEmail($item['email'], $assunto, $html);

    if ($response) {
        $item['enviado'] = true;
        $item['enviado_em'] = date('Y-m-d H:i:s');
        $alterou = true;
    }
}

if ($alterou) {
    file_put_contents(FILA_FILE, json_encode($fila, JSON_PRETTY_PRINT));
}

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

    return $status === 200;
}
