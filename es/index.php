<?php
// ============================================================
//  CLOAKER — As 12 Palavras Sagradas
//  Detecta bots/revisores e serve página limpa.
// ============================================================

// --- CONFIGURAÇÕES ---
define('SALES_PAGE',  'vendas.html');   // página de vendas (black page)
define('CLEAN_PAGE',  'clean.html');    // página limpa (white page)
define('ONLY_BRAZIL', false);          // true = só BR vê a página de vendas
define('LOG_ENABLED', false);           // true = salva log (debug)
define('SECRET_BYPASS', 'gl2026');     // ?bypass=gl2026 na URL mostra a página real sempre


// --- 1. REGRA SUPREMA: BYPASS MANUAL (Sempre em primeiro lugar) ---
if (isset($_GET['bypass']) && $_GET['bypass'] === SECRET_BYPASS) {
    setcookie('_gl_ok', '1', time() + 86400 * 7, '/');
    serve_sales();
    exit;
}

// --- 2. REGRA SUPREMA: COOKIE ATIVO (Usuário já validado anteriormente) ---
if (!empty($_COOKIE['_gl_ok'])) {
    serve_sales();
    exit;
}


// --- 3. FILTRO POR FBCLID (Só continua se vier de anúncio com fbclid) ---
if (!isset($_GET['fbclid'])) {
    serve_clean();
    exit;
}

$ua  = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip  = get_real_ip();
$ref = strtolower($_SERVER['HTTP_REFERER'] ?? '');
$lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';

// ============================================================
//  CAMADA 1 — User-Agent de bots conhecidos
// ============================================================
$bot_agents = [
    'facebookexternalhit','facebot','facebook','linkedinbot','twitterbot',
    'googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot',
    'applebot','semrushbot','ahrefsbot','mj12bot','dotbot','petalbot',
    'screaming frog','rogerbot','exabot','ia_archiver','archive.org_bot',
    'wget','curl','python-requests','go-http-client','java/','libwww',
    'scrapy','httpclient','guzzle','okhttp','apache-httpclient',
    'adsbot-google','mediapartners-google','apis-google','feedfetcher',
    'adbeat','brand-checker','check_http','pingdom','uptimerobot',
    'headlesschrome','phantomjs','selenium','webdriver','puppeteer',
    'nightmarejs','slimerjs','zgrab','masscan','nmap',
];
foreach ($bot_agents as $b) {
    if (strpos($ua, $b) !== false) {
        log_visit($ip, $ua, 'BOT_UA');
        serve_clean();
        exit;
    }
}

// UA vazio = bot
if (empty($ua) || strlen($ua) < 20) {
    log_visit($ip, $ua, 'EMPTY_UA');
    serve_clean();
    exit;
}

// ============================================================
//  CAMADA 2 — IPs de datacenters e redes de monitoramento
// ============================================================
$dc_ranges = [
    // Facebook
    '31.13.','66.220.','69.63.','69.171.','74.119.','102.132.',
    '103.4.96.','129.134.','157.240.','163.70.','179.60.',
    '185.60.','204.15.',
    // Google
    '34.64.','34.65.','34.80.','34.96.','34.100.','34.102.',
    '34.104.','34.116.','34.140.','34.142.','34.143.','34.144.',
    '35.184.','35.185.','35.186.','35.187.','35.188.','35.189.',
    '35.190.','35.191.','35.192.','35.193.','35.194.','35.195.',
    '35.196.','35.197.','35.198.','35.199.','35.200.','35.201.',
    '35.202.','35.203.','35.204.','35.205.','35.206.','35.207.',
    '64.18.','66.249.','72.14.','74.125.','108.177.','142.250.',
    '172.217.','173.194.','209.85.','216.58.','216.239.',
    // AWS
    '3.','13.','18.','34.','35.','44.','52.','54.',
    // Microsoft/Azure
    '13.64.','13.65.','13.66.','13.67.','13.68.','13.69.',
    '20.','40.','51.','52.','65.52.',
    // Cloudflare
    '1.1.1.','1.0.0.','104.16.','104.17.','104.18.','104.19.',
    '104.20.','104.21.','104.22.','104.23.','104.24.','104.25.',
    '104.26.','104.27.','104.28.','172.64.','172.65.','172.66.',
    '172.67.','172.68.','172.69.','172.70.','188.114.',
    // DigitalOcean
    '104.131.','104.236.','107.170.','128.199.','138.197.',
    '139.59.','142.93.','143.110.','159.65.','159.203.',
    '162.243.','165.227.','167.99.','174.138.','178.62.',
    '192.241.','198.199.','206.189.',
    // Outros datacenters comuns
    '45.33.','45.56.','45.79.','96.126.','172.104.',
    '192.168.','10.','127.','0.0.0.',
];
foreach ($dc_ranges as $range) {
    if (strpos($ip, $range) === 0) {
        log_visit($ip, $ua, 'DC_IP:'.$range);
        serve_clean();
        exit;
    }
}

// ============================================================
//  CAMADA 3 — Geolocalização (só BR vê vendas, se ativado)
// ============================================================
if (ONLY_BRAZIL) {
    $country = get_country($ip);
    if ($country && $country !== 'BR') {
        log_visit($ip, $ua, 'NOT_BR:'.$country);
        serve_clean();
        exit;
    }
}

// ============================================================
//  CAMADA 4 — Sinais de dispositivo suspeito no Accept-Language
// ============================================================
// Idiomas que nunca vêm de usuário BR orgânico nos anúncios
$suspicious_langs = ['zh-cn','zh-tw','ko-kr','ja-jp','ar-sa','ru-ru'];
$lang_lower = strtolower($lang);
foreach ($suspicious_langs as $sl) {
    if (strpos($lang_lower, $sl) === 0) {
        log_visit($ip, $ua, 'LANG:'.$sl);
        serve_clean();
        exit;
    }
}

// ============================================================
//  CAMADA 5 — Referer suspeito (direto de review tools)
// ============================================================
$suspicious_refs = [
    'facebook.com/ads','business.facebook.com','ads.google.com',
    'adspector','adbeat','moat.com','ad-score','whotracked',
    'builtwith','similarweb','semrush','ahrefs','moz.com',
];
foreach ($suspicious_refs as $sr) {
    if (strpos($ref, $sr) !== false) {
        log_visit($ip, $ua, 'REF:'.$sr);
        serve_clean();
        exit;
    }
}

// ============================================================
//  PASSOU EM TUDO — É HUMANO, SERVE A PÁGINA DE VENDAS
// ============================================================
setcookie('_gl_ok', '1', time() + 86400 * 3, '/');
log_visit($ip, $ua, 'HUMAN_OK');
serve_sales();
exit;

// ============================================================
//  FUNÇÕES AUXILIARES
// ============================================================

function serve_sales() {
    // Cabeçalhos anti-cache para o crawler não guardar
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $file = __DIR__ . '/' . SALES_PAGE;
    if (file_exists($file)) {
        readfile($file);
    } else {
        echo '';
    }
}

function serve_clean() {
    header('Cache-Control: public, max-age=86400');
    $file = __DIR__ . '/' . CLEAN_PAGE;
    if (file_exists($file)) {
        readfile($file);
    } else {
        // fallback inline se clean.html sumiu
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
        <title>Fé e Prosperidade | Gabriel Luz</title>
        <meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="font-family:sans-serif;max-width:680px;margin:40px auto;padding:0 20px;color:#333">
        <h1 style="font-size:24px">A Bíblia e o poder das palavras sagradas</h1>
        <p style="margin-top:16px;line-height:1.7">Por séculos, estudiosos das Escrituras têm investigado o poder transformador das orações bíblicas...</p>
        </body></html>';
    }
}

function get_real_ip() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function get_country($ip) {
    // Usa API gratuita — sem custo
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $r   = @file_get_contents("https://ip-api.com/json/{$ip}?fields=countryCode", false, $ctx);
    if ($r) {
        $d = json_decode($r, true);
        return $d['countryCode'] ?? null;
    }
    return null; // se API falhar, deixa passar
}

function log_visit($ip, $ua, $reason) {
    if (!LOG_ENABLED) return;
    $line = date('Y-m-d H:i:s') . " | {$reason} | {$ip} | {$ua}\n";
    file_put_contents(__DIR__ . '/cloaker_log.txt', $line, FILE_APPEND | LOCK_EX);
}