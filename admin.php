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
        session_regenerate_id(true);
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
  <?php if (isset($erro)) echo '<p class="erro">'.htmlspecialchars($erro).'</p>'; ?>
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

// ─── Biblioteca compartilhada com o webhook ──────────────────────────────────
$rd_lib_ok = false;
try {
    $rd_lib = __DIR__ . '/leads_lib.php';
    if (file_exists($rd_lib)) { require_once $rd_lib; $rd_lib_ok = function_exists('rd_extrair'); }
} catch (\Throwable $e) {
    $rd_lib_ok = false;
    $rd_lib_erro = $e->getMessage();
}

// Token anti-CSRF para as ações que gravam arquivo.
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$aviso = '';

// ─── Ações (POST) ────────────────────────────────────────────────────────────
if ($rd_lib_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $aviso = 'Sessão expirada. Recarregue a página e tente de novo.';
    } elseif ($_POST['acao'] === 'contatado') {
        rd_contatado_alternar((string)($_POST['id'] ?? ''));
        // Só aceita voltar para a própria página (evita redirecionamento aberto).
        $voltar = (string)($_POST['voltar'] ?? '');
        if (strpos($voltar, 'admin.php') !== 0) $voltar = 'admin.php';
        header('Location: ' . $voltar);
        exit;
    } elseif ($_POST['acao'] === 'reconstruir') {
        // Reconstrói leads.json e atribuicao.json a partir do histórico de payloads.
        $n = rd_reconstruir_historico();
        $aviso = "Histórico reconstruído: {$n['leads']} leads, {$n['vendas']} vendas e {$n['atrib']} registros de campanha.";
    }
}

// Reconstrução: lê o webhook_debug.txt (payloads crus) em ordem cronológica.
function rd_reconstruir_historico() {
    $arquivo = RD_DEBUG_FILE;
    $leads  = [];
    $atrib  = [];
    $vendas = [];
    if (!file_exists($arquivo)) return ['leads' => 0, 'atrib' => 0, 'vendas' => 0];

    $fh = fopen($arquivo, 'r');
    if (!$fh) return ['leads' => 0, 'atrib' => 0, 'vendas' => 0];

    while (($linha = fgets($fh)) !== false) {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (\{.*)$/', rtrim($linha), $m)) continue;
        $payload = json_decode($m[2], true);
        if (!is_array($payload)) continue;          // payload cortado em 4000 chars

        $info = rd_extrair($payload);
        $info['quando'] = $m[1];                    // horário real do evento, não o de agora

        $tipo = rd_tipo_do_evento($info['evento']);
        if ($tipo !== '') {
            $leads = rd_lead_registrar($info, $tipo, $leads);
        } elseif (in_array(strtolower($info['evento']), ['purchase_approved','compra_aprovada'])) {
            $leads  = rd_marcar_pago_array($leads, $info['email'], $info['telefone'], $info['quando']);
            $vendas = rd_venda_registrar($info, $vendas);
        }

        if (!empty($info['utm']['utm_source'])) {
            $atrib[] = [
                'order_id' => $info['order_id'],
                'cpf'      => $info['cpf'],
                'email'    => $info['email'],
                'telefone' => $info['telefone'],
                'utm'      => $info['utm'],
                'quando'   => $info['quando'],
            ];
        }
    }
    fclose($fh);

    if (count($atrib) > RD_MAX_ATRIB) $atrib = array_slice($atrib, -RD_MAX_ATRIB);
    rd_json_gravar(RD_LEADS_FILE, $leads);
    rd_json_gravar(RD_VENDAS_FILE, $vendas);
    rd_json_gravar(RD_ATRIB_FILE, ['registros' => $atrib]);
    return ['leads' => count($leads), 'atrib' => count($atrib), 'vendas' => count($vendas)];
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

$leads      = $rd_lib_ok ? rd_json_ler(RD_LEADS_FILE) : [];
$contatados = $rd_lib_ok ? rd_contatados() : [];

// Ordena por mais recente
usort($leads, fn($a, $b) => strcmp((string)($b['quando'] ?? ''), (string)($a['quando'] ?? '')));

$TIPOS_PAGAMENTO = ['pix', 'boleto', 'picpay', 'nubank', 'cartao_recusado'];

$busca = trim((string)($_GET['q'] ?? ''));
function rd_casa_busca($L, $q) {
    if ($q === '') return true;
    $alvo = mb_strtolower(($L['nome'] ?? '') . ' ' . ($L['email'] ?? '') . ' ' . ($L['telefone'] ?? ''));
    return mb_strpos($alvo, mb_strtolower($q)) !== false;
}

// Índice de quem já é cliente (por e-mail e por telefone).
$cli_email = [];
$cli_tel   = [];
foreach ($clientes as $c) {
    $e = strtolower(trim((string)($c['email'] ?? '')));
    if ($e !== '') $cli_email[$e] = true;
    $t = preg_replace('/\D/', '', (string)($c['telefone'] ?? ''));
    if ($t !== '') $cli_tel[$t] = true;
}
// Abandono de quem JÁ COMPROU não é lead de recuperação — não adianta chamar
// no WhatsApp perguntando por que não finalizou se a pessoa finalizou.
// Vale só pro abandono: um Pix pendente de um cliente pode ser um segundo pedido real.
function rd_e_cliente($L, $cli_email, $cli_tel) {
    $e = strtolower(trim((string)($L['email'] ?? '')));
    if ($e !== '' && isset($cli_email[$e])) return true;
    $t = preg_replace('/\D/', '', (string)($L['telefone'] ?? ''));
    return $t !== '' && isset($cli_tel[$t]);
}

$leads_abandono = array_values(array_filter($leads, fn($L) => ($L['tipo'] ?? '') === 'abandono' && empty($L['pago']) && !rd_e_cliente($L, $cli_email, $cli_tel) && rd_casa_busca($L, $busca)));
$leads_pgto     = array_values(array_filter($leads, fn($L) => in_array($L['tipo'] ?? '', $TIPOS_PAGAMENTO) && empty($L['pago']) && rd_casa_busca($L, $busca)));

// ─── Horários (lê só o final do log, que já passou de 38 MB) ────────────────
$LOG_FILE   = __DIR__ . '/../dados/log.txt';
$horas_ger  = array_fill(0, 24, 0);
$horas_pago = array_fill(0, 24, 0);
$horas_ab   = array_fill(0, 24, 0);
if (file_exists($LOG_FILE)) {
    $fh = fopen($LOG_FILE, 'r');
    if ($fh) {
        $tam   = filesize($LOG_FILE);
        $limite = 4 * 1024 * 1024; // últimos 4 MB
        if ($tam > $limite) { fseek($fh, $tam - $limite); fgets($fh); }
        while (($linha = fgets($fh)) !== false) {
            if (strpos($linha, 'evento=') === false) continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}):\d{2}:\d{2} evento=(\w+)/', $linha, $m)) {
                $h = (int)$m[1]; $ev = $m[2];
                if ($ev === 'purchase_approved') $horas_pago[$h]++;
                elseif (in_array($ev, ['pix_gerado','boleto_gerado','picpay_gerado','nubank_gerado'])) $horas_ger[$h]++;
                elseif ($ev === 'checkout_abandonment') $horas_ab[$h]++;
            }
        }
        fclose($fh);
    }
}
$horas_total = [];
for ($i = 0; $i < 24; $i++) $horas_total[$i] = $horas_ger[$i] + $horas_pago[$i];
$max_total = max(1, max($horas_total));
$tot_ger   = array_sum($horas_ger);
$tot_pago  = array_sum($horas_pago);
$tot_ab    = array_sum($horas_ab);

// ─── Números do topo ─────────────────────────────────────────────────────────
$hoje           = date('Y-m-d');
$total_clientes = count($clientes);

// Vendas de hoje contadas por PEDIDO (inclui order bump, upsell e recompra) —
// diferente de clientes.json, que só ganha linha quando o e-mail é inédito.
$vendas_arq  = $rd_lib_ok ? rd_json_ler(RD_VENDAS_FILE) : [];
$hoje_stats  = $rd_lib_ok ? rd_vendas_do_dia($vendas_arq, $hoje) : ['qtd' => 0, 'total' => 0];
$vendas_hoje = $hoje_stats['qtd'];
$fat_hoje    = $hoje_stats['total'];
$qtd_abandono   = count(array_filter($leads, fn($L) => ($L['tipo'] ?? '') === 'abandono' && empty($L['pago']) && !rd_e_cliente($L, $cli_email, $cli_tel)));
$qtd_pgto       = count(array_filter($leads, fn($L) => in_array($L['tipo'] ?? '', $TIPOS_PAGAMENTO) && empty($L['pago'])));

$aba = $_GET['aba'] ?? 'abandono';

$labels = [
    'email1-30min'       => 'Pix 30min',
    'email2-24h'         => 'Pix 24h',
    'email-boleto-30min' => 'Boleto 30min',
    'email-boleto-24h'   => 'Boleto 24h',
    'email-abandono'     => 'Abandono',
];

// ─── Mensagem pronta de WhatsApp por tipo de lead ────────────────────────────
function rd_msg_wa($tipo, $nome) {
    $p = rd_primeiro_nome($nome);
    $ola = $p !== '' ? "Olá, $p! " : 'Olá! ';
    switch ($tipo) {
        case 'abandono':
            return $ola . "Aqui é o Gabriel, do Roteiro Divino das 12 Palavras. 🙏\n\n"
                 . "Vi que você começou a garantir o seu acesso mas não chegou a concluir. Aconteceu alguma dificuldade na hora do pagamento?\n\n"
                 . "Se quiser, eu te ajudo por aqui mesmo, é rapidinho.";
        case 'pix':
            return $ola . "Aqui é o Gabriel, do Roteiro Divino das 12 Palavras. 🙏\n\n"
                 . "Seu Pix foi gerado mas ainda não constou o pagamento aqui. O código tem prazo pra vencer — se ele já expirou, eu gero um novo pra você agora.\n\n"
                 . "Quer que eu envie?";
        case 'boleto':
            return $ola . "Aqui é o Gabriel, do Roteiro Divino das 12 Palavras. 🙏\n\n"
                 . "Seu boleto foi gerado e ainda não compensou. O boleto demora até 3 dias úteis pra cair.\n\n"
                 . "Se preferir receber o acesso na hora, eu te mando um Pix — libera em segundos.";
        case 'cartao_recusado':
            return $ola . "Aqui é o Gabriel, do Roteiro Divino das 12 Palavras. 🙏\n\n"
                 . "Seu cartão foi recusado pelo banco — isso é comum em compras pela internet e não tem nada a ver com o seu limite.\n\n"
                 . "O jeito mais rápido de resolver é pelo Pix, que libera o acesso na hora. Quer que eu gere pra você?";
        default:
            return $ola . "Aqui é o Gabriel, do Roteiro Divino das 12 Palavras. 🙏 Posso te ajudar a concluir seu acesso?";
    }
}

function rd_url_atual() {
    return 'admin.php?' . http_build_query(array_filter([
        'aba' => $_GET['aba'] ?? null,
        'q'   => $_GET['q'] ?? null,
    ]));
}
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

  .cards{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;}
  .card{background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:18px 24px;min-width:140px;text-align:center;}
  .card .num{font-size:32px;font-weight:bold;color:#f0c060;}
  .card .label{font-size:13px;color:#9a8fbb;margin-top:4px;}

  .abas{display:flex;gap:0;margin-bottom:24px;border-bottom:2px solid #2a1f5e;flex-wrap:wrap;}
  .aba{padding:10px 22px;text-decoration:none;color:#9a8fbb;font-size:15px;font-weight:bold;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s;}
  .aba:hover{color:#e8dfc4;}
  .aba.ativa{color:#f0c060;border-bottom-color:#f0c060;}

  .filtros{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center;}
  select,a.btn,input[type=text],button.btn{padding:8px 14px;background:#1a1040;border:1px solid #3a2f7e;border-radius:6px;color:#e8dfc4;font-size:14px;text-decoration:none;cursor:pointer;}
  a.btn,button.btn{background:#2a1f5e;}

  table{width:100%;border-collapse:collapse;font-size:14px;}
  th{background:#1a1040;color:#f0c060;padding:10px 12px;text-align:left;border-bottom:1px solid #2a1f5e;}
  td{padding:10px 12px;border-bottom:1px solid #1a1040;vertical-align:middle;}
  tr:hover td{background:#0f0f25;}
  tr.feito td{opacity:.45;}
  .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:bold;}
  .tipo-pix{background:#1a3a5e;color:#60b0f0;}
  .tipo-boleto{background:#3a2a0e;color:#f0c060;}
  .tipo-picpay{background:#0a2e1a;color:#00d4a0;}
  .tipo-nubank{background:#2a0a3a;color:#c084f0;}
  .tipo-abandono{background:#2a1a3a;color:#c080f0;}
  .tipo-cartao_recusado{background:#3a1414;color:#ff8080;}

  .email-check{display:inline-flex;align-items:center;gap:4px;font-size:12px;padding:3px 8px;border-radius:12px;margin:2px 0;}
  .email-ok{background:#0d2e1a;color:#2ecc71;}
  .email-pendente{background:#1a1a2e;color:#6a5f8a;}
  .email-aguardando{background:#1a1a2e;color:#c9884c;}
  .cliente-badge{background:#1a3e2a;color:#2ecc71;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:bold;}

  .wa{display:inline-block;background:#25d366;color:#052e16;font-weight:700;font-size:13px;padding:7px 14px;border-radius:8px;text-decoration:none;white-space:nowrap;}
  .marcar{background:none;border:1px solid #3a2f7e;color:#9a8fbb;border-radius:6px;padding:5px 9px;font-size:12px;cursor:pointer;}
  .marcar.on{border-color:#2ecc71;color:#2ecc71;}
  .campanha{font-size:12px;color:#c9a84c;}
  .aviso{background:#1a1040;border:1px solid #3a2f7e;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:14px;color:#f0c060;}

  @media(max-width:600px){.cards{flex-direction:column;}.filtros{flex-direction:column;align-items:stretch;}}
</style>
</head>
<body>

<div class="topbar">
  <h1>📊 Painel Admin</h1>
  <a href="?logout=1" class="logout">Sair →</a>
</div>

<?php if (!$rd_lib_ok): ?>
  <div class="aviso" style="border-color:#ff6b6b;color:#ff6b6b;">
    ⚠️ O arquivo <strong>leads_lib.php</strong> não foi carregado. As abas de Abandono e Pix não pago ficam vazias até isso ser resolvido.
    <?= isset($rd_lib_erro) ? '<br><small>' . htmlspecialchars($rd_lib_erro) . '</small>' : '' ?>
  </div>
<?php endif; ?>

<?php if ($aviso): ?>
  <div class="aviso"><?= htmlspecialchars($aviso) ?></div>
<?php endif; ?>

<div class="cards">
  <div class="card"><div class="num" style="color:#c080f0"><?= $qtd_abandono ?></div><div class="label">Abandonos a recuperar</div></div>
  <div class="card"><div class="num"><?= $qtd_pgto ?></div><div class="label">Pagamentos pendentes</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $total_clientes ?></div><div class="label">Clientes pagos ✓</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $vendas_hoje ?></div><div class="label">Pedidos hoje</div></div>
  <div class="card"><div class="num" style="color:#2ecc71;font-size:24px;">R$ <?= number_format((float)$fat_hoje, 2, ',', '.') ?></div><div class="label">Faturamento hoje</div></div>
</div>

<div class="abas">
  <a href="?aba=abandono"   class="aba <?= $aba==='abandono'?'ativa':'' ?>">🛒 Abandono de checkout</a>
  <a href="?aba=pendentes"  class="aba <?= $aba==='pendentes'?'ativa':'' ?>">⏳ Gerou e não pagou</a>
  <a href="?aba=vendas"     class="aba <?= $aba==='vendas'?'ativa':'' ?>">💰 Vendas e campanha</a>
  <a href="?aba=clientes"   class="aba <?= $aba==='clientes'?'ativa':'' ?>">🏆 Clientes</a>
  <a href="?aba=emails"     class="aba <?= $aba==='emails'?'ativa':'' ?>">✉️ Fila de e-mails</a>
  <a href="?aba=checkout"   class="aba <?= $aba==='checkout'?'ativa':'' ?>">🧾 Checkout (antigo)</a>
  <a href="?aba=horarios"   class="aba <?= $aba==='horarios'?'ativa':'' ?>">📊 Horários</a>
</div>

<?php
// ─── Tabela reutilizada pelas duas abas de recuperação ──────────────────────
function rd_tabela_leads($lista, $contatados, $csrf, $vazio) { ?>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Data</th>
      <th>Nome</th>
      <th>Contato</th>
      <th>Tipo</th>
      <th>Valor</th>
      <th>Veio de</th>
      <th>WhatsApp</th>
      <th>Feito</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($lista)): ?>
    <tr><td colspan="9" style="text-align:center;color:#6a5f8a;padding:30px;"><?= htmlspecialchars($vazio) ?></td></tr>
  <?php else: $n = count($lista); foreach ($lista as $i => $L):
      $id     = (string)($L['id'] ?? '');
      $feito  = isset($contatados[$id]);
      $tipo   = (string)($L['tipo'] ?? '');
      $wa     = rd_wa_link($L['telefone'] ?? '', rd_msg_wa($tipo, $L['nome'] ?? ''));
      $camp   = rd_campanha($L['utm'] ?? []);
      $anun   = rd_anuncio($L['utm'] ?? []);
  ?>
    <tr class="<?= $feito ? 'feito' : '' ?>">
      <td style="color:#6a5f8a;"><?= $n - $i ?></td>
      <td style="font-size:13px;color:#9a8fbb;white-space:nowrap;"><?= rd_fmt_data($L['quando'] ?? '') ?></td>
      <td><?= htmlspecialchars($L['nome'] ?? '-') ?></td>
      <td style="font-size:13px;">
        <?= htmlspecialchars($L['email'] ?? '-') ?>
        <?php if (!empty($L['telefone'])): ?><br><span style="color:#9a8fbb;"><?= htmlspecialchars($L['telefone']) ?></span><?php endif; ?>
      </td>
      <td>
        <span class="badge tipo-<?= htmlspecialchars($tipo) ?>"><?= htmlspecialchars(rd_rotulo_tipo($tipo)) ?></span>
        <?php if (intval($L['tentativas'] ?? 1) > 1): ?>
          <br><small style="color:#6a5f8a;"><?= intval($L['tentativas']) ?>x tentou</small>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap;"><?= !empty($L['valor']) ? 'R$ ' . number_format((float)$L['valor'], 2, ',', '.') : '-' ?></td>
      <td class="campanha">
        <?= $camp !== '' ? htmlspecialchars($camp) : '<span style="color:#4a4160;">sem rastreio</span>' ?>
        <?php if ($anun !== ''): ?><br><small style="color:#6a5f8a;">anúncio <?= htmlspecialchars($anun) ?></small><?php endif; ?>
      </td>
      <td>
        <?php if ($wa !== ''): ?>
          <a class="wa" href="<?= htmlspecialchars($wa) ?>" target="_blank" rel="noopener">💬 Chamar</a>
        <?php else: ?>
          <span style="color:#4a4160;font-size:12px;">sem telefone</span>
        <?php endif; ?>
      </td>
      <td>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="acao" value="contatado">
          <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
          <input type="hidden" name="voltar" value="<?= htmlspecialchars(rd_url_atual()) ?>">
          <button type="submit" class="marcar <?= $feito ? 'on' : '' ?>"><?= $feito ? '✓ falei' : 'marcar' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
<?php }

// ─── Busca (usada nas duas abas de recuperação) ─────────────────────────────
function rd_form_busca($aba, $busca) { ?>
<div class="filtros">
  <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="aba" value="<?= htmlspecialchars($aba) ?>">
    <input type="text" name="q" placeholder="Buscar nome, e-mail ou telefone" value="<?= htmlspecialchars($busca) ?>" style="min-width:280px;">
    <button type="submit" class="btn">Buscar</button>
    <?php if ($busca !== ''): ?><a href="?aba=<?= htmlspecialchars($aba) ?>" class="btn">Limpar</a><?php endif; ?>
  </form>
</div>
<?php }
?>

<?php if ($aba === 'abandono'): ?>

  <p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
    Pessoas que preencheram os dados no checkout da Cakto e <strong>não finalizaram</strong>. Quem já comprou sai da lista sozinho.
  </p>
  <?php rd_form_busca('abandono', $busca); ?>
  <?php rd_tabela_leads($leads_abandono, $contatados, $csrf, 'Nenhum abandono registrado ainda.'); ?>

<?php elseif ($aba === 'pendentes'): ?>

  <p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
    Gerou Pix, boleto ou teve o <strong>cartão recusado</strong> e ainda não pagou. Estes continuam recebendo o e-mail automático de recuperação.
  </p>
  <?php rd_form_busca('pendentes', $busca); ?>
  <?php rd_tabela_leads($leads_pgto, $contatados, $csrf, 'Nenhum pagamento pendente.'); ?>

<?php elseif ($aba === 'vendas'):
  // Pedidos aprovados, com a campanha de origem. Quando o pedido chega sem UTM
  // (upsell one-click), herda a campanha da compra anterior do mesmo cliente —
  // a mesma lógica que o webhook usa pra mandar a atribuição pra UTMify.
  $lista_vendas = array_reverse($vendas_arq);
  if ($busca !== '') {
      $lista_vendas = array_values(array_filter($lista_vendas, fn($V) =>
          mb_strpos(mb_strtolower(($V['nome'] ?? '') . ' ' . ($V['email'] ?? '') . ' ' . ($V['telefone'] ?? '') . ' ' . ($V['produto'] ?? '')), mb_strtolower($busca)) !== false));
  }
  $lista_vendas = array_slice($lista_vendas, 0, 300);
?>

<p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
  Cada <strong>pedido aprovado</strong> com a campanha que originou a venda. Busque pelo e-mail do cliente
  para descobrir de onde veio — inclusive nos <strong>upsells</strong>, que chegam sem rastreio e herdam a campanha da compra anterior.
</p>

<?php rd_form_busca('vendas', $busca); ?>

<table>
  <thead>
    <tr><th>Data</th><th>Cliente</th><th>Produto</th><th>Valor</th><th>Método</th><th>Campanha</th><th>Anúncio</th></tr>
  </thead>
  <tbody>
  <?php if (empty($lista_vendas)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6a5f8a;padding:30px;">
      <?= $busca !== '' ? 'Nenhuma venda encontrada para essa busca.' : 'Nenhuma venda registrada ainda — rode o "Reconstruir agora" na aba Horários.' ?>
    </td></tr>
  <?php else: foreach ($lista_vendas as $V):
      $utm  = is_array($V['utm'] ?? null) ? $V['utm'] : [];
      $camp = rd_campanha($utm);
      $anun = rd_anuncio($utm);
      $herdado = false;
      // Sem UTM no próprio pedido: procura a compra anterior do mesmo cliente.
      if ($camp === '') {
          $pai = rd_atribuicao_buscar([
              'parent_order' => '', 'cpf' => '',
              'email' => $V['email'] ?? '', 'telefone' => $V['telefone'] ?? '',
          ], 24);
          if ($pai) { $utm = $pai['utm']; $camp = rd_campanha($utm); $anun = rd_anuncio($utm); $herdado = $camp !== ''; }
      }
      $liq = (float)($V['liquido'] ?? 0); if ($liq <= 0) $liq = (float)($V['valor'] ?? 0);
  ?>
    <tr>
      <td style="font-size:13px;color:#9a8fbb;white-space:nowrap;"><?= rd_fmt_data($V['quando'] ?? '') ?></td>
      <td style="font-size:13px;">
        <?= htmlspecialchars($V['nome'] ?? '-') ?><br>
        <span style="color:#9a8fbb;"><?= htmlspecialchars($V['email'] ?? '-') ?></span>
      </td>
      <td style="font-size:13px;"><?= htmlspecialchars($V['produto'] ?? '-') ?></td>
      <td style="white-space:nowrap;color:#2ecc71;">R$ <?= number_format($liq, 2, ',', '.') ?></td>
      <td style="font-size:13px;color:#c080f0;"><?= htmlspecialchars($V['metodo'] ?? '-') ?></td>
      <td class="campanha">
        <?php if ($camp !== ''): ?>
          <?= htmlspecialchars($camp) ?>
          <?php if ($herdado): ?><br><small style="color:#6a5f8a;">herdado da compra anterior</small><?php endif; ?>
        <?php else: ?>
          <span style="color:#4a4160;">sem rastreio</span>
        <?php endif; ?>
      </td>
      <td style="font-size:13px;color:#9a8fbb;"><?= $anun !== '' ? htmlspecialchars($anun) : '-' ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'clientes'): ?>

<table>
  <thead>
    <tr><th>#</th><th>Nome</th><th>Email</th><th>Telefone</th><th>Comprado em</th><th>WhatsApp</th></tr>
  </thead>
  <tbody>
  <?php if (empty($clientes)): ?>
    <tr><td colspan="6" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum cliente ainda.</td></tr>
  <?php else: ?>
    <?php foreach (array_reverse($clientes) as $i => $c):
      $telc = $rd_lib_ok ? rd_wa_numero($c['telefone'] ?? '') : '';
    ?>
    <tr>
      <td style="color:#6a5f8a;"><?= count($clientes) - $i ?></td>
      <td><?= htmlspecialchars($c['nome'] ?? '-') ?></td>
      <td><?= htmlspecialchars($c['email'] ?? '-') ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($c['telefone'] ?? '-') ?></td>
      <td style="font-size:13px;color:#2ecc71;white-space:nowrap;"><?= $rd_lib_ok ? rd_fmt_data($c['comprado_em'] ?? '') : htmlspecialchars($c['comprado_em'] ?? '-') ?></td>
      <td><?php if ($telc !== ''): ?><a class="wa" href="https://wa.me/<?= $telc ?>" target="_blank" rel="noopener">💬 Chamar</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'emails'):
  // Agrupa a fila por contato+tipo pra mostrar o status dos 2 e-mails numa linha só.
  $grupos = [];
  foreach ($fila as $item) {
      $lead_id = ($item['email'] ?? '') !== '' ? $item['email'] : ($item['telefone'] ?? '');
      $key = $lead_id . '||' . ($item['tipo'] ?? '');
      if (!isset($grupos[$key])) {
          $grupos[$key] = [
              'nome' => $item['nome'] ?? '-', 'email' => $item['email'] ?? '-',
              'telefone' => $item['telefone'] ?? '-', 'tipo' => $item['tipo'] ?? '-',
              'criado_em' => $item['criado_em'] ?? '-', 'emails' => [],
          ];
      }
      $grupos[$key]['emails'][] = $item;
  }
  $grupos = array_values($grupos);
  usort($grupos, fn($a,$b) => strcmp($b['criado_em'], $a['criado_em']));
  $grupos = array_slice($grupos, 0, 300);
?>

<p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
  Status dos e-mails automáticos de recuperação (300 mais recentes). <strong>pago</strong> = a pessoa comprou e os e-mails pendentes foram cancelados.
</p>

<table>
  <thead>
    <tr><th>Data</th><th>Nome</th><th>Email</th><th>Tipo</th><th>Envios</th></tr>
  </thead>
  <tbody>
  <?php if (empty($grupos)): ?>
    <tr><td colspan="5" style="text-align:center;color:#6a5f8a;padding:30px;">Fila vazia.</td></tr>
  <?php else: foreach ($grupos as $g): ?>
    <tr>
      <td style="font-size:13px;color:#9a8fbb;white-space:nowrap;"><?= $rd_lib_ok ? rd_fmt_data($g['criado_em']) : htmlspecialchars($g['criado_em']) ?></td>
      <td><?= htmlspecialchars($g['nome']) ?></td>
      <td style="font-size:13px;"><?= htmlspecialchars($g['email']) ?></td>
      <td><span class="badge tipo-<?= htmlspecialchars($g['tipo']) ?>"><?= htmlspecialchars($g['tipo']) ?></span></td>
      <td>
        <?php foreach ($g['emails'] as $e):
          $st = $e['status'] ?? 'aguardando';
          $cls = $st === 'enviado' ? 'email-ok' : ($st === 'pago' ? 'email-pendente' : 'email-aguardando');
          $lb = $labels[$e['template'] ?? ''] ?? ($e['template'] ?? '?');
        ?>
          <span class="email-check <?= $cls ?>"><?= htmlspecialchars($lb) ?> · <?= htmlspecialchars($st) ?></span>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php elseif ($aba === 'checkout'):
  $chk_file = __DIR__ . '/../dados/checkout_leads.json';
  $chk = file_exists($chk_file) ? json_decode(@file_get_contents($chk_file), true) : [];
  if (!is_array($chk)) $chk = [];
  $chk_uniq = [];
  foreach ($chk as $r) { $chk_uniq[$r['telefone'] ?? uniqid()] = $r; }
  $chk_uniq = array_values($chk_uniq);
?>

<p style="font-size:14px;color:#9a8fbb;margin-bottom:18px;">
  Histórico do <strong>checkout transparente</strong> (desligado desde julho/2026). O status vinha da resposta da API da Cakto no momento da cobrança — por isso aparece <em>refused</em>, <em>pending</em> e <em>waiting_payment</em> aqui e em nenhum outro lugar.
</p>

<table>
  <thead>
    <tr><th>#</th><th>Data</th><th>Nome</th><th>Email (real)</th><th>Telefone</th><th>Método</th><th>Status</th><th>WhatsApp</th></tr>
  </thead>
  <tbody>
  <?php if (empty($chk_uniq)): ?>
    <tr><td colspan="8" style="text-align:center;color:#6a5f8a;padding:30px;">Nenhum lead de checkout.</td></tr>
  <?php else: ?>
    <?php foreach (array_reverse($chk_uniq) as $i => $r):
      $tel = preg_replace('/\D/', '', $r['telefone'] ?? '');
      $wa  = (strlen($tel) <= 11 ? '55'.$tel : $tel);
      $pago = ($r['status'] ?? '') === 'paid';
    ?>
    <tr>
      <td style="color:#6a5f8a;"><?= count($chk_uniq) - $i ?></td>
      <td style="font-size:13px;color:#9a8fbb;white-space:nowrap;"><?= $rd_lib_ok ? rd_fmt_data($r['data'] ?? '') : '-' ?></td>
      <td><?= htmlspecialchars($r['nome'] ?? '-') ?></td>
      <td style="font-size:13px;"><?= htmlspecialchars($r['email'] ?? '-') ?></td>
      <td style="color:#9a8fbb;"><?= htmlspecialchars($r['telefone'] ?? '-') ?></td>
      <td style="font-size:13px;color:#c080f0;"><?= htmlspecialchars($r['metodo'] ?? '-') ?></td>
      <td style="font-size:13px;color:<?= $pago ? '#2ecc71' : '#f0c060' ?>;"><?= htmlspecialchars($r['status'] ?? '-') ?></td>
      <td><?php if ($tel !== ''): ?><a class="wa" href="https://wa.me/<?= $wa ?>" target="_blank" rel="noopener">💬 Chamar</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

<?php else: // horarios ?>

<div class="cards" style="margin-bottom:20px;">
  <div class="card"><div class="num"><?= $tot_ger ?></div><div class="label">Pgtos gerados</div></div>
  <div class="card"><div class="num" style="color:#2ecc71"><?= $tot_pago ?></div><div class="label">Compras pagas</div></div>
  <div class="card"><div class="num" style="color:#c080f0"><?= $tot_ab ?></div><div class="label">Abandonos</div></div>
</div>

<p style="font-size:13px;color:#9a8fbb;margin-bottom:18px;">
  Baseado no período mais recente do log · barra = intenção + pago por hora · <span style="color:#2ecc71;">✓ verde</span> = compras pagas naquela hora.
</p>

<div style="background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:20px;margin-bottom:26px;">
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

<?php if ($rd_lib_ok): ?>
<div style="background:#0d0d2b;border:1px solid #2a1f5e;border-radius:10px;padding:20px;">
  <p style="margin:0 0 10px;font-size:14px;color:#f0c060;font-weight:bold;">🔄 Reconstruir histórico de leads</p>
  <p style="margin:0 0 14px;font-size:13px;color:#9a8fbb;line-height:1.5;">
    Lê os payloads já recebidos (<code>webhook_debug.txt</code>) e remonta as abas de Abandono e Pendentes com tudo que chegou até agora,
    inclusive a campanha de origem. Pode rodar quantas vezes quiser — o resultado é sempre o mesmo.
  </p>
  <form method="POST" style="margin:0;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="acao" value="reconstruir">
    <button type="submit" class="btn">Reconstruir agora</button>
  </form>
</div>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
