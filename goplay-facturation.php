<?php
/**
 * Plugin Name: Goplay Arena Facturation
 * Description: Devis, factures, acomptes et paiements SumUp pour GB GLOBAL (Goplay Arena). L'outil est accessible sur /facturation, réservé aux administrateurs du site.
 * Version: 1.6.0
 * Update URI: https://github.com/fabricelau/goplay-facturation
 * Requires PHP: 7.4
 * Author: Goplay Arena
 * Text Domain: goplay-facturation
 */

if (!defined('ABSPATH')) exit;

define('GPF_VERSION', '1.6.0');
define('GPF_UPDATE_JSON', 'https://github.com/fabricelau/goplay-facturation/releases/latest/download/update.json');
define('GPF_DIR', plugin_dir_path(__FILE__));
define('GPF_SLUG', 'facturation');
define('GPF_TZ', 'Indian/Reunion');

/* ------------------------------------------------------------------ */
/*  Installation                                                       */
/* ------------------------------------------------------------------ */

function gpf_table() { global $wpdb; return $wpdb->prefix . 'gpf_records'; }

function gpf_vendor_libs() {
    return [
        'jspdf.umd.min.js' => 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
        'jszip.min.js' => 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
    ];
}
function gpf_vendor_path($name) { $u = wp_upload_dir(); return trailingslashit($u['basedir']) . 'gpf-vendor/' . $name; }
function gpf_vendor_url($name) { $u = wp_upload_dir(); return trailingslashit($u['baseurl']) . 'gpf-vendor/' . $name; }
function gpf_fetch_vendor() {
    foreach (gpf_vendor_libs() as $name => $url) {
        $path = gpf_vendor_path($name);
        if (file_exists($path) && filesize($path) > 10000) continue;
        $res = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = wp_remote_retrieve_body($res);
        if (strlen($body) < 10000) continue;
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $body);
    }
}

function gpf_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = gpf_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        coll VARCHAR(32) NOT NULL,
        id VARCHAR(64) NOT NULL,
        data LONGTEXT NOT NULL,
        updated DATETIME NOT NULL,
        PRIMARY KEY  (coll,id)
    ) $charset;");
    gpf_rewrite();
    flush_rewrite_rules();
    gpf_fetch_vendor();
    if (!wp_next_scheduled('gpf_daily')) {
        $dt = new DateTime('tomorrow 07:45', new DateTimeZone(GPF_TZ));
        wp_schedule_event($dt->getTimestamp(), 'daily', 'gpf_daily');
    }
    update_option('gpf_version', GPF_VERSION);
    if (!get_option('gpf_last_backup')) gpf_backup_run();
}
register_activation_hook(__FILE__, 'gpf_install');
// Plugin replaced by upload: activation does not run again, so redo the install steps once per new version
add_action('init', function () { if (get_option('gpf_version') !== GPF_VERSION) gpf_install(); }, 20);

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    wp_clear_scheduled_hook('gpf_daily');
});

/* ------------------------------------------------------------------ */
/*  Storage helpers                                                    */
/* ------------------------------------------------------------------ */

function gpf_valid_key($s) { return is_string($s) && preg_match('/^[A-Za-z0-9_\-.~:@+]{1,64}$/', $s); }
function gpf_valid_coll($c) { return in_array($c, ['clients', 'articles', 'formules', 'docs', 'config'], true); }

function gpf_get($coll, $id) {
    global $wpdb;
    $row = $wpdb->get_var($wpdb->prepare('SELECT data FROM ' . gpf_table() . ' WHERE coll=%s AND id=%s', $coll, $id));
    return $row ? json_decode($row, true) : null;
}
function gpf_put($coll, $id, $data) {
    global $wpdb;
    $wpdb->replace(gpf_table(), [
        'coll' => $coll, 'id' => $id,
        'data' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        'updated' => current_time('mysql', true),
    ], ['%s', '%s', '%s', '%s']);
}
function gpf_all($coll = null) {
    global $wpdb;
    $sql = 'SELECT coll,id,data FROM ' . gpf_table();
    $rows = $coll ? $wpdb->get_results($wpdb->prepare($sql . ' WHERE coll=%s', $coll), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    return $rows ?: [];
}
function gpf_opt($k, $default = '') { $o = get_option('gpf_options', []); $v = is_array($o) ? ($o[$k] ?? '') : ''; return ($v === '' || $v === null) ? $default : $v; }
function gpf_settings() { return gpf_get('config', 'entreprise') ?: []; }

/* ------------------------------------------------------------------ */
/*  Money helpers (same rules as the app)                              */
/* ------------------------------------------------------------------ */

function gpf_r2($n) { return round((float)$n + 0.0, 2); }
function gpf_totals($d, $settings) {
    $snap = (!empty($d['numero']) && !empty($d['entreprise']) && is_array($d['entreprise'])) ? $d['entreprise'] : $settings;
    $fr = ($snap['regimeTva'] ?? '') === 'franchise';
    $ttcMode = ($d['saisie'] ?? '') === 'ttc';
    $ht = 0; $byHt = []; $byTtc = []; $norm = []; $fixed = [];
    foreach (($d['lignes'] ?? []) as $l) {
        $t = $fr ? 0 : (float)($l['tva'] ?? 0);
        $q = (float)($l['qte'] ?? 0);
        $key = (string)$t;
        if ($ttcMode) {
            $lht = gpf_r2($q * (float)($l['puTTC'] ?? 0) / (1 + $t / 100));
            $byTtc[$key] = ($byTtc[$key] ?? 0) + gpf_r2($q * (float)($l['puTTC'] ?? 0));
        } else {
            $lht = gpf_r2($q * (float)($l['pu'] ?? 0));
        }
        $ht += $lht; $byHt[$key] = ($byHt[$key] ?? 0) + $lht;
        if (!$ttcMode && !empty($l['acompteRef']) && isset($l['puTTC'])) $fixed[$key] = ($fixed[$key] ?? 0) + gpf_r2(gpf_r2($q * (float)$l['puTTC']) - $lht);
        else $norm[$key] = ($norm[$key] ?? 0) + $lht;
    }
    $tva = 0;
    foreach ($byHt as $k => $b) {
        $tva += $ttcMode ? gpf_r2(($byTtc[$k] ?? 0) - $b) : gpf_r2(gpf_r2(($norm[$k] ?? 0) * (float)$k / 100) + ($fixed[$k] ?? 0));
    }
    return ['ht' => gpf_r2($ht), 'tva' => gpf_r2($tva), 'ttc' => gpf_r2($ht + $tva)];
}
function gpf_eur($n) { return number_format((float)$n, 2, ',', ' ') . ' €'; }
function gpf_today() { return (new DateTime('now', new DateTimeZone(GPF_TZ)))->format('Y-m-d'); }
function gpf_fmt_date($iso) { if (!$iso) return ''; $p = explode('-', substr($iso, 0, 10)); return count($p) === 3 ? "$p[2]/$p[1]/$p[0]" : $iso; }

/* ------------------------------------------------------------------ */
/*  Routes: /facturation and /facturation/payer/{token}                */
/* ------------------------------------------------------------------ */

function gpf_rewrite() {
    add_rewrite_rule('^' . GPF_SLUG . '/payer/([A-Za-z0-9]{16,64})/?$', 'index.php?gpf_pay=$matches[1]', 'top');
    add_rewrite_rule('^' . GPF_SLUG . '/devis/([A-Za-z0-9]{16,64})/?$', 'index.php?gpf_quote=$matches[1]', 'top');
    add_rewrite_rule('^' . GPF_SLUG . '/manifest\.webmanifest$', 'index.php?gpf_app=manifest', 'top');
    add_rewrite_rule('^' . GPF_SLUG . '/sw\.js$', 'index.php?gpf_app=sw', 'top');
    add_rewrite_rule('^' . GPF_SLUG . '/?$', 'index.php?gpf_app=1', 'top');
}
add_action('init', 'gpf_rewrite');
add_filter('query_vars', function ($v) { $v[] = 'gpf_app'; $v[] = 'gpf_pay'; $v[] = 'gpf_quote'; return $v; });

add_action('template_redirect', function () {
    if (get_query_var('gpf_pay')) { gpf_render_pay(get_query_var('gpf_pay')); exit; }
    if (get_query_var('gpf_quote')) { gpf_render_quote(get_query_var('gpf_quote')); exit; }
    if (!get_query_var('gpf_app')) return;
    if (get_query_var('gpf_app') === 'manifest') { gpf_manifest(); exit; }
    if (get_query_var('gpf_app') === 'sw') { gpf_service_worker(); exit; }
    if (!is_user_logged_in()) { wp_safe_redirect(wp_login_url(home_url('/' . GPF_SLUG . '/'))); exit; }
    if (!current_user_can('manage_options')) { wp_die('Accès réservé aux administrateurs du site.', 'Accès refusé', ['response' => 403]); }
    gpf_render_app();
    exit;
});

function gpf_nocache() {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    do_action('litespeed_control_set_nocache', 'goplay facturation');
    nocache_headers();
}
function gpf_manifest() {
    gpf_nocache();
    header('Content-Type: application/manifest+json; charset=utf-8');
    $icon = function ($f) { return plugins_url('app/icons/' . $f, __FILE__); };
    echo wp_json_encode([
        'name' => 'Goplay Arena Facturation', 'short_name' => 'Facturation', 'lang' => 'fr',
        'start_url' => home_url('/' . GPF_SLUG . '/'), 'scope' => home_url('/' . GPF_SLUG . '/'), 'id' => home_url('/' . GPF_SLUG . '/'),
        'display' => 'standalone', 'background_color' => '#0E2F2B', 'theme_color' => '#0E2F2B',
        'icons' => [
            ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => $icon('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function gpf_service_worker() {
    gpf_nocache();
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /' . GPF_SLUG . '/');
    // No caching of the app or the data: always fresh, with a friendly page when offline
    echo "self.addEventListener('install',e=>self.skipWaiting());\nself.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));\n"
       . "self.addEventListener('fetch',e=>{if(e.request.mode!=='navigate')return;e.respondWith(fetch(e.request).catch(()=>new Response('<!doctype html><meta charset=utf-8><meta name=viewport content=\"width=device-width,initial-scale=1\"><body style=\"font:16px system-ui;background:#0E2F2B;color:#fff;display:grid;place-items:center;height:100vh;margin:0;text-align:center;padding:20px\"><div><h1 style=\"color:#F2711C\">Pas de connexion</h1><p>L\\u2019outil de facturation a besoin d\\u2019internet. R\\u00e9essaie d\\u00e8s que tu as du r\\u00e9seau.</p></div>',{headers:{'Content-Type':'text/html; charset=utf-8'}})));});\n";
}
function gpf_render_app() {
    gpf_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    $opt = get_option('gpf_options', []);
    $cfg = [
        'rest' => esc_url_raw(rest_url('gpf/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'payBase' => home_url('/' . GPF_SLUG . '/payer/'),
        'quoteBase' => home_url('/' . GPF_SLUG . '/devis/'),
        'sumup' => !empty($opt['sumup_key']) && !empty($opt['sumup_merchant']),
        'mailFrom' => gpf_opt('from_email', get_option('admin_email')),
        'logout' => wp_logout_url(home_url('/')),
        'admin' => admin_url('options-general.php?page=gpf'),
        'base' => home_url('/' . GPF_SLUG . '/'),
        'assets' => plugins_url('app/', __FILE__),
        'version' => GPF_VERSION,
    ];
    $app = file_get_contents(GPF_DIR . 'app/index.html');
    // Use local copies of the PDF/ZIP libraries when available (downloaded once on activation)
    foreach (gpf_vendor_libs() as $name => $cdn) {
        $local = gpf_vendor_path($name);
        if (file_exists($local) && filesize($local) > 10000) $app = str_replace($cdn, gpf_vendor_url($name), $app);
    }
    $shim = file_get_contents(GPF_DIR . 'app/shim.js');
    echo "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\"><meta name=\"robots\" content=\"noindex,nofollow\">";
    $base = home_url('/' . GPF_SLUG . '/');
    echo '<link rel="manifest" href="' . esc_url($base . 'manifest.webmanifest') . '"><meta name="theme-color" content="#0E2F2B"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Facturation"><link rel="apple-touch-icon" href="' . esc_url(plugins_url('app/icons/apple-touch-icon.png', __FILE__)) . '"><link rel="icon" type="image/png" href="' . esc_url(plugins_url('app/icons/icon-192.png', __FILE__)) . '">';
    echo "<style>:root{color-scheme:light;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}body{margin:0;font:14px system-ui,sans-serif}img{max-width:100%}[hidden]{display:none!important}</style>";
    echo '<script>window.GPF=' . wp_json_encode($cfg) . ";\n" . $shim . '</script>';
    echo '</head><body>' . $app . '</body></html>';
}

/* ------------------------------------------------------------------ */
/*  REST API (admin only, cookie + nonce)                              */
/* ------------------------------------------------------------------ */

function gpf_admin() { return current_user_can('manage_options'); }

add_action('rest_api_init', function () {
    register_rest_route('gpf/v1', '/all', ['methods' => 'GET', 'permission_callback' => 'gpf_admin', 'callback' => function () {
        $out = [];
        foreach (gpf_all() as $r) $out[] = ['coll' => $r['coll'], 'id' => $r['id'], 'data' => json_decode($r['data'], true)];
        return rest_ensure_response(['records' => $out]);
    }]);
    register_rest_route('gpf/v1', '/rec/(?P<coll>[a-z]+)/(?P<id>[A-Za-z0-9_-]+)', [
        ['methods' => 'POST, PUT', 'permission_callback' => 'gpf_admin', 'callback' => function ($req) {
            $c = $req['coll']; $id = $req['id'];
            if (!gpf_valid_coll($c) || !gpf_valid_key($id)) return new WP_Error('bad', 'Chemin invalide', ['status' => 400]);
            $data = $req->get_json_params();
            if (!is_array($data)) return new WP_Error('bad', 'Données invalides', ['status' => 400]);
            unset($data['id']);
            if ($c === 'docs') { $err = gpf_check_doc_write($id, $data); if ($err) return $err; }
            gpf_put($c, $id, $data);
            return rest_ensure_response(['ok' => true]);
        }],
    ]);
    register_rest_route('gpf/v1', '/del/(?P<coll>[a-z]+)/(?P<id>[A-Za-z0-9_-]+)', [
        ['methods' => 'POST, DELETE', 'permission_callback' => 'gpf_admin', 'callback' => function ($req) {
            global $wpdb;
            $c = $req['coll']; $id = $req['id'];
            if (!gpf_valid_coll($c) || !gpf_valid_key($id)) return new WP_Error('bad', 'Chemin invalide', ['status' => 400]);
            if ($c === 'docs') {
                $d = gpf_get('docs', $id);
                if ($d && !empty($d['numero']) && empty($d['test'])) return new WP_Error('locked', 'Un document émis ne peut pas être supprimé.', ['status' => 409]);
            }
            $wpdb->delete(gpf_table(), ['coll' => $c, 'id' => $id], ['%s', '%s']);
            return rest_ensure_response(['ok' => true]);
        }],
    ]);
    register_rest_route('gpf/v1', '/reset', ['methods' => 'POST', 'permission_callback' => 'gpf_admin', 'callback' => function ($req) {
        global $wpdb;
        $b = $req->get_json_params();
        if (!is_array($b) || ($b['confirm'] ?? '') !== 'SUPPRIMER') return new WP_Error('bad', 'Confirmation manquante', ['status' => 400]);
        $n = $wpdb->delete(gpf_table(), ['coll' => 'docs'], ['%s']);
        return rest_ensure_response(['ok' => true, 'deleted' => (int)$n]);
    }]);
    register_rest_route('gpf/v1', '/entreprises', ['methods' => 'GET', 'permission_callback' => 'gpf_admin', 'callback' => 'gpf_rest_companies']);
    register_rest_route('gpf/v1', '/mail', ['methods' => 'POST', 'permission_callback' => 'gpf_admin', 'callback' => 'gpf_rest_mail']);
    register_rest_route('gpf/v1', '/sumup-webhook', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        $b = $req->get_json_params();
        $cid = is_array($b) ? ($b['id'] ?? '') : '';
        if ($cid && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $cid)) gpf_check_checkout($cid);
        return rest_ensure_response(['ok' => true]);
    }]);
});



/* ------------------------------------------------------------------ */
/* Automatic updates from GitHub releases (no ZIP to upload anymore)  */
/* ------------------------------------------------------------------ */
function gpf_update_info($force = false) {
    $c = get_site_transient('gpf_update_info');
    if (!$force && is_array($c)) return $c;
    $r = wp_remote_get(GPF_UPDATE_JSON, ['timeout' => 10, 'redirection' => 5, 'headers' => ['Accept' => 'application/json']]);
    $info = ['checked' => time(), 'ok' => false];
    if (!is_wp_error($r) && (int)wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (is_array($j) && !empty($j['version']) && preg_match('/^\d+\.\d+\.\d+$/', $j['version'])
            && !empty($j['package']) && strpos($j['package'], 'https://github.com/fabricelau/goplay-facturation/') === 0) {
            $info = ['checked' => time(), 'ok' => true, 'version' => $j['version'], 'package' => $j['package'],
                'changelog' => (string)($j['changelog'] ?? ''), 'requires_php' => (string)($j['requires_php'] ?? '7.4'), 'tested' => (string)($j['tested'] ?? '')];
        }
    }
    set_site_transient('gpf_update_info', $info, $info['ok'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $info;
}
add_filter('pre_set_site_transient_update_plugins', function ($t) {
    if (!is_object($t)) return $t;
    $info = gpf_update_info();
    $base = plugin_basename(__FILE__);
    $item = (object)['id' => 'github.com/fabricelau/goplay-facturation', 'slug' => 'goplay-facturation', 'plugin' => $base, 'new_version' => GPF_VERSION,
        'url' => 'https://github.com/fabricelau/goplay-facturation', 'package' => '', 'icons' => ['1x' => plugins_url('app/icons/icon-192.png', __FILE__)]];
    if (!empty($info['ok']) && version_compare($info['version'], GPF_VERSION, '>')) {
        $item->new_version = $info['version'];
        $item->package = $info['package'];
        $item->requires_php = $info['requires_php'];
        if ($info['tested'] !== '') $item->tested = $info['tested'];
        $t->response[$base] = $item;
        unset($t->no_update[$base]);
    } else {
        $t->no_update[$base] = $item;
        unset($t->response[$base]);
    }
    return $t;
});
add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'goplay-facturation') return $res;
    $info = gpf_update_info();
    return (object)['name' => 'Goplay Arena Facturation', 'slug' => 'goplay-facturation', 'version' => $info['version'] ?? GPF_VERSION,
        'author' => 'Goplay Arena', 'homepage' => 'https://github.com/fabricelau/goplay-facturation', 'requires_php' => '7.4',
        'download_link' => $info['package'] ?? '', 'sections' => ['changelog' => wpautop(esc_html($info['changelog'] ?? ''))]];
}, 10, 3);
/* Updates install by themselves (WordPress checks twice a day) unless switched off in the settings. */
add_filter('auto_update_plugin', function ($update, $item) {
    if (isset($item->plugin) && $item->plugin === plugin_basename(__FILE__)) return gpf_opt('autoupdate', 'on') !== 'off';
    return $update;
}, 10, 2);
add_action('upgrader_process_complete', function () { delete_site_transient('gpf_update_info'); });
add_action('admin_post_gpf_check_update', function () {
    if (!current_user_can('update_plugins') || !check_admin_referer('gpf_check_update')) wp_die('Accès refusé', '', ['response' => 403]);
    gpf_update_info(true);
    delete_site_transient('update_plugins');
    if (function_exists('wp_update_plugins')) wp_update_plugins();
    wp_safe_redirect(admin_url('options-general.php?page=gpf&gpf_msg=update_checked#gpf-maj'));
    exit;
});

/* ------------------------------------------------------------------ */
/* Site hardening (on by default, can be switched off in the settings) */
/* ------------------------------------------------------------------ */
function gpf_secu_on() { $o = get_option('gpf_options', []); return !is_array($o) || ($o['secu'] ?? 'on') !== 'off'; }
function gpf_client_ip() { return preg_replace('/[^0-9a-fA-F:.]/', '', (string)($_SERVER['REMOTE_ADDR'] ?? '')); }
function gpf_login_blocked_count() { $l = get_option('gpf_login_blocks', []); $n = 0; foreach ((array)$l as $t) if ($t > time() - DAY_IN_SECONDS) $n++; return $n; }
if (gpf_secu_on()) {
    /* 1. Brute force: 5 wrong passwords per IP → 15 min lock. */
    add_filter('authenticate', function ($user, $username) {
        if (empty($username)) return $user;
        $n = (int)get_transient('gpf_lf_' . md5(gpf_client_ip()));
        if ($n >= 5) return new WP_Error('gpf_locked', '<strong>Trop de tentatives.</strong> Pour ta sécurité, la connexion est bloquée 15 minutes depuis cet appareil.');
        return $user;
    }, 30, 2);
    add_action('wp_login_failed', function () {
        $k = 'gpf_lf_' . md5(gpf_client_ip());
        $n = (int)get_transient($k) + 1;
        set_transient($k, $n, 15 * MINUTE_IN_SECONDS);
        if ($n === 5) { $l = array_filter((array)get_option('gpf_login_blocks', []), function ($t) { return $t > time() - DAY_IN_SECONDS; }); $l[] = time(); update_option('gpf_login_blocks', array_values(array_slice($l, -200)), false); }
    });
    add_action('wp_login', function () { delete_transient('gpf_lf_' . md5(gpf_client_ip())); });
    /* Same vague message for wrong user or wrong password. */
    add_filter('login_errors', function ($e) { return (strpos((string)$e, 'Trop de tentatives') !== false) ? $e : '<strong>Erreur :</strong> identifiant ou mot de passe incorrect.'; });
    /* 2. Hide login names from visitors. */
    add_filter('rest_endpoints', function ($e) {
        if (is_user_logged_in()) return $e;
        foreach (array_keys($e) as $r) if (strpos($r, '/wp/v2/users') === 0) unset($e[$r]);
        return $e;
    });
    add_action('template_redirect', function () { if (!is_user_logged_in() && (isset($_GET['author']) || is_author())) { wp_safe_redirect(home_url('/'), 301); exit; } }, 1);
    add_filter('oembed_response_data', function ($d) { unset($d['author_name'], $d['author_url']); return $d; });
    /* 3. Hide the WordPress version and XML-RPC. */
    remove_action('wp_head', 'wp_generator');
    add_filter('the_generator', '__return_empty_string');
    add_filter('xmlrpc_enabled', '__return_false');
    /* 4. Standard browser protections. */
    add_action('send_headers', function () {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if (is_ssl()) header('Strict-Transport-Security: max-age=31536000');
    });
}

/* Anti-abuse on public pages: at most 12 form posts per link and per visitor every 10 minutes. */
function gpf_throttle($key) {
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $k = 'gpf_rl_' . md5($key . '|' . $ip);
    $n = (int)get_transient($k);
    if ($n >= 12) return false;
    set_transient($k, $n + 1, 600);
    return true;
}
function gpf_too_many() {
    gpf_pay_page('Trop de tentatives', '<p class="err">Trop de tentatives en peu de temps. Merci de patienter une dizaine de minutes puis de réessayer.</p>');
}

/* Issued documents are locked: only follow-up fields may change.        */
function gpf_check_doc_write($id, $new) {
    $old = gpf_get('docs', $id);
    $allowed = ['statut', 'paiement', 'envois', 'factureId', 'factureNumero', 'avoirNumero', 'payToken', 'lienPaiement', 'sumupCheckouts', 'signToken', 'lienDevis', 'acceptation', 'annulation', 'acompteCheckouts', 'acompteEnLigne', 'updatedAt'];
    if (is_array($old) && !empty($old['numero']) && empty($old['test'])) {
        $a = array_diff_key($old, array_flip($allowed));
        $b = array_diff_key($new, array_flip($allowed));
        if (wp_json_encode(gpf_ksort_deep($a)) !== wp_json_encode(gpf_ksort_deep($b))) {
            return new WP_Error('locked', 'Ce document est émis : il ne peut plus être modifié. Pour le corriger, crée un avoir.', ['status' => 409]);
        }
        return null;
    }
    // New issue: the number must not already be used by another real document of the same type
    if (!empty($new['numero']) && empty($new['test'])) {
        foreach (gpf_all('docs') as $r) {
            if ($r['id'] === $id) continue;
            $d = json_decode($r['data'], true);
            if (is_array($d) && empty($d['test']) && ($d['type'] ?? '') === ($new['type'] ?? '') && (string)($d['numero'] ?? '') === (string)$new['numero']) {
                return new WP_Error('duplicate', 'Le numéro ' . $new['numero'] . ' est déjà utilisé. Recharge la page puis émets à nouveau.', ['status' => 409]);
            }
        }
    }
    return null;
}
function gpf_ksort_deep($a) {
    if (!is_array($a)) return is_float($a) || is_int($a) ? (float)$a : $a;
    $isList = array_keys($a) === range(0, count($a) - 1);
    $out = [];
    foreach ($a as $k => $v) $out[$k] = gpf_ksort_deep($v);
    if (!$isList) ksort($out);
    return $out;
}

/* ------------------------------------------------------------------ */
/*  E-mail sending                                                     */
/* ------------------------------------------------------------------ */

function gpf_mail_headers() {
    $s = gpf_settings();
    $from = gpf_opt('from_email', get_option('admin_email'));
    $name = str_replace(['"', "\r", "\n", '<', '>'], '', wp_specialchars_decode(gpf_opt('from_name', $s['nom'] ?? get_bloginfo('name'))));
    $h = ['Content-Type: text/plain; charset=UTF-8'];
    if (is_email($from)) $h[] = 'From: "' . $name . '" <' . $from . '>';
    $reply = gpf_opt('reply_to', $s['email'] ?? '');
    if ($reply && is_email($reply)) $h[] = 'Reply-To: ' . $reply;
    return $h;
}

function gpf_rest_companies($req) {
    $q = trim(sanitize_text_field((string)$req->get_param('q')));
    if (mb_strlen($q) < 3) return rest_ensure_response(['results' => []]);
    $key = 'gpf_co_' . md5(mb_strtolower($q));
    $cached = get_transient($key);
    if (is_array($cached)) return rest_ensure_response(['results' => $cached]);
    $res = wp_remote_get('https://recherche-entreprises.api.gouv.fr/search?per_page=8&q=' . rawurlencode($q), ['timeout' => 12, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('annuaire', "L'annuaire des entreprises ne répond pas. Réessaie dans un instant.", ['status' => 502]);
    $j = json_decode(wp_remote_retrieve_body($res), true);
    $out = [];
    foreach (($j['results'] ?? []) as $r) {
        $sg = $r['siege'] ?? [];
        $street = trim(implode(' ', array_filter([(string)($sg['numero_voie'] ?? ''), (string)($sg['indice_repetition'] ?? ''), (string)($sg['type_voie'] ?? ''), (string)($sg['libelle_voie'] ?? '')], 'strlen')));
        $comp = trim((string)($sg['complement_adresse'] ?? ''));
        $siren = (string)($r['siren'] ?? '');
        $tva = is_array($r['tva'] ?? null) && !empty($r['tva'][0]) ? (string)$r['tva'][0] : (preg_match('/^\d{9}$/', $siren) ? 'FR' . str_pad((string)((12 + 3 * ((int)$siren % 97)) % 97), 2, '0', STR_PAD_LEFT) . $siren : '');
        $out[] = ['nom' => (string)($r['nom_complet'] ?? $r['nom_raison_sociale'] ?? ''), 'siren' => $siren, 'tva' => $tva,
            'adresse' => trim(($comp ? $comp . ', ' : '') . $street, ', '), 'cp' => (string)($sg['code_postal'] ?? ''), 'ville' => (string)($sg['libelle_commune'] ?? ''),
            'actif' => ($r['etat_administratif'] ?? 'A') === 'A'];
    }
    set_transient($key, $out, DAY_IN_SECONDS);
    return rest_ensure_response(['results' => $out]);
}

function gpf_rest_mail($req) {
    $b = $req->get_json_params();
    $to = $b['to'] ?? [];
    if (is_string($to)) $to = [$to];
    $to = array_values(array_filter($to, 'is_email'));
    if (!$to) return new WP_Error('bad', 'Adresse e-mail invalide', ['status' => 400]);
    $subject = sanitize_text_field($b['subject'] ?? '');
    $body = (string)($b['body'] ?? '');
    $headers = gpf_mail_headers();
    $copy = gpf_opt('copy_to', gpf_settings()['email'] ?? '');
    if ($copy && is_email($copy) && !in_array($copy, $to, true)) $headers[] = 'Bcc: ' . $copy;
    $files = [];
    $dir = trailingslashit(get_temp_dir()) . 'gpf-' . wp_generate_password(12, false);
    wp_mkdir_p($dir);
    foreach (($b['attachments'] ?? []) as $a) {
        $name = sanitize_file_name($a['filename'] ?? 'document.pdf');
        $bin = base64_decode((string)($a['content'] ?? ''), true);
        if ($bin === false || strlen($bin) > 15 * 1024 * 1024) continue;
        $p = $dir . '/' . $name;
        file_put_contents($p, $bin);
        $files[] = $p;
    }
    $ok = wp_mail($to, $subject, $body, $headers, $files);
    foreach ($files as $f) @unlink($f);
    @rmdir($dir);
    if (!$ok) return new WP_Error('mail', "L'e-mail n'a pas pu partir. Vérifie la configuration e-mail du site.", ['status' => 500]);
    return rest_ensure_response(['ok' => true, 'sent' => true]);
}

/* ------------------------------------------------------------------ */
/*  SumUp: pay page creates a hosted checkout at click time            */
/* ------------------------------------------------------------------ */

function gpf_find_by_token($token, $field = 'payToken') {
    foreach (gpf_all('docs') as $r) {
        $d = json_decode($r['data'], true);
        if (is_array($d) && ($d[$field] ?? '') !== '' && hash_equals((string)$d[$field], (string)$token)) { $d['id'] = $r['id']; return $d; }
    }
    return null;
}

function gpf_sumup($method, $path, $body = null) {
    $opt = get_option('gpf_options', []);
    $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . ($opt['sumup_key'] ?? ''), 'Content-Type' => 'application/json', 'Accept' => 'application/json']];
    if ($body !== null) $args['body'] = wp_json_encode($body);
    $res = wp_remote_request('https://api.sumup.com' . $path, $args);
    if (is_wp_error($res)) return [0, ['message' => $res->get_error_message()]];
    return [wp_remote_retrieve_response_code($res), json_decode(wp_remote_retrieve_body($res), true)];
}

function gpf_mark_paid($docId, $checkoutId) {
    $d = gpf_get('docs', $docId);
    if (!$d || ($d['statut'] ?? '') !== 'emise') return;
    $d['statut'] = 'payee';
    $d['paiement'] = ['date' => gpf_today(), 'mode' => 'Lien de paiement SumUp', 'ref' => $checkoutId, 'auto' => true];
    gpf_put('docs', $docId, $d);
}

function gpf_check_checkout($cid) {
    list($code, $c) = gpf_sumup('GET', '/v0.1/checkouts/' . rawurlencode($cid));
    if ($code !== 200 || !is_array($c)) return null;
    $status = strtoupper($c['status'] ?? '');
    $ref = (string)($c['checkout_reference'] ?? '');
    if ($status === 'PAID' && preg_match('/^gpf-([A-Za-z0-9_\-]+)-\d+$/', $ref, $m)) gpf_mark_paid($m[1], $cid);
    if ($status === 'PAID' && preg_match('/^gpfq-([A-Za-z0-9_\-]+)-\d+$/', $ref, $m)) gpf_deposit_paid($m[1], $cid);
    return $status;
}

function gpf_pay_page($title, $html) {
    $s = gpf_settings();
    gpf_nocache();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    $logo = !empty($s['logo']) ? '<img src="' . esc_attr($s['logo']) . '" alt="" style="max-height:60px;max-width:200px;margin-bottom:12px">' : '';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($title) . '</title>
<style>body{margin:0;background:#F1F4F3;color:#14201D;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:480px;margin:40px auto;padding:0 16px}.card{background:#fff;border-radius:14px;padding:28px 24px;box-shadow:0 6px 24px rgba(15,30,26,.08)}h1{font-size:22px;margin:0 0 6px}.muted{color:#5A6864}.amt{font-size:32px;font-weight:700;margin:16px 0;font-variant-numeric:tabular-nums}.btn{display:block;text-align:center;background:#F2711C;color:#fff;text-decoration:none;font-weight:600;padding:14px;border-radius:10px;border:0;font-size:17px;width:100%;cursor:pointer}.ok{color:#1E7A3B;font-weight:600}.kv{display:flex;justify-content:space-between;border-top:1px solid #DCE2E0;padding:8px 0;font-size:15px}.kv.tot{font-weight:700;font-size:17px}.evt{margin:14px 0;padding:8px 12px;border-left:3px solid #0A6B60;background:#F4F8F7;font-size:15px}.tb{width:100%;border-collapse:collapse;margin:14px 0;font-size:15px}.tb th{text-align:left;font-size:12px;text-transform:uppercase;color:#5A6864;border-bottom:1.5px solid #14201D;padding:6px 4px}.tb td{padding:8px 4px;border-bottom:1px solid #DCE2E0;vertical-align:top}.tb small{display:block;color:#5A6864;font-size:13px}.r{text-align:right;white-space:nowrap}.cond{margin-top:16px;font-size:14px}.cond ul{padding-left:18px;margin:6px 0}.acc{margin-top:20px;display:flex;flex-direction:column;gap:12px}.acc label{display:flex;flex-direction:column;gap:6px;font-weight:600;font-size:15px}.acc input[type=text]{font:inherit;padding:12px;border:1px solid #C9D2CF;border-radius:8px}.acc .chk{flex-direction:row;align-items:flex-start;gap:10px;font-weight:400}.acc .chk input{width:20px;height:20px;flex:none;margin-top:2px}.err{color:#B42318;font-weight:600}main{max-width:620px}</style></head><body><main><div class="card">'
        . $logo . $html . '</div><p class="muted" style="text-align:center;font-size:13px;margin-top:14px">' . esc_html($s['nom'] ?? '') . '</p></main></body></html>';
}

function gpf_render_pay($token) {
    $opt = get_option('gpf_options', []);
    $d = gpf_find_by_token($token);
    if (!$d || ($d['type'] ?? '') !== 'facture' || empty($d['numero'])) { status_header(404); gpf_pay_page('Lien introuvable', '<h1>Lien introuvable</h1><p class="muted">Ce lien de paiement n\'existe pas ou n\'est plus valable. Contactez-nous pour régler votre facture.</p>'); return; }
    $s = gpf_settings();
    $t = gpf_totals($d, $s);
    $label = !empty($d['acompteDe']) ? "Facture d'acompte" : 'Facture';
    $head = '<h1>' . esc_html($label . ' n° ' . $d['numero']) . '</h1><div class="muted">' . esc_html($d['client']['nom'] ?? '') . (!empty($d['objet']) ? ' · ' . esc_html($d['objet']) : '') . '</div>';

    // Return from SumUp: check stored checkouts
    if (isset($_GET['retour']) && ($d['statut'] ?? '') === 'emise') {
        foreach (array_reverse($d['sumupCheckouts'] ?? []) as $cid) { if (gpf_check_checkout($cid) === 'PAID') break; }
        $fresh = gpf_get('docs', $d['id']);
        if (is_array($fresh)) $d = $fresh + ['id' => $d['id']];
    }
    if (($d['statut'] ?? '') === 'payee') { gpf_pay_page('Facture réglée', $head . '<div class="amt">' . gpf_eur($t['ttc']) . '</div><p class="ok">Cette facture est réglée. Merci !</p>'); return; }
    if (($d['statut'] ?? '') !== 'emise') { gpf_pay_page('Paiement indisponible', $head . '<p class="muted">Cette facture ne peut pas être réglée en ligne. Contactez-nous.</p>'); return; }
    if (empty($opt['sumup_key']) || empty($opt['sumup_merchant'])) { gpf_pay_page('Paiement indisponible', $head . '<p class="muted">Le paiement en ligne n\'est pas encore activé. Contactez-nous pour régler votre facture.</p>'); return; }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['gpf_pay_now'])) { if (!gpf_throttle('gpf_pay_now' . ($d['id'] ?? ''))) { gpf_too_many(); return; }
        $ref = 'gpf-' . $d['id'] . '-' . time();
        $payUrl = home_url('/' . GPF_SLUG . '/payer/' . $token . '/');
        list($code, $c) = gpf_sumup('POST', '/v0.1/checkouts', [
            'checkout_reference' => $ref,
            'amount' => $t['ttc'],
            'currency' => 'EUR',
            'merchant_code' => $opt['sumup_merchant'],
            'description' => $label . ' ' . $d['numero'] . ' – ' . ($s['nom'] ?? ''),
            'redirect_url' => add_query_arg('retour', '1', $payUrl),
            'return_url' => rest_url('gpf/v1/sumup-webhook'),
            'hosted_checkout' => ['enabled' => true],
        ]);
        if (($code === 200 || $code === 201) && !empty($c['hosted_checkout_url']) && !empty($c['id'])) {
            $fresh = gpf_get('docs', $d['id']) ?: [];
            $fresh['sumupCheckouts'] = array_slice(array_merge($fresh['sumupCheckouts'] ?? [], [$c['id']]), -20);
            gpf_put('docs', $d['id'], $fresh);
            wp_redirect($c['hosted_checkout_url']); return;
        }
        $msg = is_array($c) ? ($c['message'] ?? ($c['error_message'] ?? '')) : '';
        gpf_pay_page('Paiement indisponible', $head . '<p class="muted">Le paiement par carte est momentanément indisponible. Merci de réessayer plus tard ou de nous contacter.</p>' . (current_user_can('manage_options') ? '<p class="muted" style="font-size:13px">Détail (visible par l\'administrateur) : ' . esc_html($code . ' ' . $msg) . '</p>' : ''));
        return;
    }
    $form = '<form method="post"><button class="btn" name="gpf_pay_now" value="1">Payer ' . esc_html(gpf_eur($t['ttc'])) . ' par carte</button></form>';
    $info = '<div class="kv"><span class="muted">Date</span><span>' . esc_html(gpf_fmt_date($d['date'] ?? '')) . '</span></div><div class="kv"><span class="muted">Échéance</span><span>' . esc_html(gpf_fmt_date($d['echeance'] ?? '')) . '</span></div>';
    $note = isset($_GET['retour']) ? '<p class="muted" style="font-size:14px">Le paiement n\'a pas encore été confirmé. S\'il vient d\'être effectué, actualisez cette page dans un instant.</p>' : '';
    gpf_pay_page('Régler la facture ' . $d['numero'], $head . '<div class="amt">' . gpf_eur($t['ttc']) . '</div>' . $note . $form . '<div style="margin-top:16px">' . $info . '</div><p class="muted" style="font-size:13px;margin-top:12px">Paiement sécurisé par SumUp.</p>');
}

/* ------------------------------------------------------------------ */
/*  Online quote acceptance: /facturation/devis/{token}                */
/* ------------------------------------------------------------------ */

function gpf_cond_lines($d, $s) {
    $lines = array_filter(array_map('trim', explode("\n", (string)($s['conditionsDevis'] ?? ''))));
    $ac = !empty($d['acompteDemande']);
    $out = [];
    foreach ($lines as $x) {
        if (isset($d['montantFerme']) && $d['montantFerme'] === false && stripos($x, 'nombre de participants') !== false) continue;
        if (!$ac) $x = preg_replace("/\\s+et de l['’]acompte(?=\\s*\\.?\\s*$)/iu", '', $x);
        $out[] = $x;
    }
    return $out;
}

function gpf_quote_page_html($d, $s, $notice = '') {
    $t = gpf_totals($d, $s);
    $ttcMode = ($d['saisie'] ?? '') === 'ttc';
    $fr = ($s['regimeTva'] ?? '') === 'franchise';
    $e = 'esc_html';
    $h = '<h1>Devis n° ' . $e($d['numero']) . '</h1><div class="muted">' . $e($d['client']['nom'] ?? '') . (!empty($d['objet']) ? ' · ' . $e($d['objet']) : '') . '</div>';
    $ev = [];
    if (!empty($d['evtDate'])) { $ev[] = 'Session le ' . gpf_fmt_date($d['evtDate']) . (!empty($d['evtHeure']) ? ' à ' . str_replace(':', 'h', $d['evtHeure']) : '') . (!empty($d['evtDuree']) ? ' · durée ' . $d['evtDuree'] : ''); }
    if (!empty($d['participants'])) $ev[] = 'Participants : ' . $d['participants'] . (!empty($d['accompagnateurs']) ? ' · Accompagnateurs : ' . $d['accompagnateurs'] : '');
    if (!empty($d['evtNote'])) $ev[] = $d['evtNote'];
    if ($ev) $h .= '<div class="evt">' . implode('<br>', array_map($e, $ev)) . '</div>';
    $h .= '<table class="tb"><thead><tr><th>Désignation</th><th class="r">Qté</th><th class="r">' . ($ttcMode ? 'Total TTC' : 'Total HT') . '</th></tr></thead><tbody>';
    foreach (($d['lignes'] ?? []) as $l) {
        if (empty($l['designation'])) continue;
        $q = (float)($l['qte'] ?? 0);
        $rate = $fr ? 0 : (float)($l['tva'] ?? 0);
        $tot = $ttcMode ? round($q * (float)($l['puTTC'] ?? 0), 2) : round($q * (float)($l['pu'] ?? 0), 2);
        $h .= '<tr><td>' . $e($l['designation']) . (!empty($l['details']) ? '<small>' . nl2br($e($l['details'])) . '</small>' : '') . '</td><td class="r">' . $e(rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ',') . ' ' . ($l['unite'] ?? '')) . '</td><td class="r">' . gpf_eur($tot) . '</td></tr>';
    }
    $h .= '</tbody></table><div class="kv"><span class="muted">Total HT</span><span>' . gpf_eur($t['ht']) . '</span></div>';
    if (!$fr) $h .= '<div class="kv"><span class="muted">TVA</span><span>' . gpf_eur($t['tva']) . '</span></div>';
    $h .= '<div class="kv tot"><span>Total TTC</span><span>' . gpf_eur($t['ttc']) . '</span></div>';
    if (!empty($d['acompteDemande'])) { $pct = (float)($d['acomptePct'] ?? 30); $h .= '<div class="kv"><span class="muted">Acompte à verser (' . $e(rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')) . ' %)</span><span>' . gpf_eur(round($t['ttc'] * $pct / 100, 2)) . '</span></div>'; }
    $cond = gpf_cond_lines($d, $s);
    if ($cond) $h .= '<div class="cond"><b>Conditions de réservation</b><ul>' . implode('', array_map(function ($x) use ($e) { return '<li>' . $e($x) . '</li>'; }, $cond)) . '</ul></div>';
    return $h . $notice;
}

function gpf_render_quote($token) {
    $d = gpf_find_by_token($token, 'signToken');
    if (!$d || ($d['type'] ?? '') !== 'devis' || empty($d['numero'])) { status_header(404); gpf_pay_page('Devis introuvable', '<h1>Devis introuvable</h1><p class="muted">Ce lien n\'existe pas ou n\'est plus valable. Contactez-nous.</p>'); return; }
    $s = gpf_settings();
    $snap = !empty($d['entreprise']) && is_array($d['entreprise']) ? array_merge($s, $d['entreprise']) : $s;
    $st = $d['statut'] ?? '';
    $title = 'Devis ' . $d['numero'];
    if (in_array($st, ['accepte', 'facture'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['gpf_pay_deposit'])) { if (!gpf_throttle('gpf_pay_deposit' . ($d['id'] ?? ''))) { gpf_too_many(); return; }
            $go = gpf_deposit_start($d, $snap, $token);
            if ($go) { wp_redirect($go); return; }
            gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="muted">Le paiement par carte est momentanément indisponible. Merci de réessayer plus tard ou de nous contacter.</p>')); return;
        }
        if (isset($_GET['retour'])) {
            foreach (array_reverse($d['acompteCheckouts'] ?? []) as $cid) { if (gpf_check_checkout($cid) === 'PAID') break; }
            $fresh = gpf_get('docs', $d['id']); if (is_array($fresh)) $d = $fresh + ['id' => $d['id']];
        }
        $a = $d['acceptation'] ?? [];
        $msg = '<p class="ok">Ce devis a été accepté' . (!empty($a['date']) ? ' le ' . esc_html(gpf_fmt_date(substr($a['date'], 0, 10))) : '') . (!empty($a['nom']) ? ' par ' . esc_html($a['nom']) : '') . '. Merci !</p>';
        gpf_pay_page($title, gpf_quote_page_html($d, $snap, $msg . gpf_deposit_block($d, $snap))); return;
    }
    if ($st === 'annule') { gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="muted">Ce devis a été annulé. Contactez-nous pour un nouveau devis.</p>')); return; }
    if ($st === 'refuse') { gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="muted">Ce devis n\'est plus disponible. Contactez-nous pour un nouveau devis.</p>')); return; }
    if (!in_array($st, ['emis', 'envoye'], true)) { gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="muted">Ce devis ne peut pas être accepté en ligne. Contactez-nous.</p>')); return; }
    if (!empty($d['validite']) && $d['validite'] < gpf_today()) { gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="muted">Ce devis a expiré le ' . esc_html(gpf_fmt_date($d['validite'])) . '. Contactez-nous pour le renouveler.</p>')); return; }

    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['gpf_accept'])) { if (!gpf_throttle('gpf_accept' . ($d['id'] ?? ''))) { gpf_too_many(); return; }
        $nom = trim(sanitize_text_field(wp_unslash($_POST['nom'] ?? '')));
        if (mb_strlen($nom) < 2) $err = 'Indiquez votre nom et prénom.';
        elseif (empty($_POST['bon'])) $err = 'Cochez la case « Bon pour accord » pour accepter le devis.';
        else {
            $fresh = gpf_get('docs', $d['id']);
            if (is_array($fresh) && in_array($fresh['statut'] ?? '', ['emis', 'envoye'], true)) {
                $fresh['statut'] = 'accepte';
                $fresh['acceptation'] = ['date' => (new DateTime('now', new DateTimeZone(GPF_TZ)))->format('c'), 'nom' => $nom, 'ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''), 'navigateur' => mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), 'montantTTC' => gpf_totals($fresh, $snap)['ttc'], 'enLigne' => true];
                gpf_put('docs', $d['id'], $fresh);
                $to = gpf_opt('copy_to', !empty($s['email']) ? $s['email'] : get_option('admin_email'));
                if (is_email($to)) wp_mail($to, 'Devis ' . $d['numero'] . ' accepté en ligne', "Bonne nouvelle !\n\nLe devis " . $d['numero'] . ' (' . ($d['client']['nom'] ?? '') . ', ' . gpf_eur(gpf_totals($fresh, $snap)['ttc']) . ") vient d'être accepté en ligne par " . $nom . ".\n\nOuvrir l'outil : " . home_url('/' . GPF_SLUG . '/') . "\n", gpf_mail_headers());
                $d = $fresh + ['id' => $d['id']];
            }
            $dep = gpf_deposit_block($d, $snap);
            gpf_pay_page($title, gpf_quote_page_html($d, $snap, '<p class="ok">Merci ' . esc_html($nom) . ', votre devis est accepté.' . ($dep ? '' : ' Nous revenons vers vous très vite.') . '</p>' . $dep));
            return;
        }
    }
    $form = '<form method="post" class="acc">' . ($err ? '<p class="err">' . esc_html($err) . '</p>' : '')
        . '<label>Votre nom et prénom<input type="text" name="nom" required autocomplete="name" value="' . esc_attr(wp_unslash($_POST['nom'] ?? '')) . '"></label>'
        . '<label class="chk"><input type="checkbox" name="bon" value="1"> Bon pour accord : j\'accepte ce devis et les conditions de réservation ci-dessus.</label>'
        . '<button class="btn" name="gpf_accept" value="1">Accepter le devis</button>'
        . '<p class="muted" style="font-size:13px">Valable jusqu\'au ' . esc_html(gpf_fmt_date($d['validite'] ?? '')) . '. Votre acceptation est enregistrée avec la date et l\'heure.</p></form>';
    gpf_pay_page($title, gpf_quote_page_html($d, $snap) . $form);
}

/* ------------------------------------------------------------------ */
/*  Deposit paid online right after the quote is accepted              */
/* ------------------------------------------------------------------ */

function gpf_deposit_amount($d, $snap) {
    if (empty($d['acompteDemande'])) return 0;
    $pct = (float)($d['acomptePct'] ?? 30);
    return gpf_r2(gpf_totals($d, $snap)['ttc'] * $pct / 100);
}
function gpf_issued_deposits($devisId) {
    $out = [];
    foreach (gpf_all('docs') as $r) {
        $x = json_decode($r['data'], true);
        if (is_array($x) && ($x['type'] ?? '') === 'facture' && !empty($x['numero']) && ($x['acompteDe'] ?? '') === $devisId) { $x['id'] = $r['id']; $out[] = $x; }
    }
    return $out;
}
function gpf_deposit_block($d, $snap) {
    $amt = gpf_deposit_amount($d, $snap);
    if ($amt <= 0 || !in_array($d['statut'] ?? '', ['accepte', 'facture'], true)) return '';
    $deps = gpf_issued_deposits($d['id']);
    if ($deps) {
        $paid = array_filter($deps, function ($x) { return ($x['statut'] ?? '') === 'payee'; });
        return $paid ? '<p class="ok">Acompte réglé. Votre réservation est confirmée, merci !</p>' : '';
    }
    if (!gpf_opt('sumup_key') || !gpf_opt('sumup_merchant')) return '<p class="muted">Nous vous enverrons la facture d\'acompte de ' . gpf_eur($amt) . ' pour confirmer la réservation.</p>';
    $pct = (float)($d['acomptePct'] ?? 30);
    return '<form method="post" class="acc"><p style="margin:0">Pour confirmer votre réservation, réglez l\'acompte de <b>' . esc_html(rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')) . ' %</b>, soit <b>' . gpf_eur($amt) . '</b>.</p>'
        . '<button class="btn" name="gpf_pay_deposit" value="1">Payer l\'acompte de ' . esc_html(gpf_eur($amt)) . ' par carte</button>'
        . (isset($_GET['retour']) ? '<p class="muted" style="font-size:14px">Le paiement n\'a pas encore été confirmé. S\'il vient d\'être effectué, actualisez cette page dans un instant.</p>' : '')
        . '<p class="muted" style="font-size:13px">Paiement sécurisé par SumUp. La facture d\'acompte vous sera envoyée par e-mail.</p></form>';
}
function gpf_deposit_start($d, $snap, $token) {
    $amt = gpf_deposit_amount($d, $snap);
    if ($amt <= 0 || gpf_issued_deposits($d['id'])) return false;
    $s = gpf_settings();
    $url = home_url('/' . GPF_SLUG . '/devis/' . $token . '/');
    list($code, $c) = gpf_sumup('POST', '/v0.1/checkouts', [
        'checkout_reference' => 'gpfq-' . $d['id'] . '-' . time(),
        'amount' => $amt,
        'currency' => 'EUR',
        'merchant_code' => gpf_opt('sumup_merchant'),
        'description' => 'Acompte devis ' . $d['numero'] . ' – ' . ($s['nom'] ?? ''),
        'redirect_url' => add_query_arg('retour', '1', $url),
        'return_url' => rest_url('gpf/v1/sumup-webhook'),
        'hosted_checkout' => ['enabled' => true],
    ]);
    if (($code === 200 || $code === 201) && !empty($c['hosted_checkout_url']) && !empty($c['id'])) {
        $fresh = gpf_get('docs', $d['id']) ?: [];
        $fresh['acompteCheckouts'] = array_slice(array_merge($fresh['acompteCheckouts'] ?? [], [$c['id']]), -20);
        gpf_put('docs', $d['id'], $fresh);
        return $c['hosted_checkout_url'];
    }
    return false;
}
function gpf_tvas($d, $snap) {
    // VAT base and amount per rate, same rules as gpf_totals
    $fr = ($snap['regimeTva'] ?? '') === 'franchise';
    $ttcMode = ($d['saisie'] ?? '') === 'ttc';
    $by = [];
    foreach (($d['lignes'] ?? []) as $l) {
        $t = $fr ? 0 : (float)($l['tva'] ?? 0); $k = (string)$t; $q = (float)($l['qte'] ?? 0);
        if (!isset($by[$k])) $by[$k] = ['rate' => $t, 'ht' => 0, 'ttc' => 0, 'norm' => 0, 'fixed' => 0];
        if ($ttcMode) { $lht = gpf_r2($q * (float)($l['puTTC'] ?? 0) / (1 + $t / 100)); $by[$k]['ttc'] += gpf_r2($q * (float)($l['puTTC'] ?? 0)); }
        else $lht = gpf_r2($q * (float)($l['pu'] ?? 0));
        $by[$k]['ht'] += $lht;
        if (!$ttcMode && !empty($l['acompteRef']) && isset($l['puTTC'])) $by[$k]['fixed'] += gpf_r2(gpf_r2($q * (float)$l['puTTC']) - $lht); else $by[$k]['norm'] += $lht;
    }
    $out = [];
    foreach ($by as $g) {
        $m = $ttcMode ? gpf_r2($g['ttc'] - $g['ht']) : gpf_r2(gpf_r2($g['norm'] * $g['rate'] / 100) + $g['fixed']);
        $out[] = ['rate' => $g['rate'], 'base' => gpf_r2($g['ht']), 'montant' => $m];
    }
    usort($out, function ($a, $b) { return $b['rate'] <=> $a['rate']; });
    return $out;
}
function gpf_next_invoice_number($test, $date) {
    $s = gpf_settings();
    $max = 0;
    foreach (gpf_all('docs') as $r) {
        $x = json_decode($r['data'], true);
        if (is_array($x) && ($x['type'] ?? '') === 'facture' && !empty($x['seq']) && !empty($x['test']) === (bool)$test) $max = max($max, (int)$x['seq']);
    }
    $start = $test ? 1 : max(1, (int)($s['debutFacture'] ?? 1));
    $seq = max($start, $max + 1);
    $parts = array_filter([$test ? 'TEST' : '', (string)($s['prefixeFacture'] ?? ''), !empty($s['annee']) ? substr($date, 0, 4) : '', str_pad((string)$seq, 4, '0', STR_PAD_LEFT)], function ($p) { return $p !== ''; });
    return [$seq, implode('-', $parts)];
}
function gpf_deposit_paid($devisId, $cid) {
    $lock = 'gpf_lock_dep_' . md5($devisId);
    if (!add_option($lock, time(), '', 'no')) { if ((int)get_option($lock) < time() - 120) { delete_option($lock); } return; }
    try {
        $d = gpf_get('docs', $devisId);
        if (!is_array($d) || !empty($d['acompteEnLigne']['factureId']) || gpf_issued_deposits($devisId)) return;
        $s = gpf_settings();
        $snap = !empty($d['entreprise']) && is_array($d['entreprise']) ? array_merge($s, $d['entreprise']) : $s;
        $t = gpf_totals($d + ['id' => $devisId], $snap);
        $amt = gpf_deposit_amount($d, $snap);
        if ($amt <= 0 || $t['ttc'] <= 0) return;
        $ratio = $amt / $t['ttc'];
        $pct = (float)($d['acomptePct'] ?? 30);
        $pctTxt = rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',') . ' %';
        $tvas = gpf_tvas($d, $snap);
        $recapLines = ['Rappel du devis' . (!empty($d['objet']) ? ' « ' . $d['objet'] . ' »' : '') . ' :'];
        $ttcMode = ($d['saisie'] ?? '') === 'ttc';
        foreach (($d['lignes'] ?? []) as $l) {
            if (empty($l['designation']) || !empty($l['acompteRef'])) continue;
            $q = (float)($l['qte'] ?? 0); $rate = (($snap['regimeTva'] ?? '') === 'franchise') ? 0 : (float)($l['tva'] ?? 0);
            $tot = $ttcMode ? gpf_r2($q * (float)($l['puTTC'] ?? 0)) : gpf_r2(gpf_r2($q * (float)($l['pu'] ?? 0)) * (1 + $rate / 100));
            $recapLines[] = '• ' . (empty($l['remise']) ? rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ',') . ' × ' : '') . trim($l['designation']) . ' : ' . gpf_eur($tot) . ' TTC';
        }
        $recapLines[] = 'Total du devis : ' . gpf_eur($t['ttc']) . ' TTC';
        $lignes = []; $sum = 0;
        foreach ($tvas as $i => $g) {
            $puTTC = gpf_r2(($g['base'] + $g['montant']) * $ratio); $sum += $puTTC;
            $lignes[] = ['id' => 'l' . wp_generate_password(10, false), 'designation' => 'Acompte de ' . $pctTxt . ' sur le devis n° ' . $d['numero'], 'details' => (count($tvas) > 1 ? 'Part soumise à la TVA de ' . str_replace('.', ',', (string)$g['rate']) . " %\n" : '') . implode("\n", $recapLines), 'qte' => 1, 'unite' => 'forfait', 'puTTC' => $puTTC, 'tva' => $g['rate']];
        }
        if (!$lignes) return;
        $lignes[0]['puTTC'] = gpf_r2($lignes[0]['puTTC'] + gpf_r2($amt - $sum));
        foreach ($lignes as &$l) $l['pu'] = gpf_r2($l['puTTC'] / (1 + $l['tva'] / 100));
        unset($l);
        $today = gpf_today();
        $test = !empty($d['test']);
        list($seq, $numero) = gpf_next_invoice_number($test, $today);
        $ent = $s; unset($ent['logo'], $ent['logoW'], $ent['logoH']);
        $now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $f = ['type' => 'facture', 'statut' => 'payee', 'saisie' => 'ttc', 'date' => $today, 'echeance' => $today, 'clientId' => $d['clientId'] ?? '', 'client' => $d['client'] ?? null,
            'objet' => 'Acompte – ' . (!empty($d['objet']) ? $d['objet'] : 'devis ' . $d['numero']), 'nature' => 'services', 'lignes' => $lignes, 'notes' => '', 'lienPaiement' => '',
            'acompteDe' => $devisId, 'acompteDevisNumero' => $d['numero'], 'seq' => $seq, 'numero' => $numero, 'emisLe' => $now, 'entreprise' => $ent,
            'paiement' => ['date' => $today, 'mode' => 'Lien de paiement SumUp', 'ref' => $cid, 'auto' => true], 'creeEnLigne' => true, 'createdAt' => $now, 'updatedAt' => $now];
        foreach (['evtDate', 'evtHeure', 'evtDuree', 'participants', 'accompagnateurs', 'evtNote'] as $k) if (isset($d[$k]) && $d[$k] !== '') $f[$k] = $d[$k];
        if ($test) $f['test'] = true;
        $fid = 'x' . base_convert((string)time(), 10, 36) . strtolower(wp_generate_password(6, false));
        gpf_put('docs', $fid, $f);
        $d['acompteEnLigne'] = ['factureId' => $fid, 'numero' => $numero, 'checkout' => $cid, 'date' => $today, 'montant' => $amt];
        gpf_put('docs', $devisId, $d);
        $to = gpf_opt('copy_to', !empty($s['email']) ? $s['email'] : get_option('admin_email'));
        if (is_email($to)) wp_mail($to, 'Acompte reçu – devis ' . $d['numero'], "Bonne nouvelle !\n\n" . ($d['client']['nom'] ?? 'Le client') . ' a payé en ligne l\'acompte de ' . gpf_eur($amt) . ' pour le devis ' . $d['numero'] . ".\n\nLa facture d'acompte n° " . $numero . " a été créée et marquée payée.\nPense à l'envoyer au client depuis l'outil (Envoyer par e-mail) : " . home_url('/' . GPF_SLUG . '/') . "\n", gpf_mail_headers());
        $ce = $d['client']['email'] ?? '';
        if ($ce && is_email($ce)) wp_mail($ce, 'Acompte bien reçu – ' . ($s['nom'] ?? ''), "Bonjour,\n\nNous avons bien reçu votre acompte de " . gpf_eur($amt) . ' pour le devis n° ' . $d['numero'] . (!empty($d['evtDate']) ? ' (session du ' . gpf_fmt_date($d['evtDate']) . ')' : '') . ".\nVotre réservation est confirmée. Votre facture d'acompte n° " . $numero . " vous sera transmise.\n\nÀ très bientôt,\n" . ($s['nom'] ?? '') . (!empty($s['tel']) ? "\n" . $s['tel'] : '') . "\n", gpf_mail_headers());
    } finally {
        delete_option($lock);
    }
}

/* ------------------------------------------------------------------ */
/*  Automatic daily backup                                             */
/* ------------------------------------------------------------------ */

function gpf_backup_dir() {
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'gpf-backups';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!file_exists($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php // Silence\n");
    return $dir;
}
function gpf_backup_data() {
    $bk = ['app' => 'goplay-facturation', 'version' => 1, 'exportedAt' => (new DateTime('now', new DateTimeZone(GPF_TZ)))->format('c'), 'config' => ['entreprise' => gpf_settings()], 'clients' => [], 'articles' => [], 'formules' => [], 'docs' => []];
    foreach (gpf_all() as $r) {
        if (!isset($bk[$r['coll']]) || $r['coll'] === 'config') continue;
        $d = json_decode($r['data'], true);
        if (is_array($d)) { $d['id'] = $r['id']; $bk[$r['coll']][] = $d; }
    }
    return $bk;
}
function gpf_backup_files() {
    $files = glob(gpf_backup_dir() . '/sauvegarde-*.json') ?: [];
    rsort($files);
    return $files;
}
function gpf_backup_run() {
    $dir = gpf_backup_dir();
    $name = 'sauvegarde-' . (new DateTime('now', new DateTimeZone(GPF_TZ)))->format('Y-m-d-His') . '-' . wp_generate_password(8, false) . '.json';
    $json = wp_json_encode(gpf_backup_data(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!$json || !file_put_contents($dir . '/' . $name, $json)) return null;
    foreach (array_slice(gpf_backup_files(), 30) as $old) @unlink($old);
    update_option('gpf_last_backup', ['date' => current_time('mysql'), 'file' => $name, 'size' => strlen($json)]);
    return $dir . '/' . $name;
}
add_action('gpf_daily', function () {
    $file = gpf_backup_run();
    $s = gpf_settings();
    $isMonday = (new DateTime('now', new DateTimeZone(GPF_TZ)))->format('N') === '1';
    if ($file && gpf_opt('backup_email') && $isMonday) {
        $to = gpf_opt('copy_to', !empty($s['email']) ? $s['email'] : get_option('admin_email'));
        if (is_email($to)) wp_mail($to, 'Sauvegarde hebdomadaire de la facturation', "Bonjour,\n\nVoici la sauvegarde complète de ta facturation (clients, catalogue, devis, factures et réglages).\nGarde ce fichier en lieu sûr : il peut être réimporté dans l'outil (Réglages > Sauvegarde complète > Importer).\n", gpf_mail_headers(), [$file]);
    }
});
add_action('admin_post_gpf_backup_now', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('gpf_backup_now')) wp_die('Accès refusé', '', ['response' => 403]);
    $ok = gpf_backup_run();
    wp_safe_redirect(add_query_arg('gpf_msg', $ok ? 'backup_ok' : 'backup_fail', admin_url('options-general.php?page=gpf'))); exit;
});
add_action('admin_post_gpf_backup_dl', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('gpf_backup_dl')) wp_die('Accès refusé', '', ['response' => 403]);
    $f = basename((string)($_GET['f'] ?? ''));
    $path = gpf_backup_dir() . '/' . $f;
    if (!preg_match('/^sauvegarde-[A-Za-z0-9\-]+\.json$/', $f) || !file_exists($path)) wp_die('Fichier introuvable', '', ['response' => 404]);
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/-[A-Za-z0-9]{8}\.json$/', '.json', $f) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
});

/* ------------------------------------------------------------------ */
/*  Daily summary of overdue invoices                                  */
/* ------------------------------------------------------------------ */

add_action('gpf_daily', function () {
    $opt = get_option('gpf_options', []);
    if (empty($opt['daily_summary'])) return;
    $s = gpf_settings();
    $today = gpf_today();
    $late = [];
    foreach (gpf_all('docs') as $r) {
        $d = json_decode($r['data'], true);
        if (!is_array($d) || !empty($d['test']) || ($d['type'] ?? '') !== 'facture' || ($d['statut'] ?? '') !== 'emise') continue;
        if (!empty($d['echeance']) && $d['echeance'] < $today) $late[] = $d;
    }
    if (!$late) return;
    $lines = array_map(function ($d) use ($s) {
        return '- Facture ' . $d['numero'] . ' · ' . ($d['client']['nom'] ?? '') . ' · ' . gpf_eur(gpf_totals($d, $s)['ttc']) . ' · échue le ' . gpf_fmt_date($d['echeance']);
    }, $late);
    $to = gpf_opt('copy_to', !empty($s['email']) ? $s['email'] : get_option('admin_email'));
    wp_mail($to, count($late) . ' facture(s) en retard de paiement', "Bonjour,\n\nCes factures sont échues et pas encore réglées :\n\n" . implode("\n", $lines) . "\n\nPour envoyer une relance : " . home_url('/' . GPF_SLUG . '/') . "\n", gpf_mail_headers());
});

/* ------------------------------------------------------------------ */
/*  Settings page (secrets stay on the server)                         */
/* ------------------------------------------------------------------ */

add_action('admin_menu', function () {
    add_options_page('Goplay Facturation', 'Goplay Facturation', 'manage_options', 'gpf', 'gpf_settings_page');
});
add_action('admin_init', function () {
    register_setting('gpf', 'gpf_options', ['sanitize_callback' => function ($in) {
        $old = get_option('gpf_options', []);
        $out = [];
        $out['sumup_key'] = trim((string)($in['sumup_key'] ?? ''));
        if ($out['sumup_key'] === '' && !empty($old['sumup_key']) && empty($in['sumup_clear'])) $out['sumup_key'] = $old['sumup_key'];
        $out['sumup_merchant'] = sanitize_text_field($in['sumup_merchant'] ?? '');
        $out['from_email'] = sanitize_email($in['from_email'] ?? '');
        $out['from_name'] = sanitize_text_field($in['from_name'] ?? '');
        $out['reply_to'] = sanitize_email($in['reply_to'] ?? '');
        $out['copy_to'] = sanitize_email($in['copy_to'] ?? '');
        $out['daily_summary'] = !empty($in['daily_summary']) ? 1 : 0;
        $out['backup_email'] = !empty($in['backup_email']) ? 1 : 0;
        $out['secu'] = !empty($in['secu']) ? 'on' : 'off';
        $out['autoupdate'] = !empty($in['autoupdate']) ? 'on' : 'off';
        return $out;
    }]);
});
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($l) {
    array_unshift($l, '<a href="' . esc_url(home_url('/' . GPF_SLUG . '/')) . '">Ouvrir</a>', '<a href="' . esc_url(admin_url('options-general.php?page=gpf')) . '">Réglages</a>');
    return $l;
});

function gpf_settings_page() {
    $o = get_option('gpf_options', []);
    $v = function ($k, $d = '') use ($o) { return esc_attr($o[$k] ?? $d); };
    ?>
    <div class="wrap">
      <h1>Goplay Arena Facturation</h1>
      <p><a class="button button-primary" href="<?php echo esc_url(home_url('/' . GPF_SLUG . '/')); ?>">Ouvrir l'outil de facturation</a></p>
      <form method="post" action="options.php">
        <?php settings_fields('gpf'); ?>
        <h2>Envoi des e-mails</h2>
        <p>Les devis et factures partent directement depuis ton site. Utilise de préférence une adresse de ton domaine (par exemple facturation@goplayarena.re, à créer dans le cPanel d'o2switch) pour éviter les spams. Les réponses des clients arrivent à l'adresse « Réponses ».</p>
        <table class="form-table">
          <tr><th>Adresse d'envoi</th><td><input type="email" class="regular-text" name="gpf_options[from_email]" value="<?php echo $v('from_email', get_option('admin_email')); ?>"></td></tr>
          <tr><th>Nom affiché</th><td><input type="text" class="regular-text" name="gpf_options[from_name]" value="<?php echo $v('from_name', 'Goplay Arena'); ?>"></td></tr>
          <tr><th>Réponses des clients vers</th><td><input type="email" class="regular-text" name="gpf_options[reply_to]" value="<?php echo $v('reply_to'); ?>" placeholder="goplayarena974@gmail.com"></td></tr>
          <tr><th>Copie de chaque envoi à</th><td><input type="email" class="regular-text" name="gpf_options[copy_to]" value="<?php echo $v('copy_to'); ?>" placeholder="goplayarena974@gmail.com"><p class="description">Tu reçois une copie cachée de chaque devis et facture envoyés, et le résumé quotidien.</p></td></tr>
          <tr><th>Résumé des retards</th><td><label><input type="checkbox" name="gpf_options[daily_summary]" value="1" <?php checked(!empty($o['daily_summary'])); ?>> M'envoyer chaque matin la liste des factures en retard</label></td></tr>
        </table>
        <h2>Paiement par carte SumUp</h2>
        <p>Une fois rempli, chaque facture émise reçoit automatiquement un lien « Payer par carte » avec le bon montant. Quand le client paie, la facture passe toute seule en « Payée ». La clé reste sur ton serveur, elle n'est jamais affichée dans l'outil.</p>
        <table class="form-table">
          <tr><th>Clé API SumUp (secrète)</th><td><input type="password" class="regular-text" name="gpf_options[sumup_key]" value="" placeholder="<?php echo !empty($o['sumup_key']) ? 'Clé enregistrée (laisser vide pour la garder)' : 'sup_sk_…'; ?>" autocomplete="off"><?php if (!empty($o['sumup_key'])): ?><p><label><input type="checkbox" name="gpf_options[sumup_clear]" value="1"> Effacer la clé enregistrée</label></p><?php endif; ?><p class="description">À créer dans ton espace SumUp : Paramètres › Développeurs › Clés API.</p></td></tr>
          <tr><th>Code marchand SumUp</th><td><input type="text" class="regular-text" name="gpf_options[sumup_merchant]" value="<?php echo $v('sumup_merchant'); ?>" placeholder="ex. MXXXXXXX"><p class="description">Visible dans ton profil SumUp.</p></td></tr>
        </table>
        <h2>Sauvegarde automatique</h2>
        <table class="form-table">
          <tr><th>Envoi par e-mail</th><td><label><input type="checkbox" name="gpf_options[backup_email]" value="1" <?php checked(!empty($o['backup_email'])); ?>> M'envoyer la sauvegarde chaque lundi (à l'adresse « Copie de chaque envoi »)</label></td></tr>
        </table>
        <h2 id="gpf-maj">Mises à jour</h2>
        <table class="form-table">
          <tr><th>Mises à jour automatiques</th><td><label><input type="checkbox" name="gpf_options[autoupdate]" value="1" <?php checked(gpf_opt('autoupdate', 'on') !== 'off'); ?>> Installer toute seule chaque nouvelle version (recommandé)</label>
            <?php $ui = get_site_transient('gpf_update_info'); ?>
            <p class="description">Version installée : <b><?php echo esc_html(GPF_VERSION); ?></b><?php if (is_array($ui) && !empty($ui['ok'])): ?> · dernière version disponible : <b><?php echo esc_html($ui['version']); ?></b><?php endif; ?>. WordPress vérifie deux fois par jour ; tes données ne sont jamais touchées par une mise à jour.</p>
            <?php if (($_GET['gpf_msg'] ?? '') === 'update_checked'): ?><p><b><?php echo (is_array($ui) && !empty($ui['ok'])) ? (version_compare($ui['version'], GPF_VERSION, '>') ? 'Nouvelle version ' . esc_html($ui['version']) . ' trouvée : elle sera installée automatiquement, ou tout de suite depuis Tableau de bord › Mises à jour.' : 'Tu as déjà la dernière version.') : 'Impossible de joindre le serveur des mises à jour pour le moment.'; ?></b></p><?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=gpf_check_update'), 'gpf_check_update')); ?>">Vérifier maintenant</a></p></td></tr>
        </table>
        <h2>Sécurité du site</h2>
        <table class="form-table">
          <tr><th>Protection renforcée</th><td><label><input type="checkbox" name="gpf_options[secu]" value="1" <?php checked(gpf_secu_on()); ?>> Activer (recommandé)</label>
            <p class="description">Bloque l'ordinateur qui se trompe 5 fois de mot de passe (15 minutes), cache ton identifiant de connexion et la version de WordPress aux visiteurs, et ajoute les protections standard du navigateur. Ne change rien à l'affichage de ton site.</p>
            <?php $bl = gpf_login_blocked_count(); if ($bl): ?><p><b><?php echo (int)$bl; ?></b> tentative(s) de connexion bloquée(s) ces dernières 24 h.</p><?php endif; ?></td></tr>
        </table>
        <?php submit_button('Enregistrer'); ?>
      </form>
      <?php $last = get_option('gpf_last_backup'); $files = gpf_backup_files(); ?>
      <h2>Sauvegardes enregistrées</h2>
      <?php if (($_GET['gpf_msg'] ?? '') === 'backup_ok'): ?><div class="notice notice-success"><p>Sauvegarde créée.</p></div><?php elseif (($_GET['gpf_msg'] ?? '') === 'backup_fail'): ?><div class="notice notice-error"><p>La sauvegarde n'a pas pu être créée (droits d'écriture du dossier uploads).</p></div><?php endif; ?>
      <p>Une sauvegarde complète est faite automatiquement chaque nuit. Les 30 dernières sont gardées sur ton serveur, dans un dossier protégé. Pour en restaurer une : télécharge-la, puis dans l'outil, Réglages › Sauvegarde complète › Importer.</p>
      <p><?php echo $last ? 'Dernière sauvegarde : ' . esc_html(mysql2date('d/m/Y à H:i', $last['date'])) : 'Aucune sauvegarde pour l\'instant.'; ?>
        <a class="button" style="margin-left:8px" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=gpf_backup_now'), 'gpf_backup_now')); ?>">Faire une sauvegarde maintenant</a></p>
      <?php if ($files): ?><table class="widefat striped" style="max-width:640px"><thead><tr><th>Date</th><th>Taille</th><th></th></tr></thead><tbody>
        <?php foreach (array_slice($files, 0, 30) as $f): $b = basename($f); preg_match('/sauvegarde-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})/', $b, $m); ?>
          <tr><td><?php echo $m ? esc_html("$m[3]/$m[2]/$m[1] à $m[4]h$m[5]") : esc_html($b); ?></td><td><?php echo esc_html(size_format(filesize($f))); ?></td><td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=gpf_backup_dl&f=' . rawurlencode($b)), 'gpf_backup_dl')); ?>">Télécharger</a></td></tr>
        <?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
    <?php
}
