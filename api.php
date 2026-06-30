<?php
/**
 * Бэкенд хранения доски бригад — портал «Инструменты Hide-X».
 *
 *  GET  api.php  → отдаёт всю доску как JSON. Если data.json нет — {"brigades":[],"objects":[]}.
 *  POST api.php  → тело = JSON всей доски ({brigades:[...], objects:[...]}).
 *                  Сохраняет в data.json под блокировкой flock; возвращает {"ok":true}.
 *
 * Чистый PHP 8.x, без Composer и внешних зависимостей. Один домен — CORS не нужен.
 */

declare(strict_types=1);

// Наружу — только корректный JSON, ошибки PHP не показываем.
ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const DATA_FILE   = __DIR__ . '/data.json';
const DEFAULT_DOC = '{"brigades":[],"objects":[]}';
const MAX_BODY    = 1048576; // ≈1 МБ

/** Отдать ответ и завершить выполнение. */
function respond(int $code, string $json): never
{
    http_response_code($code);
    echo $json;
    exit;
}

/** Ответ-ошибка в JSON. */
function fail(int $code, string $message): never
{
    respond($code, json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ------------------------------------------------------------------ GET */
if ($method === 'GET') {
    if (!is_file(DATA_FILE)) {
        respond(200, DEFAULT_DOC);
    }
    $fp = @fopen(DATA_FILE, 'rb');
    if ($fp === false) {
        fail(500, 'cannot open data');
    }
    $data = '';
    if (flock($fp, LOCK_SH)) {
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } else {
        fclose($fp);
        fail(500, 'cannot lock data');
    }
    fclose($fp);

    if (!is_string($data) || trim($data) === '') {
        respond(200, DEFAULT_DOC);
    }
    respond(200, $data);
}

/* ----------------------------------------------------------------- POST */
if ($method === 'POST') {
    // Ограничение размера тела (Content-Length и фактический объём).
    $declaredLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLen > MAX_BODY) {
        fail(413, 'payload too large');
    }

    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if ($raw === false) {
        fail(400, 'no body');
    }
    if (strlen($raw) > MAX_BODY) {
        fail(413, 'payload too large');
    }

    $doc = json_decode($raw, true);
    if (!is_array($doc)
        || !isset($doc['brigades'], $doc['objects'])
        || !is_array($doc['brigades'])
        || !is_array($doc['objects'])
    ) {
        fail(400, 'invalid data: expected {brigades:[], objects:[]}');
    }

    // Нормализуем — храним только нужные поля верхнего уровня.
    $clean = json_encode(
        ['brigades' => $doc['brigades'], 'objects' => $doc['objects']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($clean === false) {
        fail(400, 'cannot encode data');
    }

    // Запись строго под эксклюзивной блокировкой.
    $fp = @fopen(DATA_FILE, 'cb+');
    if ($fp === false) {
        fail(500, 'cannot open data for write');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        fail(500, 'cannot lock data for write');
    }

    $ok = ftruncate($fp, 0) && rewind($fp) && (fwrite($fp, $clean) !== false);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!$ok) {
        fail(500, 'write failed');
    }
    respond(200, json_encode(['ok' => true]));
}

/* --------------------------------------------------------------- прочее */
header('Allow: GET, POST');
fail(405, 'method not allowed');
