<?php
// ============================================================
//  Seletor de versao da pagina — funil INTERNACIONAL (Perfect Pay)
//
//  Independente do index.php da raiz: arquivos proprios, cookie proprio.
//  Nada aqui afeta o funil brasileiro que roda em noticiasdafe.com.br/
//
//  Sem consulta de geolocalizacao: o funil e internacional (nao faz sentido
//  filtrar pais) e a chamada externa custava ~600ms de espera por visitante.
// ============================================================

define('SALES_PAGE',    'p.html');      // versao completa
define('CLEAN_PAGE',    'clean.html');  // versao institucional
define('SECRET_BYPASS', 'gl2026');      // ?bypass=gl2026 mostra a completa sempre
define('COOKIE_NAME',   '_pp_ok');      // cookie proprio — nao se mistura com o do Brasil

// --- 1. Bypass manual (sempre primeiro) ---
if (isset($_GET['bypass']) && $_GET['bypass'] === SECRET_BYPASS) {
    setcookie(COOKIE_NAME, '1', time() + 86400 * 7, '/cppt/');
    entregar(SALES_PAGE);
}

// --- 2. Visitante ja validado antes ---
if (!empty($_COOKIE[COOKIE_NAME])) {
    entregar(SALES_PAGE);
}

// --- 3. Precisa vir de anuncio real (fbclid da Meta ou ttclid real do TikTok) ---
$is_meta_click   = !empty($_GET['fbclid']) && strlen($_GET['fbclid']) > 15;
$is_tiktok_click = !empty($_GET['ttclid']) && $_GET['ttclid'] !== '__CLICKID__' && strlen($_GET['ttclid']) > 10;

if (!$is_meta_click && !$is_tiktok_click) {
    entregar(CLEAN_PAGE);
}

$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip = ip_real();

// --- 4. User-agent de robo ---
$bots = [
    'facebookexternalhit','facebot','facebook','linkedinbot','twitterbot',
    'bytespider','tiktokbot',
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
foreach ($bots as $b) {
    if (strpos($ua, $b) !== false) entregar(CLEAN_PAGE);
}
if (empty($ua) || strlen($ua) < 20) entregar(CLEAN_PAGE);

// --- 5. Faixas de IP de datacenter (nao sao pessoas navegando) ---
$datacenters = [
    '31.13.','66.220.','69.63.','69.171.','74.119.','102.132.',
    '103.4.96.','129.134.','157.240.','163.70.','179.60.','185.60.','204.15.',
    '34.64.','34.65.','34.80.','34.96.','34.100.','34.102.','34.104.','34.116.',
    '34.140.','34.142.','34.143.','34.144.','35.184.','35.185.','35.186.',
    '35.187.','35.188.','35.189.','35.190.','35.191.','35.192.','35.193.',
    '35.194.','35.195.','35.196.','35.197.','35.198.','35.199.','35.200.',
    '35.201.','35.202.','35.203.','35.204.','35.205.','35.206.','35.207.',
    '64.18.','66.249.','72.14.','74.125.','108.177.','142.250.','172.217.',
    '173.194.','209.85.','216.58.','216.239.',
    '3.','13.','18.','34.','35.','44.','52.','54.',
    '20.','40.','51.','65.52.',
    '1.1.1.','1.0.0.','104.16.','104.17.','104.18.','104.19.','104.20.',
    '104.21.','104.22.','104.23.','104.24.','104.25.','104.26.','104.27.',
    '104.28.','172.64.','172.65.','172.66.','172.67.','172.68.','172.69.',
    '172.70.','188.114.',
    '104.131.','104.236.','107.170.','128.199.','138.197.','139.59.',
    '142.93.','143.110.','159.65.','159.203.','162.243.','165.227.',
    '167.99.','174.138.','178.62.','192.241.','198.199.','206.189.',
    '45.33.','45.56.','45.79.','96.126.','172.104.',
    '192.168.','10.','127.','0.0.0.',
];
foreach ($datacenters as $faixa) {
    if (strpos($ip, $faixa) === 0) entregar(CLEAN_PAGE);
}

// --- Passou em tudo: visitante real vindo de anuncio ---
setcookie(COOKIE_NAME, '1', time() + 86400 * 3, '/cppt/');
entregar(SALES_PAGE);


// ------------------------------------------------------------
function entregar($arquivo) {
    $caminho = __DIR__ . '/' . $arquivo;
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    if (is_file($caminho)) {
        readfile($caminho);
    } else {
        http_response_code(404);
        echo 'Pagina nao encontrada.';
    }
    exit;
}

function ip_real() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP'] as $k) {
        if (!empty($_SERVER[$k])) {
            $partes = explode(',', $_SERVER[$k]);
            $cand = trim($partes[0]);
            if (filter_var($cand, FILTER_VALIDATE_IP)) return $cand;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
