<?php
// NOTE: intentionally no scalar/return type declarations so this file
// parses on any PHP 7.x (shared hosts often run older defaults).

declare(strict_types=0);

header('X-Content-Type-Options: nosniff');

define('DIR', __DIR__ . '/uploadedpics');
define('DB', __DIR__ . '/comparisons.json');
define('LOG', __DIR__ . '/debug.log');
define('MAX_SIZE', 10 * 1024 * 1024); // 10 MB per file
define('TTL', 7 * 86400);             // keep for 7 days

$MIME_EXT = array(
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    'image/bmp'  => 'bmp',
);

function dbg($msg) {
    @file_put_contents(LOG, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// turn PHP fatals into readable JSON instead of a blank 500 page
set_exception_handler(function ($e) {
    dbg('EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array('ok' => false, 'error' => 'Server error: ' . $e->getMessage()));
});

function json_out($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    dbg('RESPONSE ' . $code . ': ' . json_encode($payload));
    echo json_encode($payload);
    exit;
}

function fail($msg, $code = 400, $extra = array()) {
    dbg('FAIL ' . $code . ': ' . $msg . ($extra ? ' | ' . json_encode($extra) : ''));
    $payload = array('ok' => false, 'error' => $msg);
    if ($extra) $payload['debug'] = $extra;
    json_out($payload, $code);
}

function load_db() {
    if (!is_file(DB)) return array();
    $raw = (string)file_get_contents(DB);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        dbg('WARNING: comparisons.json exists but is not valid JSON (' . strlen($raw) . ' bytes)');
        return array();
    }
    return $data;
}

function save_db($data) {
    $ok = file_put_contents(DB, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    dbg('SAVE comparisons.json: ' . ($ok === false ? 'FAILED' : $ok . ' bytes written'));
    return $ok !== false;
}

if (!is_dir(DIR)) {
    if (!mkdir(DIR, 0755, true)) fail('Cannot create storage directory.', 500, array('dir' => DIR));
}

$now = time();
$data = load_db();
$changed = false;
foreach ($data as $cid => $c) {
    if ($now > $c['expiresAt']) {
        @unlink(DIR . '/' . $c['before']);
        @unlink(DIR . '/' . $c['after']);
        unset($data[$cid]);
        $changed = true;
        dbg('PURGED expired comparison ' . $cid);
    }
}
if ($changed) save_db($data);

$method = $_SERVER['REQUEST_METHOD'];
dbg(sprintf(
    'REQUEST %s ?%s | php=%s db_exists=%s known_ids=%d dir_writable=%s',
    $method,
    isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
    PHP_VERSION,
    var_export(is_file(DB), true),
    count($data),
    var_export(is_writable(DIR), true)
));

/* ---------- GET ?diag=1 : in-browser diagnostics (no disk needed) ---------- */
if (isset($_GET['diag'])) {
    $logTest = @file_put_contents(LOG, date('Y-m-d H:i:s') . " diag write test\n", FILE_APPEND | LOCK_EX);
    json_out(array(
        'php_version' => PHP_VERSION,
        'os'          => PHP_OS,
        'server'      => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '?',
        'paths'       => array('dir' => DIR, 'db' => DB, 'log' => LOG),
        'writable'    => array(
            'upload_dir'   => is_writable(DIR),
            'db_folder'    => is_writable(dirname(DB)),
            'log_write'    => ($logTest === false) ? 'FAILED - cannot write debug.log' : 'OK (' . $logTest . ' bytes)',
        ),
        'db_exists'   => is_file(DB),
        'limits'      => array(
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'memory_limit'        => ini_get('memory_limit'),
        ),
        'fileinfo_ext'   => extension_loaded('fileinfo'),
        'random_bytes'   => function_exists('random_bytes') ? 'ok' : 'MISSING',
    ));
}

/* ---------- GET: fetch a shared comparison or its images ---------- */
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    $regexOk = (bool)preg_match('/^[a-f0-9]{32}$/', $id);

    if (!$regexOk) {
        fail('Not found.', 404, array(
            'reason'         => 'id in URL does not match the expected format (32 lowercase hex chars)',
            'id_received'    => $id === '' ? '(empty)' : $id,
            'id_length'      => strlen($id),
            'db_file_exists' => is_file(DB),
            'known_ids'      => array_keys($data),
        ));
    }
    if (!isset($data[$id])) {
        fail('Not found or expired.', 404, array(
            'reason'         => 'id format is valid but no comparison with this id exists on the server',
            'id_received'    => $id,
            'db_file_exists' => is_file(DB),
            'known_ids'      => array_keys($data),
        ));
    }

    if (isset($_GET['img'])) {
        $which = ($_GET['img'] === 'after') ? 'after' : 'before';
        $file = DIR . '/' . $data[$id][$which];
        if (!is_file($file)) {
            fail('Not found.', 404, array(
                'reason'   => 'comparison exists but the image file is missing on disk',
                'expected' => $file,
            ));
        }

        header('Content-Type: ' . mime_content_type($file));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }

    $base = rtrim(str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : 'upload.php'), '/');
    json_out(array(
        'ok'        => true,
        'before'    => $base . '?id=' . $id . '&img=before',
        'after'     => $base . '?id=' . $id . '&img=after',
        'expiresAt' => $data[$id]['expiresAt'],
    ));
}

/* ---------- POST: upload a new comparison ---------- */
if ($method !== 'POST') fail('Method not allowed.', 405);

// post_max_size exceeded -> $_FILES is empty but body was sent
if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    fail('Upload too large. Maximum is 10 MB per image.', 413, array(
        'php_upload_max_filesize' => ini_get('upload_max_filesize'),
        'php_post_max_size'       => ini_get('post_max_size'),
        'content_length'          => (int)$_SERVER['CONTENT_LENGTH'],
    ));
}
if (!isset($_FILES['before'], $_FILES['after'])) {
    fail('Both images are required.', 422, array(
        'files_received' => array_keys($_FILES),
        'php_upload_max_filesize' => ini_get('upload_max_filesize'),
        'php_post_max_size'       => ini_get('post_max_size'),
    ));
}

$uploaded = array();
foreach (array('before', 'after') as $side) {
    $f = $_FILES[$side];
    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        dbg("UPLOAD ERROR {$side}: code=" . (isset($f['error']) ? $f['error'] : '?') . ' size=' . (isset($f['size']) ? $f['size'] : '?'));
        if (isset($f['error']) && ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)) {
            fail('Upload too large. Maximum is 10 MB per image.', 413, array(
                'php_upload_max_filesize' => ini_get('upload_max_filesize'),
                'php_post_max_size'       => ini_get('post_max_size'),
            ));
        }
        fail($side . ' image could not be uploaded.', 422, array('error_code' => isset($f['error']) ? $f['error'] : 'unknown'));
    }
    if ($f['size'] > MAX_SIZE) fail('Image too large. Maximum is 10 MB per image.');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    dbg("FILE {$side}: client_type={$f['type']} sniffed_mime={$mime} size={$f['size']}");
    if (!isset($MIME_EXT[$mime])) fail('Only PNG, JPG, GIF, WebP, AVIF or BMP images are allowed.', 415, array(
        'sniffed_mime' => $mime,
    ));

    $uploaded[$side] = array(
        'tmp' => $f['tmp_name'],
        'ext' => $MIME_EXT[$mime],
    );
}

$id = bin2hex(random_bytes(16));
$names = array(
    'before' => $id . '_before.' . $uploaded['before']['ext'],
    'after'  => $id . '_after.' . $uploaded['after']['ext'],
);

foreach (array('before', 'after') as $side) {
    if (!move_uploaded_file($uploaded[$side]['tmp'], DIR . '/' . $names[$side])) {
        dbg("MOVE FAILED {$side} -> " . DIR . '/' . $names[$side]);
        foreach ($names as $n) @unlink(DIR . '/' . $n);
        fail('Could not store the images.', 500, array(
            'dir'          => DIR,
            'dir_writable' => is_writable(DIR),
        ));
    }
}

$data[$id] = array(
    'before'    => $names['before'],
    'after'     => $names['after'],
    'createdAt' => time(),
    'expiresAt' => time() + TTL,
);
if (!save_db($data)) {
    @unlink(DIR . '/' . $names['before']);
    @unlink(DIR . '/' . $names['after']);
    unset($data[$id]);
    fail('Could not save comparison data.', 500, array(
        'db'          => DB,
        'db_writable' => is_writable(DB) || is_writable(dirname(DB)),
    ));
}

dbg('CREATED comparison ' . $id);

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$dirPath = dirname(str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/upload.php'));
if ($dirPath === '/' || $dirPath === '.') $dirPath = '';

json_out(array(
    'ok'   => true,
    'link' => $scheme . '://' . $host . $dirPath . '/index.html?id=' . $id,
));
