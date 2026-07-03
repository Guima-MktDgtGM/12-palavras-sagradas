<?php
define('ADMIN_USER', 'j.gmarques');
define('ADMIN_PASS', 'Jguimajo2@');
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

// Agrupa leads por email+tipo para mostrar status dos 2 emails em uma linha
$grupos = [];
foreach ($fila as $item) {
    $key = ($item['email'] ?? '') . '||' . ($item['tipo'] ?? '');
    if (!isset($grupos[$key])) {
        $grupos[$key] = [
            'nome'      => $item['nome'] ?? '-',
            'email'     => $item['email'] ?? '-',
            'telefone'  => $item['telefone'] ?? '-',
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
  <a href="?aba=clientes" class="aba <?= $aba==='clientes'?'ativa':'' ?>">🏆 Clientes</a>
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
      <td><?= htmlspecialchars($g['email']) ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($g['telefone']) ?></td>
      <td><span class="badge tipo-<?= $tipo ?>"><?= ucfirst($tipo) ?></span></td>
      <td><?= badgeEmail($e1, '1º email') ?></td>
      <td><?= badgeEmail($e2, '2º email') ?></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= $g['criado_em'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($grupos)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum registro encontrado.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<?php else: // aba clientes ?>

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

<?php endif; ?>

</body>
</html>
