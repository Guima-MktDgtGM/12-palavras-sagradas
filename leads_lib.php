<?php
/**
 * leads_lib.php — funcoes compartilhadas entre webhook.php e admin.php.
 *
 * Guarda os leads num formato unico (/dados/leads.json) e a atribuicao de
 * campanha (/dados/atribuicao.json), usada pra herdar UTM nos upsells one-click.
 *
 * Nao e uma pagina: se alguem abrir direto no navegador, nao devolve nada.
 */
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404); exit;
}

if (!defined('RD_DADOS_DIR')) define('RD_DADOS_DIR', __DIR__ . '/../dados');
define('RD_LEADS_FILE',      RD_DADOS_DIR . '/leads.json');
define('RD_ATRIB_FILE',      RD_DADOS_DIR . '/atribuicao.json');
define('RD_CONTATADOS_FILE', RD_DADOS_DIR . '/contatados.json');
define('RD_DEBUG_FILE',      RD_DADOS_DIR . '/webhook_debug.txt');

// Quantos registros manter em cada arquivo (evita crescer sem limite).
define('RD_MAX_LEADS', 5000);
define('RD_MAX_ATRIB', 3000);

// ---------------------------------------------------------------------------
// Leitura/escrita atomica (grava em .tmp e renomeia — nunca deixa JSON pela metade)
// ---------------------------------------------------------------------------
function rd_json_ler($arquivo) {
    if (!file_exists($arquivo)) return [];
    $txt = @file_get_contents($arquivo);
    if ($txt === false || $txt === '') return [];
    $d = json_decode($txt, true);
    return is_array($d) ? $d : [];
}

function rd_json_gravar($arquivo, $dados) {
    $tmp = $arquivo . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($ok === false) return false;
    return @rename($tmp, $arquivo);
}

// ---------------------------------------------------------------------------
// Normalizacao do payload da Cakto
//
// IMPORTANTE: a Cakto usa DOIS formatos diferentes.
//   - pix_gerado / purchase_approved / boleto_gerado -> data.customer.{name,email,phone}
//   - checkout_abandonment                           -> data.customerName/customerEmail/customerCellphone
// Era por isso que todo abandono chegava sem contato e era descartado.
// ---------------------------------------------------------------------------
function rd_so_digitos($v) { return preg_replace('/\D/', '', (string)$v); }

function rd_utm_da_url($url) {
    $out = [];
    if (!$url) return $out;
    $qs = parse_url($url, PHP_URL_QUERY);
    if (!$qs) return $out;
    parse_str($qs, $p);
    foreach (['utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_id','sck','src','fbclid'] as $k) {
        if (!empty($p[$k]) && is_string($p[$k])) $out[$k] = $p[$k];
    }
    return $out;
}

function rd_extrair($payload) {
    $d = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $c = is_array($d['customer'] ?? null) ? $d['customer'] : [];

    // Contato: tenta o formato padrao e depois o formato do abandono.
    $nome  = $c['name']  ?? $d['customerName']      ?? '';
    $email = $c['email'] ?? $d['customerEmail']     ?? '';
    $tel   = $c['phone'] ?? $d['customerCellphone'] ?? '';
    $cpf   = $c['docNumber'] ?? $c['document'] ?? '';

    // Valor: campo direto do pedido ou preco da oferta (o abandono so tem o da oferta).
    $valor = 0.0;
    foreach (['amount','total','value','paid_amount'] as $k) {
        if (isset($d[$k]) && is_numeric($d[$k])) { $valor = (float)$d[$k]; break; }
    }
    if ($valor <= 0 && isset($d['offer']['price']) && is_numeric($d['offer']['price'])) {
        $valor = (float)$d['offer']['price'];
    }

    // UTMs: no abandono nao vem soltos, so dentro do checkoutUrl.
    $utm = [];
    foreach (['utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_id','sck','src','fbc','fbp'] as $k) {
        if (!empty($d[$k]) && is_string($d[$k])) $utm[$k] = $d[$k];
    }
    if (empty($utm['utm_source'])) {
        $utm = array_merge(rd_utm_da_url($d['checkoutUrl'] ?? ''), $utm);
    }

    return [
        'evento'       => (string)($payload['event'] ?? ''),
        'nome'         => trim((string)$nome),
        'email'        => strtolower(trim((string)$email)),
        'telefone'     => rd_so_digitos($tel),
        'cpf'          => rd_so_digitos($cpf),
        'valor'        => $valor,
        'oferta'       => (string)($d['offer']['id'] ?? ''),
        'oferta_nome'  => (string)($d['offer']['name'] ?? ''),
        'produto'      => (string)($d['product']['name'] ?? ''),
        'produto_id'   => (string)($d['product']['id'] ?? ''),
        'order_id'     => (string)($d['id'] ?? $d['order_id'] ?? $d['refId'] ?? ''),
        'parent_order' => (string)($d['parent_order'] ?? ''),
        'metodo'       => (string)($d['paymentMethod'] ?? ''),
        'utm'          => $utm,
        'quando'       => date('Y-m-d H:i:s'),
    ];
}

// ---------------------------------------------------------------------------
// Tipo de lead a partir do nome do evento da Cakto
// ---------------------------------------------------------------------------
function rd_tipo_do_evento($evento) {
    $e = strtolower((string)$evento);
    if (in_array($e, ['checkout_abandonment','abandono_de_checkout','checkout_abandonado'])) return 'abandono';
    if ($e === 'pix_gerado')    return 'pix';
    if ($e === 'boleto_gerado') return 'boleto';
    if ($e === 'picpay_gerado') return 'picpay';
    if ($e === 'nubank_gerado') return 'nubank';
    // A Cakto pode nomear a recusa de cartao de varias formas — aceita todas.
    if (in_array($e, ['purchase_refused','compra_recusada','payment_refused','purchase_declined','payment_failed','refused','recusada'])) return 'cartao_recusado';
    return '';
}

function rd_rotulo_tipo($tipo) {
    $map = [
        'abandono'        => 'Abandono',
        'pix'             => 'Pix',
        'boleto'          => 'Boleto',
        'picpay'          => 'PicPay',
        'nubank'          => 'Nubank',
        'cartao_recusado' => 'Cartao recusado',
    ];
    return $map[$tipo] ?? $tipo;
}

// Chave de deduplicacao: telefone (mais confiavel) e, na falta dele, e-mail.
function rd_chave($lead) {
    $tel = rd_so_digitos($lead['telefone'] ?? '');
    if ($tel !== '') return 't:' . $tel;
    $em = strtolower(trim($lead['email'] ?? ''));
    return $em !== '' ? 'e:' . $em : '';
}

// ---------------------------------------------------------------------------
// Registro de lead. Um registro por (tipo + contato), sempre com a ocorrencia
// mais recente — somando as tentativas.
// ---------------------------------------------------------------------------
function rd_lead_registrar($info, $tipo, $leads = null) {
    if ($tipo === '') return $leads;
    $chave = rd_chave($info);
    if ($chave === '') return $leads; // sem contato nenhum nao serve pra recuperacao

    $externo = is_array($leads);
    if (!$externo) $leads = rd_json_ler(RD_LEADS_FILE);

    $id    = $tipo . '|' . $chave;
    $antes = null;
    foreach ($leads as $i => $L) {
        if (($L['id'] ?? '') === $id) { $antes = $i; break; }
    }

    $novo = [
        'id'         => $id,
        'tipo'       => $tipo,
        'nome'       => $info['nome']     ?: ($antes !== null ? ($leads[$antes]['nome'] ?? '')     : ''),
        'email'      => $info['email']    ?: ($antes !== null ? ($leads[$antes]['email'] ?? '')    : ''),
        'telefone'   => $info['telefone'] ?: ($antes !== null ? ($leads[$antes]['telefone'] ?? '') : ''),
        'valor'      => $info['valor'],
        'oferta'     => $info['oferta'],
        'produto'    => $info['produto'],
        'order_id'   => $info['order_id'],
        'metodo'     => $info['metodo'],
        'utm'        => $info['utm'],
        'quando'     => $info['quando'],
        'tentativas' => $antes !== null ? intval($leads[$antes]['tentativas'] ?? 1) + 1 : 1,
        // Uma nova tentativa depois de ja ter pago volta a contar como pendente:
        // e exatamente o caso de quem compra o principal e gera Pix de outro produto.
        'pago'       => false,
        'pago_em'    => null,
    ];

    if ($antes !== null) $leads[$antes] = $novo; else $leads[] = $novo;
    if (count($leads) > RD_MAX_LEADS) $leads = array_slice($leads, -RD_MAX_LEADS);

    if ($externo) return $leads;
    rd_json_gravar(RD_LEADS_FILE, $leads);
    return $leads;
}

// Marca como pago todo lead do mesmo contato criado ATE o momento da compra.
// Leads criados depois (ex.: Pix de um segundo produto) continuam pendentes.
// Versao que opera sobre um array em memoria (usada tambem na reconstrucao).
function rd_marcar_pago_array($leads, $email, $telefone, $quando) {
    $email = strtolower(trim((string)$email));
    $tel   = rd_so_digitos($telefone);
    if ($email === '' && $tel === '') return $leads;

    foreach ($leads as $i => $L) {
        if (!empty($L['pago'])) continue;
        $bateEmail = $email !== '' && strtolower($L['email'] ?? '') === $email;
        $bateTel   = $tel   !== '' && rd_so_digitos($L['telefone'] ?? '') === $tel;
        if (!$bateEmail && !$bateTel) continue;
        if (strcmp((string)($L['quando'] ?? ''), (string)$quando) > 0) continue; // criado depois da compra
        $leads[$i]['pago']    = true;
        $leads[$i]['pago_em'] = $quando;
    }
    return $leads;
}

function rd_leads_marcar_pago($email, $telefone, $quando = null) {
    $quando = $quando ?: date('Y-m-d H:i:s');
    $leads  = rd_json_ler(RD_LEADS_FILE);
    $novo   = rd_marcar_pago_array($leads, $email, $telefone, $quando);
    if ($novo !== $leads) rd_json_gravar(RD_LEADS_FILE, $novo);
}

// ---------------------------------------------------------------------------
// Atribuicao: guarda os UTMs de toda compra que chega COM rastreio, indexada
// por order_id, CPF, e-mail e telefone. E o que permite o upsell one-click
// herdar a campanha da compra que veio antes.
// ---------------------------------------------------------------------------
function rd_atribuicao_salvar($info) {
    if (empty($info['utm']['utm_source'])) return;
    $a = rd_json_ler(RD_ATRIB_FILE);
    if (!isset($a['registros']) || !is_array($a['registros'])) $a = ['registros' => []];

    $a['registros'][] = [
        'order_id' => $info['order_id'],
        'cpf'      => $info['cpf'],
        'email'    => $info['email'],
        'telefone' => $info['telefone'],
        'utm'      => $info['utm'],
        'quando'   => $info['quando'],
    ];
    if (count($a['registros']) > RD_MAX_ATRIB) {
        $a['registros'] = array_slice($a['registros'], -RD_MAX_ATRIB);
    }
    rd_json_gravar(RD_ATRIB_FILE, $a);
}

// Procura a atribuicao do "pedido pai": primeiro pelo parent_order, depois pelo
// CPF, e-mail e telefone. Sempre a ocorrencia mais recente dentro da janela.
function rd_atribuicao_buscar($info, $janela_horas = 24) {
    $a = rd_json_ler(RD_ATRIB_FILE);
    $regs = $a['registros'] ?? [];
    if (!$regs) return null;

    $limite = time() - ($janela_horas * 3600);
    $cpf   = rd_so_digitos($info['cpf'] ?? '');
    $email = strtolower(trim($info['email'] ?? ''));
    $tel   = rd_so_digitos($info['telefone'] ?? '');
    $pai   = (string)($info['parent_order'] ?? '');

    if ($pai !== '') {
        for ($i = count($regs) - 1; $i >= 0; $i--) {
            if ((string)($regs[$i]['order_id'] ?? '') === $pai) return $regs[$i];
        }
    }
    for ($i = count($regs) - 1; $i >= 0; $i--) {
        $r = $regs[$i];
        if (strtotime($r['quando'] ?? '') < $limite) continue;
        if ($cpf   !== '' && rd_so_digitos($r['cpf'] ?? '') === $cpf)      return $r;
        if ($email !== '' && strtolower($r['email'] ?? '') === $email)     return $r;
        if ($tel   !== '' && rd_so_digitos($r['telefone'] ?? '') === $tel) return $r;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Apoio ao painel
// ---------------------------------------------------------------------------
// "2026-08-20 10:19:33" -> "10:19 20/08/2026"
function rd_fmt_data($iso) {
    $ts = strtotime((string)$iso);
    return $ts ? date('H:i d/m/Y', $ts) : '-';
}

// Nome da campanha sem o "|id" no final.
function rd_campanha($utm) {
    $c = is_array($utm) ? ($utm['utm_campaign'] ?? '') : '';
    if ($c === '') return '';
    return trim(explode('|', $c)[0]);
}

function rd_anuncio($utm) {
    $c = is_array($utm) ? ($utm['utm_content'] ?? '') : '';
    if ($c === '') return '';
    return trim(explode('|', $c)[0]);
}

// Telefone brasileiro pronto pro wa.me (com DDI).
function rd_wa_numero($tel) {
    $t = rd_so_digitos($tel);
    if ($t === '') return '';
    if (strlen($t) <= 11) $t = '55' . $t;
    return $t;
}

function rd_wa_link($tel, $mensagem) {
    $n = rd_wa_numero($tel);
    if ($n === '') return '';
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($mensagem);
}

// Primeiro nome, capitalizado — pra usar na mensagem do WhatsApp.
function rd_primeiro_nome($nome) {
    $n = trim((string)$nome);
    if ($n === '') return '';
    $p = preg_split('/\s+/', $n)[0];
    return mb_convert_case(mb_strtolower($p), MB_CASE_TITLE, 'UTF-8');
}

// Marcar/desmarcar "ja falei com essa pessoa".
function rd_contatados() { return rd_json_ler(RD_CONTATADOS_FILE); }

function rd_contatado_alternar($id) {
    $c = rd_contatados();
    if (isset($c[$id])) unset($c[$id]); else $c[$id] = date('Y-m-d H:i:s');
    rd_json_gravar(RD_CONTATADOS_FILE, $c);
}
