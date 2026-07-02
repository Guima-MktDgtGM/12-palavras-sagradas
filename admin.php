<?php
define('ADMIN_USER', 'j.gmarques');
define('ADMIN_PASS', 'Jguimajo2@');
define('FILA_FILE', __DIR__ . '/emails/fila.json');

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

$fila = [];
if (file_exists(FILA_FILE)) {
    $fila = json_decode(file_get_contents(FILA_FILE), true) ?? [];
}

// Filtros
$filtro_tipo   = $_GET['tipo']   ?? '';
$filtro_status = $_GET['status'] ?? '';

$dados = array_filter($fila, function($item) use ($filtro_tipo, $filtro_status) {
    if ($filtro_tipo   && ($item['tipo']   ?? '') !== $filtro_tipo)   return false;
    if ($filtro_status && ($item['status'] ?? '') !== $filtro_status) return false;
    return true;
});

// Estatísticas
$total     = count($fila);
$pagos     = count(array_filter($fila, fn($i) => ($i['status'] ?? '') === 'pago'));
$enviados  = count(array_filter($fila, fn($i) => ($i['status'] ?? '') === 'enviado'));
$aguardando= count(array_filter($fila, fn($i) => ($i['status'] ?? '') === 'aguardando'));

$labels = [
    'email1-30min'       => 'Pix 30min',
    'email2-24h'         => 'Pix 24h',
    'email-boleto-30min' => 'Boleto 30min',
    'email-boleto-24h'   => 'Boleto 24h',
    'email-abandono'     => 'Abandono',
];
$cores_status = [
    'pago'       => '#2ecc71',
    'enviado'    => '#f0c060',
    'aguardando' => '#9a8fbb',
];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Leads</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;background:#0a0a1a;color:#e8dfc4;font-family:Arial,sans-serif;padding:20px;}
  h1{color:#f0c060;margin:0 0 24px;}
  .cards{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;}
  .card{background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:18px 24px;min-width:140px;text-align:center;}
  .card .num{font-size:32px;font-weight:bold;color:#f0c060;}
  .card .label{font-size:13px;color:#9a8fbb;margin-top:4px;}
  .filtros{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center;}
  select,a.btn{padding:8px 14px;background:#1a1040;border:1px solid #3a2f7e;border-radius:6px;color:#e8dfc4;font-size:14px;text-decoration:none;cursor:pointer;}
  a.btn{background:#2a1f5e;}
  a.logout{color:#ff6b6b;font-size:13px;text-decoration:none;margin-left:auto;}
  table{width:100%;border-collapse:collapse;font-size:14px;}
  th{background:#1a1040;color:#f0c060;padding:10px 12px;text-align:left;border-bottom:1px solid #2a1f5e;}
  td{padding:10px 12px;border-bottom:1px solid #1a1040;vertical-align:middle;}
  tr:hover td{background:#0f0f25;}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:bold;}
  .tipo-pix{background:#1a3a5e;color:#60b0f0;}
  .tipo-boleto{background:#3a2a0e;color:#f0c060;}
  .tipo-abandono{background:#2a1a3a;color:#c080f0;}
  @media(max-width:600px){.cards{flex-direction:column;}.filtros{flex-direction:column;}}
</style>
</head>
<body>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
  <h1>📊 Painel de Leads</h1>
  <a href="?logout=1" class="logout">Sair →</a>
</div>

<div class="cards">
  <div class="card"><div class="num"><?= $total ?></div><div class="label">Total na fila</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $pagos ?></div><div class="label">Pagos ✓</div></div>
  <div class="card"><div class="num"><?= $enviados ?></div><div class="label">Emails enviados</div></div>
  <div class="card"><div class="num" style="color:#9a8fbb"><?= $aguardando ?></div><div class="label">Aguardando</div></div>
</div>

<div class="filtros">
  <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <select name="tipo" onchange="this.form.submit()">
      <option value="">Todos os tipos</option>
      <option value="pix" <?= $filtro_tipo==='pix'?'selected':'' ?>>Pix</option>
      <option value="boleto" <?= $filtro_tipo==='boleto'?'selected':'' ?>>Boleto</option>
      <option value="abandono" <?= $filtro_tipo==='abandono'?'selected':'' ?>>Abandono</option>
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="">Todos os status</option>
      <option value="aguardando" <?= $filtro_status==='aguardando'?'selected':'' ?>>Aguardando</option>
      <option value="enviado" <?= $filtro_status==='enviado'?'selected':'' ?>>Enviado</option>
      <option value="pago" <?= $filtro_status==='pago'?'selected':'' ?>>Pago</option>
    </select>
    <a href="admin.php" class="btn">Limpar filtros</a>
  </form>
</div>

<table>
  <thead>
    <tr>
      <th>Nome</th>
      <th>Email</th>
      <th>Telefone</th>
      <th>Tipo</th>
      <th>Email</th>
      <th>Status</th>
      <th>Criado em</th>
      <th>Enviado em</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach (array_reverse($dados) as $item): ?>
    <?php
      $status = $item['status'] ?? 'aguardando';
      $cor = $cores_status[$status] ?? '#9a8fbb';
      $tipo = $item['tipo'] ?? '-';
    ?>
    <tr>
      <td><?= htmlspecialchars($item['nome'] ?? '-') ?></td>
      <td><?= htmlspecialchars($item['email'] ?? '-') ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($item['telefone'] ?? '-') ?></td>
      <td><span class="badge tipo-<?= $tipo ?>"><?= ucfirst($tipo) ?></span></td>
      <td style="color:#9a8fbb;font-size:13px;"><?= $labels[$item['template']] ?? $item['template'] ?></td>
      <td><span class="badge" style="background:<?= $cor ?>22;color:<?= $cor ?>"><?= ucfirst($status) ?></span></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= $item['criado_em'] ?? '-' ?></td>
      <td style="font-size:12px;color:#6a5f8a;"><?= $item['enviado_em'] ?? '-' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($dados)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum registro encontrado.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</body>
</html>
