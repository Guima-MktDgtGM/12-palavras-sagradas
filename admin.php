<?php
// Credenciais ficam FORA do Git, em /dados/config.php (não versionado).
$cfg = __DIR__ . '/../dados/config.php';
if (file_exists($cfg)) require $cfg;
if (!defined('ADMIN_USER')) define('ADMIN_USER', 'admin_desativado');
if (!defined('ADMIN_PASS')) define('ADMIN_PASS', bin2hex(random_bytes(16))); // senha impossível se config faltar
define('FILA_FILE',     __DIR__ . '/../dados/fila.json');
define('CLIENTES_FILE', __DIR__ . '/../dados/clientes.json');

session_start();

if (isset($_POST['login'])) {
    if ($_POST['user'] === ADMIN_USER && $_POST['pass'] === ADMIN_PASS) {
        $_SESSION['admin'] = true;
    } else {
        $erro = 'Usuário ou senha incorretos.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['admin'])) {
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Admin — Login</title>
<style>
  body{margin:0;background:#0a0a1a;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;}
  .box{background:#0d0d2b;border:1px solid #2a1f5e;border-radius:12px;padding:40px;width:320px;}
  h2{color:#f0c060;text-align:center;margin:0 0 24px;}
  input{width:100%;box-sizing:border-box;padding:10px 14px;margin-bottom:14px;background:#1a1040;border:1px solid #3a2f7e;border-radius:6px;color:#e8dfc4;font-size:15px;}
  button{width:100%;padding:12px;background:linear-gradient(135deg,#c9a84c,#f0c060);border:none;border-radius:6px;font-size:16px;font-weight:bold;cursor:pointer;color:#0a0a1a;}
  .erro{color:#ff6b6b;font-size:13px;text-align:center;margin-bottom:12px;}
</style>
</head>
<body>
<div class="box">
  <h2>🔐 Admin</h2>
  <?php if (isset($erro)) echo '<p class="erro">'.$erro.'</p>'; ?>
  <form method="POST">
    <input type="text" name="user" placeholder="Usuário" required>
    <input type="password" name="pass" placeholder="Senha" required>
    <button type="submit" name="login">Entrar</button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

// ─── Dados ───────────────────────────────────────────────────────────────────
$fila = [];
if (file_exists(FILA_FILE)) {
    $fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
}

$clientes = [];
if (file_exists(CLIENTES_FILE)) {
    $clientes = json_decode(file_get_contents(CLIENTES_FILE), true) ?? [];
}

// ─── Horários de pico (a partir do log.txt) ──────────────────────────────────
$LOG_FILE   = __DIR__ . '/../dados/log.txt';
$horas_ger  = array_fill(0, 24, 0);  // pix/boleto/picpay gerado
$horas_pago = array_fill(0, 24, 0);  // compra aprovada
$horas_ab   = array_fill(0, 24, 0);  // abandono de checkout
$leads_log  = [];                    // TODOS os leads do log (fonte que nunca perde)
if (file_exists($LOG_FILE)) {
    $fh = fopen($LOG_FILE, 'r');
    if ($fh) {
        while (($linha = fgets($fh)) !== false) {
            if (strpos($linha, 'evento=') === false) continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}):\d{2}:\d{2} evento=(\w+)/', $linha, $m)) {
                $h = (int)$m[1]; $ev = $m[2];
                if ($ev === 'purchase_approved')                       $horas_pago[$h]++;
                elseif (in_array($ev, ['pix_gerado','boleto_gerado','picpay_gerado','nubank_gerado'])) $horas_ger[$h]++;
                elseif ($ev === 'checkout_abandonment')                $horas_ab[$h]++;
            }
            // Coleta contato de cada lead direto do log (nunca perde nada).
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) evento=(\w+) email=(\S*) tel=(\S*)/', $linha, $mm)) {
                $ev2 = $mm[2];
                if (!in_array($ev2, ['pix_gerado','boleto_gerado','picpay_gerado','nubank_gerado','checkout_abandonment'])) continue;
                $tel2 = preg_replace('/\D/', '', $mm[4]);
                $em2  = trim($mm[3]);
                if ($tel2 === '' && $em2 === '') continue;
                $chave = $tel2 !== '' ? $tel2 : $em2;   // dedup por telefone (ou e-mail)
                // mantém a ocorrência mais recente
                $leads_log[$chave] = [
                    'quando' => $mm[1],
                    'tipo'   => str_replace(['_gerado','checkout_'], ['',''], $ev2),
                    'email'  => $em2,
                    'tel'    => $tel2,
                ];
            }
        }
        fclose($fh);
    }
}
// Marca quem já é cliente (pagou) e ordena por mais recente.
$emails_clientes = array_column($clientes, 'email');
foreach ($leads_log as &$L) { $L['pago'] = ($L['email'] !== '' && in_array($L['email'], $emails_clientes)); }
unset($L);
$leads_log = array_values($leads_log);
usort($leads_log, fn($a,$b) => strcmp($b['quando'], $a['quando']));
$horas_total = [];
for ($i = 0; $i < 24; $i++) $horas_total[$i] = $horas_ger[$i] + $horas_pago[$i];
$max_total   = max(1, max($horas_total));
$tot_ger     = array_sum($horas_ger);
$tot_pago    = array_sum($horas_pago);
$tot_ab      = array_sum($horas_ab);

// Agrupa leads por email+tipo para mostrar status dos 2 emails em uma linha
$grupos = [];
foreach ($fila as $item) {
    // Identifica por e-mail; se não tiver, por telefone (leads de WhatsApp).
    $lead_id = ($item['email'] ?? '') !== '' ? $item['email'] : ($item['telefone'] ?? '');
    $key = $lead_id . '||' . ($item['tipo'] ?? '');
    if (!isset($grupos[$key])) {
        $grupos[$key] = [
            'nome'      => $item['nome'] ?? '-',
            'email'     => $item['email'] ?? '-',
            'telefone'  => $item['telefone'] ?? '-',
            'canal'     => $item['canal'] ?? (($item['email'] ?? '') !== '' ? 'email' : 'whatsapp'),
            'tipo'      => $item['tipo'] ?? '-',
            'criado_em' => $item['criado_em'] ?? '-',
            'emails'    => [],
        ];
    }
    $grupos[$key]['emails'][] = $item;
}
$grupos = array_values($grupos);
// Ordena por criado_em desc
usort($grupos, fn($a,$b) => strcmp($b['criado_em'], $a['criado_em']));

// Filtro da aba leads
$filtro_tipo = $_GET['tipo'] ?? '';
if ($filtro_tipo) {
    $grupos = array_filter($grupos, fn($g) => $g['tipo'] === $filtro_tipo);
}

// Stats
$total_leads   = count(array_unique(array_column($fila, 'email')));
$total_clientes = count($clientes);
$emails_enviados = count(array_filter($fila, fn($i) => ($i['status'] ?? '') === 'enviado'));

$aba = $_GET['aba'] ?? 'leads';

$labels = [
    'email1-30min'       => 'Pix 30min',
    'email2-24h'         => 'Pix 24h',
    'email-boleto-30min' => 'Boleto 30min',
    'email-boleto-24h'   => 'Boleto 24h',
    'email-abandono'     => 'Abandono',
];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Roteiro Divino</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;background:#0a0a1a;color:#e8dfc4;font-family:Arial,sans-serif;padding:20px;}
  h1{color:#f0c060;margin:0;}
  .topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
  a.logout{color:#ff6b6b;font-size:13px;text-decoration:none;}

  /* Cards */
  .cards{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;}
  .card{background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:18px 24px;min-width:140px;text-align:center;}
  .card .num{font-size:32px;font-weight:bold;color:#f0c060;}
  .card .label{font-size:13px;color:#9a8fbb;margin-top:4px;}

  /* Abas */
  .abas{display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid #2a1f5e;}
  .aba{padding:10px 28px;text-decoration:none;color:#9a8fbb;font-size:15px;font-weight:bold;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s;}
  .aba:hover{color:#e8dfc4;}
  .aba.ativa{color:#f0c060;border-bottom-color:#f0c060;}

  /* Filtros */
  .filtros{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center;}
  select,a.btn{padding:8px 14px;background:#1a1040;border:1px solid #3a2f7e;border-radius:6px;color:#e8dfc4;font-size:14px;text-decoration:none;cursor:pointer;}
  a.btn{background:#2a1f5e;}

  /* Tabela */
  table{width:100%;border-collapse:collapse;font-size:14px;}
  th{background:#1a1040;color:#f0c060;padding:10px 12px;text-align:left;border-bottom:1px solid #2a1f5e;}
  td{padding:10px 12px;border-bottom:1px solid #1a1040;vertical-align:middle;}
  tr:hover td{background:#0f0f25;}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:bold;}
  .tipo-pix{background:#1a3a5e;color:#60b0f0;}
  .tipo-boleto{background:#3a2a0e;color:#f0c060;}
  .tipo-picpay{background:#0a2e1a;color:#00d4a0;}
  .tipo-nubank{background:#2a0a3a;color:#c084f0;}
  .tipo-abandono{background:#2a1a3a;color:#c080f0;}

  /* Status email */
  .email-check{display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:3px 8px;border-radius:12px;margin:2px 0;}
  .email-ok{background:#0d2e1a;color:#2ecc71;}
  .email-pendente{background:#1a1a2e;color:#6a5f8a;}
  .email-aguardando{background:#1a1a2e;color:#c9884c;}

  /* Cliente badge */
  .cliente-badge{background:#1a3e2a;color:#2ecc71;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:bold;}

  @media(max-width:600px){.cards{flex-direction:column;}.filtros{flex-direction:column;}}
</style>
</head>
<body>

<div class="topbar">
  <h1>📊 Painel Admin</h1>
  <a href="?logout=1" class="logout">Sair →</a>
</div>

<div class="cards">
  <div class="card"><div class="num"><?= $total_leads ?></div><div class="label">Leads capturados</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $total_clientes ?></div><div class="label">Clientes pagos ✓</div></div>
  <div class="card"><div class="num"><?= $emails_enviados ?></div><div class="label">Emails enviados</div></div>
</div>

<div class="abas">
  <a href="?aba=leads" class="aba <?= $aba==='leads'?'ativa':'' ?>">📋 Leads</a>
  <a href="?aba=recuperacao" class="aba <?= $aba==='recuperacao'?'ativa':'' ?>">📱 Recuperação (WhatsApp)</a>
  <a href="?aba=clientes" class="aba <?= $aba==='clientes'?'ativa':'' ?>">🏆 Clientes</a>
  <a href="?aba=checkout" class="aba <?= $aba==='checkout'?'ativa':'' ?>">🛒 Checkout</a>
  <a href="?aba=horarios" class="aba <?= $aba==='horarios'?'ativa':'' ?>">📊 Horários</a>
</div>

<?php if ($aba === 'leads'): ?>

<div class="filtros">
  <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="aba" value="leads">
    <select name="tipo" onchange="this.form.submit()">
      <option value="">Todos os tipos</option>
      <option value="pix"      <?= $filtro_tipo==='pix'?'selected':'' ?>>Pix</option>
      <option value="boleto"   <?= $filtro_tipo==='boleto'?'selected':'' ?>>Boleto</option>
      <option value="picpay"   <?= $filtro_tipo==='picpay'?'selected':'' ?>>PicPay</option>
      <option value="nubank"   <?= $filtro_tipo==='nubank'?'selected':'' ?>>Nubank</option>
      <option value="abandono" <?= $filtro_tipo==='abandono'?'selected':'' ?>>Abandono</option>
    </select>
    <a href="?aba=leads" class="btn">Limpar filtros</a>
  </form>
</div>

<table>
  <thead>
    <tr>
      <th>Nome</th>
      <th>Email</th>
      <th>Telefone</th>
      <th>Tipo</th>
      <th>Email 1</th>
      <th>Email 2</th>
      <th>Capturado em</th>
    </tr>
  </thead>
  <?php function badgeEmail($em, $label) {
    if (!$em) return '<span class="email-check email-pendente">— '.$label.'</span>';
    if ($em['enviado'] ?? false) {
        return '<span class="email-check email-ok">✅ '.$label.'<br><small style="opacity:.7">'.($em['enviado_em'] ?? '').'</small></span>';
    }
    if (($em['status'] ?? '') === 'pago') {
        return '<span class="email-check email-ok">✅ Cancelado (pago)</span>';
    }
    $diff = $em['enviar_em'] - time();
    if ($diff > 0) {
        $h = floor($diff/3600); $m = floor(($diff%3600)/60);
        return '<span class="email-check email-aguardando">⏳ '.$label.' (em '.$h.'h'.$m.'min)</span>';
    }
    return '<span class="email-check email-pendente">⏳ '.$label.' aguardando</span>';
  } ?>
  <tbody>
  <?php foreach ($grupos as $g):
    $tipo = $g['tipo'];
    $emailsDoGrupo = $g['emails'];

    $virou_cliente = false;
    foreach ($clientes as $c) {
        if ($c['email'] === $g['email']) { $virou_cliente = true; break; }
    }

    $e1 = null; $e2 = null;
    foreach ($emailsDoGrupo as $em) {
        $tpl = $em['template'] ?? '';
        if (in_array($tpl, ['email1-30min','email-boleto-30min','email-picpay-30min','email-nubank-30min','email-abandono'])) $e1 = $em;
        if (in_array($tpl, ['email2-24h','email-boleto-24h','email-picpay-24h','email-nubank-24h'])) $e2 = $em;
    }
  ?>
    <tr>
      <td>
        <?= htmlspecialchars($g['nome']) ?>
        <?php if ($virou_cliente): ?><br><span class="cliente-badge">✓ Cliente</span><?php endif; ?>
      </td>
      <td>
        <?php if (($g['canal'] ?? 'email') === 'whatsapp'): ?>
          <span style="display:inline-block;background:#0a2e1a;color:#25d366;font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;">📱 WhatsApp (sem e-mail)</span>
        <?php else: ?>
          <?= htmlspecialchars($g['email']) ?>
        <?php endif; ?>
      </td>
      <td style="color:#9a8fbb;"><?php $tel = preg_replace('/\D/','',$g['telefone']); ?>
        <?php if ($tel !== '' && $g['telefone'] !== '-'): ?>
          <a href="https://wa.me/<?= (strlen($tel) <= 11 ? '55'.$tel : $tel) ?>" target="_blank" style="color:#25d366;text-decoration:none;"><?= htmlspecialchars($g['telefone']) ?></a>
        <?php else: ?><?= htmlspecialchars($g['telefone']) ?><?php endif; ?>
      </td>
      <td><span class="badge tipo-<?= $tipo ?>"><?= ucfirst($tipo) ?></span></td>
      <td><?= ($g['canal'] ?? 'email') === 'whatsapp' ? '<span style="color:#6a5f8a;font-size:12px;">—</span>' : badgeEmail($e1, '1º email') ?></td>
      <td><?= ($g['canal'] ?? 'email') === 'whatsapp' ? '<span style="color:#6a5f8a;font-size:12px;">—</span>' : badgeEmail($e2, '2º email') ?></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= $g['criado_em'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($grupos)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum registro encontrado.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'recuperacao'):
  $rec_pendentes = array_filter($leads_log, fn($l) => !$l['pago'] && $l['tel'] !== '');
?>

<p style="font-size:13px;color:#9a8fbb;margin-bottom:8px;">
  Lista completa direto do <strong>log.txt</strong> (nunca perde ninguém). Clique em <span style="color:#25d366;">Falar no WhatsApp</span> pra abrir a conversa — <strong>sem precisar salvar o número</strong>.
</p>
<div class="cards" style="margin-bottom:18px;">
  <div class="card"><div class="num"><?= count($leads_log) ?></div><div class="label">Total no log</div></div>
  <div class="card"><div class="num" style="color:#c9884c"><?= count($rec_pendentes) ?></div><div class="label">Não pagaram (recuperar)</div></div>
</div>

<table>
  <thead>
    <tr><th>Nome/Contato</th><th>Telefone</th><th>Tipo</th><th>Quando</th><th>Status</th><th>Ação</th></tr>
  </thead>
  <tbody>
  <?php if (empty($leads_log)): ?>
    <tr><td colspan="6" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum lead no log ainda.</td></tr>
  <?php else: foreach ($leads_log as $l):
    $tel = $l['tel'];
    $wa  = strlen($tel) <= 11 ? '55'.$tel : $tel;
  ?>
    <tr>
      <td style="font-size:13px;"><?= htmlspecialchars($l['email'] !== '' ? $l['email'] : '(sem e-mail)') ?></td>
      <td style="color:#9a8fbb;"><?= $tel !== '' ? htmlspecialchars($tel) : '—' ?></td>
      <td><span class="badge tipo-<?= htmlspecialchars($l['tipo']) ?>"><?= ucfirst(htmlspecialchars($l['tipo'])) ?></span></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= htmlspecialchars($l['quando']) ?></td>
      <td>
        <?php if ($l['pago']): ?>
          <span class="cliente-badge">✓ Pagou</span>
        <?php else: ?>
          <span style="color:#c9884c;font-size:12px;font-weight:bold;">⚠️ Não pagou</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($tel !== '' && !$l['pago']): ?>
          <a href="https://wa.me/<?= $wa ?>" target="_blank" style="display:inline-block;background:#25d366;color:#052e16;font-weight:700;font-size:13px;padding:7px 14px;border-radius:8px;text-decoration:none;">💬 Falar no WhatsApp</a>
        <?php elseif ($tel !== ''): ?>
          <a href="https://wa.me/<?= $wa ?>" target="_blank" style="color:#25d366;font-size:12px;text-decoration:none;">abrir chat</a>
        <?php else: ?>
          <span style="color:#6a5f8a;font-size:12px;">sem telefone</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'clientes'): ?>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Nome</th>
      <th>Email</th>
      <th>Telefone</th>
      <th>Comprado em</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($clientes)): ?>
    <tr><td colspan="5" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum cliente ainda.</td></tr>
  <?php else: ?>
    <?php foreach (array_reverse($clientes) as $i => $c): ?>
    <tr>
      <td style="color:#6a5f8a;"><?= count($clientes) - $i ?></td>
      <td><?= htmlspecialchars($c['nome'] ?? '-') ?></td>
      <td><?= htmlspecialchars($c['email'] ?? '-') ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($c['telefone'] ?? '-') ?></td>
      <td style="font-size:13px;color:#2ecc71;"><?= $c['comprado_em'] ?? '-' ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'checkout'):
  // Leads capturados no checkout transparente (email/telefone REAIS, mesmo quem nao pagou)
  $chk_file = __DIR__ . '/../dados/checkout_leads.json';
  $chk = file_exists($chk_file) ? json_decode(@file_get_contents($chk_file), true) : [];
  if (!is_array($chk)) $chk = [];
  // Dedup por telefone, mantendo o registro mais recente
  $chk_uniq = [];
  foreach ($chk as $r) { $chk_uniq[$r['telefone'] ?? uniqid()] = $r; }
  $chk_uniq = array_values($chk_uniq);
?>

<p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
  Contatos <strong>reais</strong> capturados no checkout transparente — <strong>sua lista</strong>, incluindo quem preencheu e <strong>não finalizou</strong> (ótimo pra recuperação). Deduplicado por telefone.
</p>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Data</th>
      <th>Nome</th>
      <th>Email (real)</th>
      <th>Alias (Cakto)</th>
      <th>Telefone</th>
      <th>Método</th>
      <th>Status</th>
      <th>WhatsApp</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($chk_uniq)): ?>
    <tr><td colspan="9" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum lead de checkout ainda.</td></tr>
  <?php else: ?>
    <?php foreach (array_reverse($chk_uniq) as $i => $r):
      $tel = preg_replace('/\D/', '', $r['telefone'] ?? '');
      $wa  = (strlen($tel) <= 11 ? '55'.$tel : $tel);
      $pago = ($r['status'] ?? '') === 'paid';
    ?>
    <tr>
      <td style="color:#6a5f8a;"><?= count($chk_uniq) - $i ?></td>
      <td style="font-size:13px;color:#9a8fbb;"><?= !empty($r['data']) ? date('d/m/y H:i', strtotime($r['data'])) : '-' ?></td>
      <td><?= htmlspecialchars($r['nome'] ?? '-') ?></td>
      <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= htmlspecialchars($r['email_cakto'] ?? $r['alias'] ?? '-') ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($r['telefone'] ?? '-') ?></td>
      <td style="font-size:13px;color:#c080f0;"><?= htmlspecialchars($r['metodo'] ?? '-') ?></td>
      <td style="font-size:13px;color:<?= $pago ? '#2ecc71' : '#f0c060' ?>;"><?= htmlspecialchars($r['status'] ?? '-') ?></td>
      <td>
        <a href="https://wa.me/<?= $wa ?>" target="_blank" style="display:inline-block;background:#25d366;color:#052e16;font-weight:700;font-size:13px;padding:7px 14px;border-radius:8px;text-decoration:none;">💬 Chamar</a>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<?php else: // aba horarios ?>

<div class="cards" style="margin-bottom:20px;">
  <div class="card"><div class="num"><?= $tot_ger ?></div><div class="label">Pgtos gerados</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $tot_pago ?></div><div class="label">Compras pagas</div></div>
  <div class="card"><div class="num" style="color:#c080f0"><?= $tot_ab ?></div><div class="label">Abandonos</div></div>
</div>

<p style="font-size:13px;color:#9a8fbb;margin-bottom:18px;">
  Barra = atividade total (intenção + pago) por hora do dia · <span style="color:#f0c060;">dourado</span> = horários de pico · <span style="color:#2ecc71;">✓ verde</span> = compras pagas na hora.
</p>

<div style="background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:20px;">
<?php for ($i = 0; $i < 24; $i++):
  $w = round($horas_total[$i] / $max_total * 100);
  $isPeak = $horas_total[$i] >= $max_total * 0.6;
?>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;">
    <span style="width:50px;color:#9a8fbb;text-align:right;"><?= sprintf('%02d:00', $i) ?></span>
    <div style="flex:1;background:#1a1040;border-radius:5px;height:24px;overflow:hidden;">
      <div style="width:<?= $w ?>%;height:100%;border-radius:5px;background:<?= $isPeak ? 'linear-gradient(90deg,#c9a84c,#f0c060)' : '#3a2f7e' ?>;min-width:<?= $horas_total[$i] > 0 ? '3px' : '0' ?>;"></div>
    </div>
    <span style="width:28px;font-weight:bold;color:#e8dfc4;text-align:right;"><?= $horas_total[$i] ?></span>
    <span style="width:74px;color:#2ecc71;font-size:12px;"><?= $horas_pago[$i] > 0 ? '✓ '.$horas_pago[$i].' pago' : '' ?></span>
  </div>
<?php endfor; ?>
</div>

<?php endif; ?>

</body>
</html>
