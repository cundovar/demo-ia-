<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

const MAX_TEXT_CHARS     = 20000;
const MIN_PDF_TEXT_CHARS = 200;
const PDF_VISION_BATCH_SIZE = 3;
const PDF_VISION_MAX_PAGES  = 15;
const PDF_IMAGE_DPI         = 80;
const PDF_JPEG_QUALITY      = 70;
const TEMP_DIR           = '/tmp/demo_ia_sessions/';
const ANALYSIS_LOCK_FILE  = '/tmp/demo_ia_sessions.analysis.lock';
const DEFAULT_TEXT_MODEL  = 'gemma3:4b';
const DEFAULT_VISION_MODEL = 'qwen2.5vl:7b';
const FALLBACK_TEXT_CHARS = 6000;

// ─── Routing ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'extract';
    try {
        match ($action) {
            'extract'     => handleExtract(),
            'split'       => handleSplit(),
            'analyze'     => handleAnalyze(),
            'analyze-one' => handleAnalyzeOne(),
            'analysis-status' => handleAnalysisStatus(),
            'session-reserve' => handleSessionReserve(),
            'session-release' => handleSessionRelease(),
            'cleanup'     => handleCleanup(),
            'cancel'      => handleCancel(),
            default       => throw new RuntimeException("Action inconnue : {$action}"),
        };
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
    }
    exit;
}

renderPage();

// ─── Étape 1 : extraction ───────────────────────────────────────────────────

function handleExtract(): void
{
    if (!isset($_FILES['document']) || !is_array($_FILES['document'])) {
        throw new RuntimeException('Aucun fichier recu.');
    }

    $file = $_FILES['document'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(uploadErrorMessage((int) $file['error']));
    }

    $path      = (string) $file['tmp_name'];
    $name      = (string) $file['name'];
    $mime      = mime_content_type($path) ?: 'application/octet-stream';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $token     = bin2hex(random_bytes(16));

    if (isImageMime($mime) || in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Impossible de lire l image.');
        }
        $payload = ['type' => 'vision', 'images' => [base64_encode($content)]];
    } elseif ($extension === 'pdf') {
        $payload = extractPdfPayload($path);
    } else {
        $text    = extractText($path, $extension);
        $payload = ['type' => 'text', 'content' => $text];
    }

    saveSessionWithToken($payload, $token);
    jsonResponse(['ok' => true, 'token' => $token, 'type' => $payload['type']]);
}

function extractPdfPayload(string $path): array
{
    $text = extractPdfTextSafe($path);
    if (mb_strlen($text) >= MIN_PDF_TEXT_CHARS) {
        return ['type' => 'text', 'rawText' => $text, 'content' => preparePdfTextForAnalysis($text)];
    }

    $pages = [1];
    $images = pdfPagesToCompressedImages($path, $pages);

    return [
        'type' => 'vision',
        'images' => $images,
        'pages' => $pages,
    ];
}

// ─── Annulation ─────────────────────────────────────────────────────────────

function handleCancel(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Token manquant.');
    }

    $pidFile = TEMP_DIR . $token . '.pid';
    if (file_exists($pidFile)) {
        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGTERM);
        }
        @unlink($pidFile);
    }
    releaseAnalysisSlot($token);
    @unlink(TEMP_DIR . $token . '.session');
    jsonResponse(['ok' => true]);
}

// ─── Split par formation ────────────────────────────────────────────────────

function splitIntoFormations(string $rawText): array
{
    $pages = preg_split('/\f+/', $rawText) ?: [];
    $sections = [];
    $count = count($pages);
    $i = 0;

    while ($i < $count) {
        $page = trim($pages[$i]);
        if ($page !== '' && isTrainingSheetPage($page) && trainingPageScore($page) >= 4) {
            $block = "[Page PDF " . ($i + 1) . "]\n" . $page;
            $next = $i + 1;
            if ($next < $count) {
                $nextPage = trim($pages[$next]);
                if (trainingDetailsPageScore($nextPage) >= 2 && !isTrainingSheetPage($nextPage)) {
                    $block .= "\n\n---\n\n[Page PDF " . ($next + 1) . "]\n" . $nextPage;
                    $i++;
                }
            }
            $sections[] = $block;
        }
        $i++;
    }

    return $sections;
}

function handleSplit(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token === '') throw new RuntimeException('Token manquant.');

    $payload = loadSession($token);

    if ($payload['type'] !== 'text' || !isset($payload['rawText']) || $payload['rawText'] === '') {
        jsonResponse(['ok' => true, 'count' => 1, 'previews' => []]);
        return;
    }

    $sections = splitIntoFormations($payload['rawText']);

    if (count($sections) <= 1) {
        jsonResponse(['ok' => true, 'count' => 1, 'previews' => []]);
        return;
    }

    $payload['sections'] = $sections;
    file_put_contents(TEMP_DIR . $token . '.json', json_encode($payload));

    $previews = array_map(
        fn(string $s): string => mb_substr(preg_replace('/\s+/', ' ', strip_tags($s)) ?? $s, 0, 80),
        $sections
    );

    jsonResponse(['ok' => true, 'count' => count($sections), 'previews' => $previews]);
}

function handleAnalyzeOne(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    $index = (int) ($_POST['index'] ?? -1);
    if ($token === '' || $index < 0) throw new RuntimeException('Paramètres manquants.');

    $payload = loadSession($token);

    if (!isset($payload['sections'][$index])) {
        throw new RuntimeException("Section {$index} introuvable.");
    }

    reserveAnalysisSlot($token);
    try {
        $sectionText = (string) $payload['sections'][$index];
        $result      = analyzeTextWithOllama($sectionText, $token);
        $normalized  = normalizeResult($result);

        jsonResponse(['ok' => true, 'data' => $normalized, 'raw' => $result, 'index' => $index]);
    } finally {
        releaseAnalysisSlot($token);
    }
}

function handleCleanup(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token !== '') {
        deleteSession($token);
        releaseAnalysisSlot($token);
        @unlink(TEMP_DIR . $token . '.session');
    }
    jsonResponse(['ok' => true]);
}

function handleAnalysisStatus(): void
{
    $currentToken = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    $lock = readAnalysisLockPayload();
    $sessionBusy = hasActiveSession($currentToken !== '' ? $currentToken : null);
    $busy = $lock !== null || $sessionBusy;
    jsonResponse([
        'ok'    => true,
        'busy'  => $busy,
        'owner' => is_array($lock) ? (string) ($lock['token'] ?? '') : '',
        'self'  => $currentToken !== '' && is_array($lock) && (($lock['token'] ?? null) === $currentToken),
    ]);
}

function handleSessionReserve(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token === '') throw new RuntimeException('Token manquant.');
    if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0700, true);
    file_put_contents(TEMP_DIR . $token . '.session', (string) time());
    jsonResponse(['ok' => true]);
}

function handleSessionRelease(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token !== '') @unlink(TEMP_DIR . $token . '.session');
    jsonResponse(['ok' => true]);
}

function hasActiveSession(?string $excludeToken = null): bool
{
    if (!is_dir(TEMP_DIR)) return false;
    $files = glob(TEMP_DIR . '*.session') ?: [];
    foreach ($files as $file) {
        $token = basename($file, '.session');
        if ($excludeToken !== null && $token === $excludeToken) continue;
        $ts = (int) file_get_contents($file);
        if ($ts > time() - 600) return true;
        @unlink($file);
    }
    return false;
}

// ─── Étape 2 : analyse IA ───────────────────────────────────────────────────

function handleAnalyze(): void
{
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Token manquant.');
    }

    $payload = loadSession($token);

    reserveAnalysisSlot($token);
    try {
        $result = $payload['type'] === 'vision'
            ? analyzeVisionPayload($payload, $token)
            : analyzeTextWithOllama($payload['content'], $token);

        $normalized = normalizeResult($result);
        deleteSession($token);
        jsonResponse(['ok' => true, 'data' => $normalized, 'raw' => $result]);
    } finally {
        releaseAnalysisSlot($token);
    }
}

function isAnalysisBusy(): bool
{
    $payload = readAnalysisLockPayload();
    return $payload !== null;
}

function isAnalysisOwnedByToken(string $token): bool
{
    $payload = readAnalysisLockPayload();
    return is_array($payload) && (($payload['token'] ?? null) === $token);
}

function reserveAnalysisSlot(string $token): void
{
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0700, true);
    }

    $deadline = time() + 300;
    while (true) {
        $handle = @fopen(ANALYSIS_LOCK_FILE, 'x');
        if ($handle !== false) {
            fwrite($handle, json_encode(['token' => $token, 'ts' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fclose($handle);
            return;
        }

        $payload = readAnalysisLockPayload();
        if ($payload === null) {
            @unlink(ANALYSIS_LOCK_FILE);
            continue;
        }

        $stale = !isset($payload['ts']) || !is_int($payload['ts']) || $payload['ts'] < time() - 7200;
        if ($stale) {
            @unlink(ANALYSIS_LOCK_FILE);
            continue;
        }

        if (time() >= $deadline) {
            throw new RuntimeException('Analyse déjà en cours. Réessayez dans quelques instants.');
        }

        usleep(250000);
    }
}

function releaseAnalysisSlot(string $token): void
{
    $payload = readAnalysisLockPayload();
    if (!is_array($payload) || (($payload['token'] ?? null) !== $token)) {
        return;
    }

    @unlink(ANALYSIS_LOCK_FILE);
}

function readAnalysisLockPayload(): ?array
{
    if (!is_file(ANALYSIS_LOCK_FILE)) {
        return null;
    }

    $content = trim((string) file_get_contents(ANALYSIS_LOCK_FILE));
    if ($content === '') {
        return null;
    }

    $payload = json_decode($content, true);
    return is_array($payload) ? $payload : null;
}

function saveSessionWithToken(array $payload, string $token): void
{
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0700, true);
    }
    file_put_contents(TEMP_DIR . $token . '.json', json_encode($payload));
}

// ─── Extraction de texte ────────────────────────────────────────────────────

function extractText(string $path, string $extension): string
{
    return match ($extension) {
        'pdf'        => extractPdfTextSafe($path),
        'docx'       => extractDocxText($path),
        'xlsx'       => extractXlsxText($path),
        'txt', 'csv' => readPlainText($path),
        default      => throw new RuntimeException('Format non supporte. Formats acceptes : PDF, DOCX, XLSX, TXT, CSV, JPG, PNG, WEBP.'),
    };
}

function extractPdfTextSafe(string $path): string
{
    $text = extractPdfTextWithPdftotext($path);
    if ($text !== '') {
        return $text;
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($path);
        $text   = $pdf->getText();
        return trim($text);
    } catch (Throwable) {
        return '';
    }
}

function extractPdfTextWithPdftotext(string $path): string
{
    if (!is_executable('/usr/bin/pdftotext')) {
        return '';
    }

    $command = sprintf(
        'pdftotext -layout %s - 2>/dev/null',
        escapeshellarg($path)
    );
    $text = shell_exec($command);
    return is_string($text) ? trim($text) : '';
}

function preparePdfTextForAnalysis(string $text): string
{
    $catalogText = extractTrainingCatalogPages($text);
    return limitText($catalogText !== '' ? $catalogText : $text);
}

function extractTrainingCatalogPages(string $text): string
{
    $pages = preg_split('/\f+/', $text) ?: [];
    $candidates = [];

    foreach ($pages as $index => $page) {
        $page = trim($page);
        if ($page === '') continue;
        if (!isTrainingSheetPage($page)) continue;

        $score = trainingPageScore($page);
        if ($score < 4) continue;

        $candidates[] = [
            'index' => $index,
            'score' => $score,
            'page' => $page,
        ];
    }

    $bestCandidates = array_filter(
        $candidates,
        fn(array $candidate): bool => $candidate['score'] >= 6
    );
    if ($bestCandidates === []) {
        $bestCandidates = $candidates;
    }

    $selectedIndexes = [];
    foreach (array_slice($bestCandidates, 0, 8) as $candidate) {
        $selectedIndexes[$candidate['index']] = true;
        $next = $candidate['index'] + 1;
        if (isset($pages[$next]) && trainingDetailsPageScore((string) $pages[$next]) >= 2) {
            $selectedIndexes[$next] = true;
        }
    }

    ksort($selectedIndexes);

    $selected = [];
    foreach (array_keys($selectedIndexes) as $index) {
        $selected[] = "[Page PDF " . ($index + 1) . "]\n" . trim((string) $pages[$index]);
        if (count($selected) >= 14) break;
    }

    return trim(implode("\n\n---\n\n", $selected));
}

function detectTrainingPageNumbers(string $text): array
{
    $pages = preg_split('/\f+/', $text) ?: [];
    $selected = [];

    foreach ($pages as $index => $page) {
        $page = trim((string) $page);
        if ($page === '') continue;
        if (!isTrainingSheetPage($page)) continue;
        if (trainingPageScore($page) < 4) continue;

        $selected[] = $index + 1;
    }

    return array_values(array_unique($selected));
}

function isTrainingSheetPage(string $page): bool
{
    return preg_match('/\bProgramme de votre formation\b/ui', $page) === 1
        || (
            preg_match('/\bObjectif(?:s|\(s\))?\b/ui', $page) === 1
            && preg_match('/\bProgramme\b/ui', $page) === 1
            && preg_match('/\bDur[ée]e?\b/ui', $page) === 1
        )
        || (
            preg_match('/\bObjectif(?:s|\(s\))?\b/ui', $page) === 1
            && preg_match('/Pr[ée]-?requis/ui', $page) === 1
            && preg_match('/\b(Validation|Tarif)\b/ui', $page) === 1
        )
        || (
            preg_match('/\bObjectif(?:s|\(s\))?\b/ui', $page) === 1
            && preg_match('/\bProchaines?\s+(?:sessions|promotions)\b/ui', $page) === 1
        );
}

function trainingPageScore(string $page): int
{
    $score = 0;
    $patterns = [
        '/\bobjectif(?:s|\(s\))?\b/ui' => 2,
        '/\bprogramme\b/ui' => 2,
        '/\bpublic\b/ui' => 1,
        '/pr[ée]requis/ui' => 1,
        '/\b(prochaines?|dates?|sessions?)\b/ui' => 2,
        '/\b(dur[ée]e?|jours?|heures?)\b/ui' => 1,
        '/\b(prix|tarifs?|co[uû]t|€|euros?)\b/ui' => 2,
        '/\b(pr[ée]sentiel|distanciel|classe virtuelle|e-learning|hybride|blended)\b/ui' => 1,
        '/\b(certificat|certifiante?|certification|dipl[oô]mant)\b/ui' => 1,
        '/\b(formacode|r[ée]f[ée]rence|r[ée]f\.?|code formation)\b/ui' => 1,
        '/\b(inscription|modalit[ée]s pratiques|lieu)\b/ui' => 1,
    ];

    foreach ($patterns as $pattern => $weight) {
        if (preg_match($pattern, $page)) $score += $weight;
    }

    if (preg_match_all('/\b\d{1,2}\s*(?:jours?|heures?)\b/i', $page) >= 1) $score += 1;
    if (preg_match_all('/\b\d{1,2}\s+(?:janvier|f[ée]vrier|mars|avril|mai|juin|juillet|ao[uû]t|septembre|octobre|novembre|d[ée]cembre)\s+\d{4}\b/i', $page) >= 1) $score += 2;

    return $score;
}

function trainingDetailsPageScore(string $page): int
{
    $score = 0;
    foreach ([
        '/\bprogramme\b/ui',
        '/\b(prochaines?|dates?|sessions?)\b/ui',
        '/\b(prix|tarifs?|co[uû]t|€|euros?)\b/ui',
        '/\bmodalit[ée]s pratiques\b/ui',
        '/\binscriptions?\b/ui',
    ] as $pattern) {
        if (preg_match($pattern, $page)) $score++;
    }
    return $score;
}

function extractDocxText(string $path): string
{
    $xml  = readZipEntry($path, 'word/document.xml');
    $xml  = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
    $text = strip_tags($xml);
    return limitText(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function extractXlsxText(string $path): string
{
    $strings = [];
    $shared  = readZipEntry($path, 'xl/sharedStrings.xml', false);

    if ($shared !== null) {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $shared, $matches);
        foreach ($matches[1] as $item) {
            $strings[] = html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Impossible de lire le fichier XLSX.');
    }

    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!is_string($entry) || !preg_match('#^xl/worksheets/sheet\d+\.xml$#', $entry)) {
            continue;
        }
        $xml = $zip->getFromName($entry);
        if (!is_string($xml)) continue;

        preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $xml, $cells, PREG_SET_ORDER);
        foreach ($cells as $cell) {
            preg_match('/<v>(.*?)<\/v>/s', $cell[2], $vm);
            if (!isset($vm[1])) continue;
            $value    = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $parts[]  = (str_contains($cell[1], ' t="s"') && isset($strings[(int) $value]))
                ? $strings[(int) $value]
                : $value;
        }
    }
    $zip->close();

    $text = trim(implode("\n", $parts));
    if ($text === '') throw new RuntimeException('Aucun texte exploitable trouve dans le XLSX.');
    return limitText($text);
}

function readPlainText(string $path): string
{
    $text = file_get_contents($path);
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('Fichier texte vide.');
    }
    return limitText($text);
}

function readZipEntry(string $path, string $entry, bool $required = true): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Impossible de lire le fichier compresse.');
    }
    $content = $zip->getFromName($entry);
    $zip->close();

    if (!is_string($content)) {
        if ($required) throw new RuntimeException("Entree introuvable : {$entry}.");
        return null;
    }
    return $content;
}

function pdfPagesToCompressedImages(string $path, array $pages): array
{
    $images = [];
    foreach ($pages as $page) {
        $page = max(1, (int) $page);
        $prefix = sys_get_temp_dir() . '/demo_ia_' . uniqid('', true);
        $file = $prefix . '.jpg';
        $command = sprintf(
            'pdftoppm -jpeg -jpegopt quality=%d -r %d -f %d -l %d -singlefile %s %s 2>/dev/null',
            PDF_JPEG_QUALITY,
            PDF_IMAGE_DPI,
            $page,
            $page,
            escapeshellarg($path),
            escapeshellarg($prefix)
        );

        shell_exec($command);
        if (!is_file($file)) {
            continue;
        }

        $content = file_get_contents($file);
        if (is_string($content) && $content !== '') {
            $images[] = base64_encode($content);
        }
        unlink($file);
    }

    if ($images === []) {
        throw new RuntimeException('PDF visuel non lisible et pdftoppm indisponible. Installez poppler-utils ou exportez le PDF en image.');
    }

    return $images;
}

// ─── Appels Ollama ──────────────────────────────────────────────────────────

function analyzeTextWithOllama(string $text, string $token): array
{
    try {
        return callOllama([
            'model'  => textModel(),
            'prompt' => buildExtractionPrompt($text),
            'stream' => false,
            'format' => 'json',
            'options' => ollamaOptions(),
        ], $token);
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'JSON complet')) {
            throw $e;
        }

        return callOllama([
            'model'  => textModel(),
            'prompt' => buildCompactExtractionPrompt(limitTextForRetry($text)),
            'stream' => false,
            'format' => 'json',
            'options' => ollamaOptions(),
        ], $token);
    }
}

function analyzeVisionPayload(array $payload, string $token): array
{
    $images = $payload['images'] ?? [];
    if (!is_array($images) || $images === []) {
        throw new RuntimeException('Aucune image exploitable pour l analyse vision.');
    }

    $results = [];
    foreach (array_chunk($images, PDF_VISION_BATCH_SIZE) as $batch) {
        $results[] = analyzeImagesWithOllama($batch, $token);
    }

    return mergeFormationResults($results);
}

function analyzeImagesWithOllama(array $images, string $token): array
{
    return callOllama([
        'model'  => visionModel(),
        'prompt' => buildImagePrompt(),
        'images' => $images,
        'stream' => false,
        'format' => 'json',
        'options' => ollamaOptions(),
    ], $token);
}

function mergeFormationResults(array $results): array
{
    $formations = [];
    foreach ($results as $result) {
        $items = $result['formations'] ?? null;
        if (!is_array($items)) {
            $items = [$result];
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                $formations[] = $item;
            }
        }
    }

    return ['formations' => $formations];
}

function callOllama(array $payload, string $token): array
{
    $baseUrl = rtrim((string) (getenv('OLLAMA_URL') ?: 'http://localhost:11434'), '/');
    $ch      = curl_init($baseUrl . '/api/generate');
    if ($ch === false) throw new RuntimeException('Impossible d initialiser curl.');

    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => ollamaTimeout(),
    ]);

    $pidFile = TEMP_DIR . $token . '.pid';
    if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0700, true);
    file_put_contents($pidFile, (string) getmypid());

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    @unlink($pidFile);

    if ($response === false) throw new RuntimeException('Ollama injoignable : ' . $error);
    if ($status >= 400) {
        $details = trim(mb_substr($response, 0, 500));
        throw new RuntimeException("Ollama erreur HTTP {$status}. Details : {$details}");
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['response']) || !is_string($data['response'])) {
        throw new RuntimeException('Reponse Ollama invalide.');
    }

    $raw     = trim($data['response']);
    $json    = extractJsonObject(stripMarkdownFences($raw));
    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        $preview = mb_substr($raw, 0, 300);
        $reason = isset($data['done_reason']) && is_string($data['done_reason'])
            ? " Raison Ollama : {$data['done_reason']}."
            : '';
        throw new RuntimeException("L IA n a pas retourne un JSON complet. Reponse : {$preview}{$reason}");
    }

    return $decoded;
}

function textModel(): string
{
    $model = trim((string) getenv('OLLAMA_TEXT_MODEL'));
    return $model !== '' ? $model : DEFAULT_TEXT_MODEL;
}

function visionModel(): string
{
    $model = trim((string) getenv('OLLAMA_VISION_MODEL'));
    return $model !== '' ? $model : DEFAULT_VISION_MODEL;
}

function ollamaOptions(): array
{
    return [
        'temperature' => 0,
        'num_predict' => 4096,
        'num_ctx'     => 8192,
        'num_thread'  => 8,
    ];
}

function ollamaTimeout(): int
{
    $timeout = (int) getenv('OLLAMA_TIMEOUT');
    return $timeout > 0 ? $timeout : 1800;
}

// ─── Prompts ────────────────────────────────────────────────────────────────

function buildExtractionPrompt(string $text): string
{
    return <<<PROMPT
Tu es un assistant RH. Extrais les informations de formation du document.
Retourne uniquement un objet JSON valide, sans markdown et sans commentaire.

Règles strictes :
- Utilise null quand l'information est absente ou incertaine.
- Convertis les dates au format YYYY-MM-DD.

Règles par champ :
- title : titre précis de la formation, jamais le titre du catalogue ou de l'organisme.
- organizerName : nom exact de l'organisme de formation tel qu'il apparaît dans le document (en-tête, pied de page, signature). Ne jamais inventer ou substituer un autre organisme. null si vraiment absent.
- modality : REMOTE si le document mentionne "à distance", "distanciel", "e-learning", "classe virtuelle", "100% distance", "FOAD". IN_PERSON si le document mentionne "présentiel", "présentielle", "en salle", "en centre", "formation présentielle", ou si aucune modalité n'est mentionnée (valeur par défaut). BLENDED si les deux modalités sont présentes, ou si le document mentionne "alternance", "hybride", "blended", "MOOC" combiné à des sessions en centre. Cherche dans le titre, l'en-tête et le corps du document. Ne jamais retourner null pour ce champ.
- isInternal : false si la formation est proposée par un organisme externe identifiable (AFPA, GRETA, Cegos, centre de formation, etc.) ou si organizerName est renseigné. true uniquement si c'est clairement une formation interne à l'entreprise du commanditaire. null si vraiment impossible à déterminer.
- isCertifying : true si le document mentionne CCP, certificat, diplôme, titre professionnel, certification, RNCP, VAE, habilitation. false si explicitement non certifiante. null si non mentionné.
- cost : montant numérique seul, sans devise. null si absent ou "nous consulter".
- expectedParticipants : nombre de personnes/stagiaires attendus explicitement (ex: "20 stagiaires", "15 participants max"). Si le nombre est suivi de "heures", "h", "jours", "crédits" ou désigne une durée, c'est null. null si absent ou incertain.
- context : résume en 1-2 phrases les objectifs et le programme de la formation. null si aucune information disponible.
- startDate : date de début au format YYYY-MM-DD. Cherche "du XX au XX", "dates extrêmes de la formation", "début", "à partir du". null si absente.
- endDate : date de fin au format YYYY-MM-DD. Cherche "au XX", "jusqu'au", "fin de formation". null si absente.
Si le document contient plusieurs formations distinctes, extrais-les toutes dans le tableau formations.
Si le document est un catalogue, n'extrais pas le texte institutionnel général : extrais uniquement les fiches formations.

JSON attendu :
{
  "formations": [
    {
      "title": null,
      "modality": null,
      "isInternal": null,
      "organizerName": null,
      "cost": null,
      "expectedParticipants": null,
      "isCertifying": null,
      "context": null,
      "startDate": null,
      "endDate": null
    }
  ]
}

Document :
---
{$text}
---
PROMPT;
}

function buildImagePrompt(): string
{
    return buildExtractionPrompt('Lis directement le document fourni en image.');
}

function buildCompactExtractionPrompt(string $text): string
{
    return <<<PROMPT
Retourne uniquement ce JSON valide, rempli avec le document. Aucun texte hors JSON.
Si une information manque, mets null.

{
  "formations": [
    {
      "title": null,
      "modality": null,
      "isInternal": null,
      "organizerName": null,
      "cost": null,
      "expectedParticipants": null,
      "isCertifying": null,
      "context": null,
      "startDate": null,
      "endDate": null
    }
  ]
}

Règles :
- modality: REMOTE si "à distance/distanciel/e-learning/FOAD/classe virtuelle". BLENDED si "alternance/hybride/MOOC+centre". IN_PERSON par défaut si rien n'indique le contraire. Ne jamais retourner null.
- isInternal: false si organisme externe nommé (AFPA, GRETA, centre de formation…) ou si organizerName est renseigné, true si formation interne, sinon null
- isCertifying: true si CCP/certificat/diplôme/titre professionnel/RNCP mentionné, sinon null
- organizerName: nom de l'organisme de formation, cherche dans tout le document
- startDate/endDate: YYYY-MM-DD. Cherche "du XX au XX", "dates extrêmes". null si absent.

Document :
{$text}
PROMPT;
}

// ─── Normalisation ──────────────────────────────────────────────────────────

function normalizeResult(array $result): array
{
    $formations = $result['formations'] ?? null;
    if (!is_array($formations)) $formations = [$result];
    return ['formations' => array_values(array_map('normalizeSingleFormation', $formations))];
}

function normalizeSingleFormation(array $f): array
{
    $n = [
        'title'                => firstValue($f, ['title', 'name', 'courseTitle', 'formationTitle', 'trainingTitle']),
        'modality'             => normalizeModality(firstValue($f, ['modality', 'format', 'deliveryMode', 'mode'])),
        'isInternal'           => normalizeBool(firstValue($f, ['isInternal', 'internal', 'formationInterne'])),
        'organizerName'        => firstValue($f, ['organizerName', 'organizer', 'provider', 'trainingProvider', 'organism', 'organisme']),
        'cost'                 => normalizeNumber(firstValue($f, ['cost', 'price', 'amount', 'fee', 'prix', 'cout'])),
        'expectedParticipants' => normalizeParticipants(firstValue($f, ['expectedParticipants', 'participants', 'participantCount', 'attendees', 'nombreParticipants'])),
        'isCertifying'         => normalizeBool(firstValue($f, ['isCertifying', 'certifying', 'certification', 'certifiante'])),
        'context'              => firstValue($f, ['context', 'description', 'objectives', 'summary', 'programme']),
        'startDate'            => normalizeDate(firstValue($f, ['startDate', 'start_date', 'dateDebut', 'dateStart', 'from'])),
        'endDate'              => normalizeDate(firstValue($f, ['endDate', 'end_date', 'dateFin', 'dateEnd', 'to'])),
    ];

    return $n;
}

function firstValue(array $data, array $keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== '') {
            return $data[$key];
        }
    }
    return null;
}

function normalizeBool(mixed $value): ?bool
{
    if (is_bool($value)) return $value;
    if ($value === null || $value === '') return null;
    $value = mb_strtolower(trim((string) $value));
    return match ($value) {
        '1', 'true', 'yes', 'oui', 'certifiante', 'certifiant' => true,
        '0', 'false', 'no', 'non', 'non certifiante', 'non certifiant' => false,
        default => null,
    };
}

function normalizeParticipants(mixed $value): int|null
{
    $n = normalizeNumber($value);
    if ($n === null) return null;
    $n = (int) $n;
    if ($n <= 0 || $n > 200) return null;
    return $n;
}

function normalizeNumber(mixed $value): int|float|null
{
    if (is_int($value) || is_float($value)) return $value;
    if ($value === null || $value === '') return null;
    $value = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', (string) $value) ?? '');
    if ($value === '' || !is_numeric($value)) return null;
    $number = (float) $value;
    return floor($number) === $number ? (int) $number : $number;
}

function normalizeModality(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    $value = mb_strtolower(trim((string) $value));
    return match (true) {
        str_contains($value, 'remote'), str_contains($value, 'distanc'), str_contains($value, 'online'), str_contains($value, 'visio') => 'REMOTE',
        str_contains($value, 'blend'), str_contains($value, 'hybr'), str_contains($value, 'mix') => 'BLENDED',
        str_contains($value, 'person'), str_contains($value, 'présentiel'), str_contains($value, 'presentiel'), str_contains($value, 'salle') => 'IN_PERSON',
        in_array(strtoupper($value), ['IN_PERSON', 'REMOTE', 'BLENDED'], true) => strtoupper($value),
        default => null,
    };
}

function normalizeDate(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    $value = trim((string) $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    return null;
}

// ─── Sessions temp ──────────────────────────────────────────────────────────

function saveSession(array $payload): string
{
    if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0700, true);
    $token = bin2hex(random_bytes(16));
    file_put_contents(TEMP_DIR . $token . '.json', json_encode($payload));
    return $token;
}

function loadSession(string $token): array
{
    $file = TEMP_DIR . $token . '.json';
    if (!file_exists($file)) throw new RuntimeException('Session expirée. Veuillez réessayer.');
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) throw new RuntimeException('Session corrompue.');
    return $data;
}

function deleteSession(string $token): void
{
    $file = TEMP_DIR . $token . '.json';
    if (file_exists($file)) unlink($file);
    $pidFile = TEMP_DIR . $token . '.pid';
    if (file_exists($pidFile)) unlink($pidFile);
}

// ─── Utilitaires ────────────────────────────────────────────────────────────

function stripMarkdownFences(string $text): string
{
    return preg_replace('/```(?:json)?\s*(.*?)\s*```/s', '$1', $text) ?? $text;
}

function extractJsonObject(string $text): string
{
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return $text;
    return substr($text, $start, $end - $start + 1);
}

function limitText(string $text): string
{
    $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= MAX_TEXT_CHARS) return $text;
    return mb_substr($text, 0, MAX_TEXT_CHARS) . "\n\n[Document tronque pour la demo]";
}

function limitTextForRetry(string $text): string
{
    $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= FALLBACK_TEXT_CHARS) return $text;
    return mb_substr($text, 0, FALLBACK_TEXT_CHARS) . "\n\n[Document reduit pour relance IA]";
}

function isImageMime(string $mime): bool
{
    return str_starts_with($mime, 'image/');
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux. Relancez avec ./run-demo.sh ou augmentez les limites : UPLOAD_MAX_FILESIZE=1G POST_MAX_SIZE=1100M ./run-demo.sh',
        UPLOAD_ERR_PARTIAL  => 'Upload incomplet.',
        UPLOAD_ERR_NO_FILE  => 'Aucun fichier selectionne.',
        default             => 'Erreur upload inconnue.',
    };
}

function getServerInfo(): array
{
    $cpuModel = 'Inconnu';
    $cpuCores = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = (string) file_get_contents('/proc/cpuinfo');
        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
            $cpuModel = trim($m[1]);
        }
        $cpuCores = substr_count($cpuinfo, 'processor	');
    }

    $ramTotal = 0; $ramAvail = 0;
    if (is_readable('/proc/meminfo')) {
        $mem = (string) file_get_contents('/proc/meminfo');
        if (preg_match('/MemTotal:\s+(\d+)/i', $mem, $m))     $ramTotal = (int)$m[1] * 1024;
        if (preg_match('/MemAvailable:\s+(\d+)/i', $mem, $m)) $ramAvail = (int)$m[1] * 1024;
    }

    $gpu = 'Inconnu';
    if (is_readable('/proc/bus/pci/devices')) {
        $lspci = shell_exec('lspci 2>/dev/null | grep -i -E "vga|3d|display"') ?? '';
        $gpu = trim(preg_replace('/^[0-9a-f:.]+ /im', '', $lspci)) ?: 'Inconnu';
    }

    return [
        'cpuModel'  => $cpuModel,
        'cpuCores'  => $cpuCores,
        'ramTotal'  => $ramTotal,
        'ramAvail'  => $ramAvail,
        'diskTotal' => (int) disk_total_space('/'),
        'diskFree'  => (int) disk_free_space('/'),
        'gpu'       => $gpu,
    ];
}

function fmtBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' Go';
    if ($bytes >= 1048576)    return round($bytes / 1048576) . ' Mo';
    return round($bytes / 1024) . ' Ko';
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ─── Vue HTML ───────────────────────────────────────────────────────────────

function renderPage(): void
{
    $ollamaUrl = htmlspecialchars((string) (getenv('OLLAMA_URL') ?: 'http://localhost:11434'), ENT_QUOTES, 'UTF-8');
    $textModel = htmlspecialchars(textModel(), ENT_QUOTES, 'UTF-8');
    $visionModel = htmlspecialchars(visionModel(), ENT_QUOTES, 'UTF-8');
    $uploadLimit = htmlspecialchars(ini_get('upload_max_filesize') ?: 'inconnue', ENT_QUOTES, 'UTF-8');
    $postLimit = htmlspecialchars(ini_get('post_max_size') ?: 'inconnue', ENT_QUOTES, 'UTF-8');
    $srv = getServerInfo();
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Démo IA Qualiscope</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #17202a;
            --muted: #657080;
            --line: #d9dee7;
            --accent: #0f766e;
            --accent-dark: #115e59;
            --danger: #b42318;
            --field: #fbfcfd;
            --step-pending: #c8cdd6;
            --step-active: #0f766e;
            --step-done: #15803d;
            --step-error: #b42318;
        }

        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: var(--bg); color: var(--text); }

        header { padding: 28px 32px 18px; border-bottom: 1px solid var(--line); background: var(--panel); }
        h1 { margin: 0 0 8px; font-size: 28px; }
        .subtitle { margin: 0; color: var(--muted); max-width: 760px; line-height: 1.45; }
        .info-link {
            margin: 6px 0 0; font-size: 13px; color: var(--text);
        }
        .info-link a { color: var(--accent); font-weight: 700; text-decoration: none; }
        .info-link a:hover { text-decoration: underline; }
        .warning-queue {
            margin: 10px 0 0; padding: 10px 14px;
            background: #fff1f2; border: 1.5px solid #f87171; border-radius: 6px;
            color: #b91c1c; font-size: 13.5px; line-height: 1.5;
            max-width: 760px;
        }

        main { display: grid; grid-template-columns: minmax(280px, 420px) 1fr; gap: 24px; padding: 24px 32px 32px; }

        section { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 20px; }
        h2 { margin: 0 0 16px; font-size: 18px; }

        .dropzone {
            display: grid; place-items: center; min-height: 180px;
            border: 2px dashed #a7b0bf; border-radius: 8px; background: #fbfcfd;
            text-align: center; padding: 24px; cursor: pointer;
            transition: border-color 160ms, background 160ms;
        }
        .dropzone:hover, .dropzone.dragover { border-color: var(--accent); background: #eefdfa; }
        .dropzone strong { display: block; margin-bottom: 8px; font-size: 17px; }
        .dropzone span { color: var(--muted); line-height: 1.4; }

        input[type="file"] { position: absolute; opacity: 0; pointer-events: none; }

        button {
            width: 100%; margin-top: 16px; border: 0; border-radius: 6px;
            padding: 13px 16px; background: var(--accent); color: white;
            font-weight: 700; font-size: 15px; cursor: pointer;
        }
        button:hover { background: var(--accent-dark); }
        button:disabled { cursor: not-allowed; opacity: .6; }
        #cancelButton { background: var(--danger); display: none; }
        #cancelButton:hover { background: #991b1b; }

        /* ── Terminal ── */
        .terminal {
            display: none; margin-top: 20px;
            background: #0d1117; border: 1px solid #30363d; border-radius: 8px;
            padding: 12px 14px; font-family: 'Courier New', Courier, monospace;
            font-size: 12px; line-height: 1.7; color: #c9d1d9;
            max-height: 210px; overflow-y: auto;
        }
        .tl          { display: block; white-space: pre; }
        .tl-cmd      { color: #58a6ff; }
        .tl-ok       { color: #3fb950; }
        .tl-info     { color: #e3b341; }
        .tl-err      { color: #f85149; }
        .tl-dim      { color: #6e7681; }
        @keyframes blink { 50% { opacity: 0; } }

        .status { min-height: 18px; margin-top: 10px; color: var(--danger); font-size: 13px; }

        .meta { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; line-height: 1.45; }
        .debug { margin-top: 18px; border-top: 1px solid var(--line); padding-top: 16px; display: none; }
        .debug h3 { margin: 0 0 10px; font-size: 14px; }
        .debug pre {
            max-height: 260px; overflow: auto; margin: 0; padding: 12px;
            border: 1px solid var(--line); border-radius: 6px; background: #0f172a;
            color: #e5e7eb; font-size: 12px; line-height: 1.45; white-space: pre-wrap;
        }

        /* ── Config warning ── */
        .srv-warn {
            margin-top: 20px; padding: 14px 16px;
            border: 2px solid #f87171; border-radius: 8px; background: #fff1f2;
            font-size: 12px; color: #1f2937; line-height: 1.6;
        }
        .srv-warn h3 { margin: 0 0 10px; font-size: 13px; color: #b91c1c; }
        .srv-warn table { width: 100%; border-collapse: collapse; }
        .srv-warn td { padding: 3px 6px; vertical-align: top; }
        .srv-warn td:first-child { font-weight: 700; white-space: nowrap; color: #374151; width: 110px; }
        .srv-warn .warn  { color: #b45309; font-weight: 700; }
        .srv-warn .ok    { color: #15803d; font-weight: 700; }
        .srv-warn .bad   { color: #b91c1c; font-weight: 700; }
        .srv-warn hr { border: none; border-top: 1px solid #fca5a5; margin: 10px 0; }
        .srv-warn .suggest { background: #fef2f2; border-radius: 6px; padding: 8px 10px; margin-top: 8px; }
        .srv-warn .suggest strong { display: block; margin-bottom: 4px; color: #b91c1c; }

        /* ── Form ── */
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; font-size: 13px; font-weight: 700; color: #384252; }
        input, textarea, select {
            width: 100%; margin-top: 7px; border: 1px solid var(--line);
            border-radius: 6px; background: var(--field); padding: 11px 12px;
            font: inherit; color: var(--text);
        }
        textarea { min-height: 112px; resize: vertical; }
        .full { grid-column: 1 / -1; }

        @media (max-width: 860px) {
            header { padding: 22px 18px 16px; }
            main { grid-template-columns: 1fr; padding: 18px; }
            .grid { grid-template-columns: 1fr; }
        }

        /* ── Intro comparatif ── */
        .intro-cmp {
            margin: 0 32px 20px;
            padding: 20px 24px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            font-size: 14.5px;
            line-height: 1.7;
            color: var(--text);
        }
        .intro-cmp p { margin: 0 0 10px; }
        .intro-cmp p:last-child { margin-bottom: 0; }

        @media (max-width: 860px) { .intro-cmp { margin: 0 18px 16px; } }

        /* ── Tableaux comparatifs ── */
        .comparatif {
            margin: 0 32px 40px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 28px 28px 24px;
        }
        .comparatif h2 { margin: 0 0 16px; font-size: 18px; }
        .comparatif h2 + h2 { margin-top: 36px; }
        .comparatif .tbl-wrap { overflow-x: auto; }
        .comparatif table {
            width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 560px;
        }
        .comparatif th {
            background: var(--accent); color: #fff;
            padding: 10px 14px; text-align: left; font-weight: 700;
        }
        .comparatif td {
            padding: 9px 14px; border-bottom: 1px solid var(--line); vertical-align: top;
        }
        .comparatif tr:nth-child(even) td { background: #f9fafb; }
        .comparatif tr:last-child td { border-bottom: none; }
        .comparatif td:first-child { font-weight: 700; white-space: nowrap; color: #374151; }
        .cmp-warn { color: #b45309; }
        .cmp-good { color: #15803d; }
        .cmp-badge {
            display: inline-block; padding: 1px 8px; border-radius: 10px;
            font-size: 12px; font-weight: 700;
        }
        .badge-cloud  { background: #dbeafe; color: #1d4ed8; }
        .badge-local  { background: #dcfce7; color: #15803d; }
        .badge-both   { background: #fef9c3; color: #92400e; }

        @media (max-width: 860px) {
            .comparatif { margin: 0 18px 32px; padding: 18px 14px; }
        }
    </style>
</head>
<body>
    <header>
        <h1>Démo IA Qualiscope</h1>
        <p class="subtitle">Extraction automatique des informations d'une brochure de formation vers un formulaire RH pré-rempli.</p>
        <p class="warning-queue">⚠ Ce code est conçu pour traiter <strong>une seule analyse à la fois</strong> (afin de ne pas surcharger le serveur — c'est mon PC de travail). Si une analyse est en cours par un autre utilisateur, vous serez automatiquement <strong>mis en attente</strong>.</p>
        <p class="info-link">Quelques infos utiles &rarr; <a href="#infos">sous le formulaire</a></p>
    </header>

    <main>
        <section>
            <h2>Document</h2>
            <form id="uploadForm">
                <label class="dropzone" id="dropzone">
                    <input id="documentInput" name="document" type="file" accept=".pdf,.docx,.xlsx,.txt,.csv,.jpg,.jpeg,.png,.webp">
                    <span>
                        <strong id="fileLabel">Déposer un fichier ou cliquer</strong>
                        PDF, Word, Excel, texte ou image<br>
                        Taille max actuelle : <?= $uploadLimit ?>
                    </span>
                </label>
                <button id="submitButton" type="submit">Analyser le document</button>
                <button id="cancelButton" type="button">Arrêter l'analyse</button>
            </form>

            <div class="terminal" id="terminal"></div>

            <?php
            $diskPct  = $srv['diskTotal'] > 0 ? round(($srv['diskTotal'] - $srv['diskFree']) / $srv['diskTotal'] * 100) : 0;
            $ramPct   = $srv['ramTotal']  > 0 ? round((1 - $srv['ramAvail'] / $srv['ramTotal']) * 100) : 0;
            $hasGpu   = stripos($srv['gpu'], 'nvidia') !== false || stripos($srv['gpu'], 'amd') !== false || stripos($srv['gpu'], 'radeon') !== false;
            $diskCls  = $diskPct >= 85 ? 'bad' : ($diskPct >= 70 ? 'warn' : 'ok');
            $ramCls   = $ramPct  >= 85 ? 'bad' : ($ramPct  >= 65 ? 'warn' : 'ok');
            $gpuCls   = $hasGpu ? 'ok' : 'bad';
            ?>
            <div class="srv-warn">
                <h3>⚙ Configuration serveur actuelle</h3>
                <table>
                    <tr><td>CPU</td><td><?= htmlspecialchars($srv['cpuModel'], ENT_QUOTES, 'UTF-8') ?> &nbsp;<span class="ok"><?= $srv['cpuCores'] ?> cœurs</span></td></tr>
                    <tr><td>RAM</td><td><?= fmtBytes($srv['ramTotal']) ?> total &nbsp;·&nbsp; <span class="<?= $ramCls ?>"><?= fmtBytes($srv['ramAvail']) ?> disponible (<?= 100-$ramPct ?>%)</span></td></tr>
                    <tr><td>Disque</td><td><?= fmtBytes($srv['diskFree']) ?> libre / <?= fmtBytes($srv['diskTotal']) ?> &nbsp;<span class="<?= $diskCls ?>">(<?= $diskPct ?>% utilisé)</span></td></tr>
                    <tr><td>GPU</td><td><span class="<?= $gpuCls ?>"><?= htmlspecialchars($srv['gpu'] ?: 'Aucun GPU dédié — inférence 100% CPU', ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                </table>
                <hr>
                <strong style="color:#b91c1c;font-size:12px;">Limitations actuelles pour l'IA</strong>
                <table style="margin-top:6px">
                    <?php if (!$hasGpu): ?>
                    <tr><td>⛔ GPU</td><td class="bad">Pas de GPU dédié → Ollama tourne sur CPU → 3 à 6 min par analyse</td></tr>
                    <?php endif; ?>
                    <?php if ($diskPct >= 70): ?>
                    <tr><td>⚠ Disque</td><td class="<?= $diskCls ?>"><?= $diskPct ?>% utilisé — peu d'espace pour de nouveaux modèles</td></tr>
                    <?php endif; ?>
                    <?php if ($ramPct >= 65): ?>
                    <tr><td>⚠ RAM</td><td class="<?= $ramCls ?>"><?= $ramPct ?>% utilisée — risque de swap lors de l'inférence</td></tr>
                    <?php endif; ?>
                    <tr><td>⚠ Serveur</td><td class="warn">PC portable → pas conçu pour un usage serveur continu</td></tr>
                </table>
            </div>

            <div class="status" id="status"></div>

            <div class="meta">
                IA : <strong><?= $ollamaUrl ?></strong> &nbsp;·&nbsp;
                Texte : <strong><?= $textModel ?></strong> &nbsp;·&nbsp;
                Vision : <strong><?= $visionModel ?></strong><br>
                Upload max : <strong><?= $uploadLimit ?></strong> &nbsp;·&nbsp;
                POST max : <strong><?= $postLimit ?></strong>
            </div>
        </section>

        <section>
            <h2>Formulaire pré-rempli</h2>
            <div class="grid">
                <div class="full" id="formationRow" style="display:none">
                    <label>Formation
                        <select id="formationSelect"></select>
                    </label>
                </div>
                <label>Titre
                    <input id="title" type="text">
                </label>
                <label>Organisme
                    <input id="organizerName" type="text">
                </label>
                <label>Modalité
                    <select id="modality">
                        <option value=""></option>
                        <option value="IN_PERSON">Présentiel</option>
                        <option value="REMOTE">Distanciel</option>
                        <option value="BLENDED">Hybride</option>
                    </select>
                </label>
                <label>Formation interne
                    <select id="isInternal">
                        <option value=""></option>
                        <option value="true">Oui</option>
                        <option value="false">Non</option>
                    </select>
                </label>
                <label>Coût
                    <input id="cost" type="number" min="0" step="0.01">
                </label>
                <label>Participants attendus
                    <input id="expectedParticipants" type="number" min="0" step="1">
                </label>
                <label>Certifiante
                    <select id="isCertifying">
                        <option value=""></option>
                        <option value="true">Oui</option>
                        <option value="false">Non</option>
                    </select>
                </label>
                <label class="full">Contexte
                    <textarea id="context"></textarea>
                </label>
                <label>Date début
                    <input id="startDate" type="date">
                </label>
                <label>Date fin
                    <input id="endDate" type="date">
                </label>
            </div>
            <div class="debug" id="debugPanel">
                <h3>Données IA reçues</h3>
                <pre id="debugJson"></pre>
            </div>
        </section>
    </main>

    <div id="infos" class="intro-cmp">
        <p>
            Dans cet exemple, j'ai utilisé une petite IA (<strong>Gemma 3</strong>) et une partie de la logique
            est gérée en PHP (<strong>extraction du texte depuis le PDF</strong>). Mon PC n'étant pas assez
            puissant pour faire tourner des IA plus performantes, je n'ai pas pu intégrer un modèle avec
            <strong>Vision</strong> (lecture des images contenues dans le document).
        </p>
        <p>
            Or, certaines informations importantes d'un PDF peuvent se trouver dans des images. Je conseille
            donc d'utiliser une <strong>IA capable de lire à la fois le texte et les images</strong> pour une
            analyse complète.
        </p>
        <p>
            De même, une petite IA peut analyser et structurer le texte en sortie, mais reste
            <strong>peu fiable</strong> : risques d'<strong>erreurs</strong>, d'<strong>oublis</strong>
            et d'<strong>hallucinations</strong>.
        </p>
    </div>

    <div class="intro-cmp">
        <p>
            Je pense que la proposition d'<strong>Abdel</strong> est la plus simple et la plus efficace à implémenter
            dans un premier temps (<strong>via une IA cloud</strong>). De cette manière, on peut rapidement voir des résultats concrets.
        </p>
        <p>
            La solution <strong>100% locale</strong> est, je pense, très intéressante, mais elle demande un
            <strong>coût initial</strong> et une <strong>complexité d'installation</strong> non négligeables.
            Son atout majeur : on est totalement souverain et on garde le contrôle total sur les données.
            En revanche, la maintenabilité de l'infrastructure est à assurer soi-même — contrairement au cloud
            où c'est géré par le fournisseur.
        </p>
        <p>Ci-dessous, vous trouverez des tableaux comparatifs.</p>
    </div>

    <section class="comparatif">
        <h2>Comparatif Cloud vs Local</h2>
        <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Critère</th>
                    <th>Cloud (Google AI Studio / Qwen)</th>
                    <th>100% Local</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>RGPD / confidentialité</td>
                    <td class="cmp-warn">Risqué — données envoyées chez Google (USA) ou Alibaba (Chine)</td>
                    <td class="cmp-good">Optimal — rien ne sort</td>
                </tr>
                <tr>
                    <td>Facilité de config</td>
                    <td class="cmp-good">Très facile (clé API, prêt en 10 min)</td>
                    <td>Complexe (Ollama + modèle + VPN/Tailscale pour accès extérieur, ~demi-journée)</td>
                </tr>
                <tr>
                    <td>Coût initial</td>
                    <td class="cmp-good">Zéro</td>
                    <td>1 500 € minimum viable<br>2 500–3 500 € pour être à l'aise</td>
                </tr>
                <tr>
                    <td>Coût récurrent</td>
                    <td class="cmp-good">Faible à gratuit (Google AI Studio free tier généreux)</td>
                    <td class="cmp-good">~0 € (électricité)</td>
                </tr>
                <tr>
                    <td>Puissance de calcul</td>
                    <td class="cmp-good">Excellente (Gemini 2.0, Qwen2.5-72B)</td>
                    <td class="cmp-warn">Limitée par le matériel — en dessous de 1 500 € : trop lent pour être utile</td>
                </tr>
                <tr>
                    <td>Qualité des modèles</td>
                    <td class="cmp-good">Très bonne</td>
                    <td>Correcte à bonne selon budget — petit budget = petits modèles</td>
                </tr>
                <tr>
                    <td>Maintenabilité</td>
                    <td class="cmp-good">Zéro (géré par provider)</td>
                    <td class="cmp-warn">À la charge de l'équipe</td>
                </tr>
                <tr>
                    <td>Disponibilité</td>
                    <td class="cmp-warn">Dépend d'internet</td>
                    <td class="cmp-good">Autonome</td>
                </tr>
                <tr>
                    <td>Risque fournisseur</td>
                    <td class="cmp-warn">Google peut changer les tarifs / Qwen = Alibaba/Chine</td>
                    <td class="cmp-good">Aucun</td>
                </tr>
            </tbody>
        </table>
        </div>

        <h2 style="margin-top:36px">Modèles IA : Vision / PDF / JSON / Long contexte</h2>
        <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>Modèle</th>
                    <th>Vision / PDF</th>
                    <th>JSON structuré</th>
                    <th>Contexte</th>
                    <th>Dispo</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Gemini 2.5 Pro</td>
                    <td class="cmp-good">Excellent (PDF natif)</td>
                    <td>Oui, natif</td>
                    <td><strong>2 M tokens</strong></td>
                    <td><span class="cmp-badge badge-cloud">Cloud (AI Studio)</span></td>
                </tr>
                <tr>
                    <td>Gemini 2.0 Flash</td>
                    <td>Très bon</td>
                    <td>Oui, natif</td>
                    <td>1 M tokens</td>
                    <td><span class="cmp-badge badge-cloud">Cloud (AI Studio, gratuit)</span></td>
                </tr>
                <tr>
                    <td>Gemma 4 31B</td>
                    <td class="cmp-good">Excellent (OCR, charts)</td>
                    <td>Oui, natif + function calling</td>
                    <td>256 K tokens</td>
                    <td><span class="cmp-badge badge-both">Local ou Cloud</span></td>
                </tr>
                <tr>
                    <td>Gemma 4 27B MoE</td>
                    <td class="cmp-good">Excellent</td>
                    <td>Oui, natif</td>
                    <td>256 K tokens</td>
                    <td><span class="cmp-badge badge-both">Local ou Cloud</span></td>
                </tr>
                <tr>
                    <td>Gemma 4 E4B</td>
                    <td>Bon</td>
                    <td>Oui</td>
                    <td>128 K tokens</td>
                    <td><span class="cmp-badge badge-local">Local (laptop)</span></td>
                </tr>
                <tr>
                    <td>Gemma 4 E2B</td>
                    <td>Correct + audio</td>
                    <td>Oui</td>
                    <td>128 K tokens</td>
                    <td><span class="cmp-badge badge-local">Local (mobile/edge)</span></td>
                </tr>
                <tr>
                    <td>Qwen2.5-VL 72B</td>
                    <td>Très bon</td>
                    <td>Oui</td>
                    <td>128 K tokens</td>
                    <td><span class="cmp-badge badge-both">Cloud ou Local (lourd)</span></td>
                </tr>
                <tr>
                    <td>Qwen2.5-VL 7B</td>
                    <td>Correct</td>
                    <td>Oui</td>
                    <td>128 K tokens</td>
                    <td><span class="cmp-badge badge-local">Local (léger)</span></td>
                </tr>
                <tr>
                    <td>Llama 3.2 Vision 11B</td>
                    <td>Bon</td>
                    <td>Oui</td>
                    <td>128 K tokens</td>
                    <td><span class="cmp-badge badge-local">Local</span></td>
                </tr>
            </tbody>
        </table>
        </div>
    </section>

    <script>
        const form        = document.getElementById('uploadForm');
        const input       = document.getElementById('documentInput');
        const dropzone    = document.getElementById('dropzone');
        const fileLabel   = document.getElementById('fileLabel');
        const statusEl    = document.getElementById('status');
        const button      = document.getElementById('submitButton');
        const terminal    = document.getElementById('terminal');
        const cancelButton = document.getElementById('cancelButton');
        const debugPanel  = document.getElementById('debugPanel');
        const debugJson   = document.getElementById('debugJson');
        let   abortCtrl   = null;
        let   activeToken = null;

        cancelButton.addEventListener('click', async () => {
            const body = new FormData();
            body.append('action', 'cancel');
            if (activeToken) body.append('token', activeToken);
            await fetch(window.location.href, { method: 'POST', body }).catch(() => {});
            if (abortCtrl) abortCtrl.abort();
        });

        const scalarFields = ['title', 'modality', 'isInternal', 'organizerName', 'cost', 'expectedParticipants', 'isCertifying', 'context', 'startDate', 'endDate'];
        let allFormations  = [];

        // ── Dropzone ──
        input.addEventListener('change', () => {
            fileLabel.textContent = input.files[0] ? input.files[0].name : 'Déposer un fichier ou cliquer';
        });
        ['dragenter', 'dragover'].forEach(e => dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach(e => dropzone.addEventListener(e, ev => { ev.preventDefault(); dropzone.classList.remove('dragover'); }));
        dropzone.addEventListener('drop', ev => {
            if (ev.dataTransfer.files.length > 0) {
                input.files = ev.dataTransfer.files;
                fileLabel.textContent = input.files[0].name;
            }
        });

        // ── Terminal ──
        function termLog(text, cls = '') {
            const line = document.createElement('span');
            line.className = 'tl' + (cls ? ' tl-' + cls : '');
            line.textContent = text;
            terminal.appendChild(line);
            terminal.scrollTop = terminal.scrollHeight;
            return line;
        }

        function termClear() { terminal.innerHTML = ''; }

        async function isAnalysisBusy(token) {
            const body = new FormData();
            body.append('action', 'analysis-status');
            if (token) body.append('token', token);
            const resp = await fetch(window.location.href, { method: 'POST', body });
            const data = await resp.json();
            return {
                busy: !!data.busy,
                self: !!data.self,
                owner: typeof data.owner === 'string' ? data.owner : '',
            };
        }

        async function waitForAnalysisSlot(signal, line, token) {
            let wasBusy = false;
            let waitStart = null;
            let tickId = null;

            const startTick = () => {
                if (tickId) return;
                waitStart = Date.now();
                tickId = setInterval(() => {
                    const s = Math.floor((Date.now() - waitStart) / 1000);
                    line.textContent = `  ⏳ serveur occupé — vous êtes en attente…  ${s}s`;
                    line.className = 'tl tl-info';
                    terminal.scrollTop = terminal.scrollHeight;
                }, 500);
            };

            const stopTick = () => {
                if (tickId) { clearInterval(tickId); tickId = null; }
            };

            try {
                while (!signal.aborted) {
                    const status = await isAnalysisBusy(token);
                    if (!status.busy || status.self) {
                        stopTick();
                        return wasBusy;
                    }

                    if (!wasBusy) {
                        wasBusy = true;
                        startTick();
                    }

                    await new Promise((resolve, reject) => {
                        const timer = setTimeout(resolve, 1200);
                        signal.addEventListener('abort', () => {
                            clearTimeout(timer);
                            reject(new DOMException('Aborted', 'AbortError'));
                        }, { once: true });
                    });
                }
            } finally {
                stopTick();
            }

            throw new DOMException('Aborted', 'AbortError');
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' o';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' Ko';
            return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
        }

        // ── Submit ──
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!input.files[0]) { statusEl.textContent = 'Sélectionne un fichier à analyser.'; return; }

                const file = input.files[0];
            button.disabled = true;
            cancelButton.style.display = 'block';
            statusEl.textContent = '';
            termClear();
            terminal.style.display = 'block';
            abortCtrl = new AbortController();
            const { signal } = abortCtrl;
            let token = null;
            activeToken = null;

            const runTimer = (line, prefix) => {
                const t0 = Date.now();
                const id = setInterval(() => {
                    const s = ((Date.now() - t0) / 1000).toFixed(0);
                    line.textContent = `${prefix}  ${s}s`;
                    terminal.scrollTop = terminal.scrollHeight;
                }, 500);
                return () => clearInterval(id);
            };

            try {
                // ── Étape 1 : extraction ──
                termLog(`$ upload  ${file.name}  [${formatSize(file.size)}]`, 'cmd');
                const uploadLine = termLog('  ⏳ patientez, le serveur est peut-être occupé…  0s', 'info');
                const uploadTimer = runTimer(uploadLine, '  ⏳ patientez, le serveur est peut-être occupé…');

                const extractBody = new FormData();
                extractBody.append('document', file);
                extractBody.append('action', 'extract');

                const extractResp = await fetch(window.location.href, { method: 'POST', body: extractBody, signal });
                const extractData = await extractResp.json();
                uploadTimer();
                if (!extractData.ok) throw new Error(extractData.error);

                token = extractData.token;
                activeToken = token;
                const isVision = extractData.type === 'vision';
                uploadLine.textContent = `  ✓ fichier reçu  (${formatSize(file.size)})`;
                uploadLine.className = 'tl tl-ok';
                termLog(isVision
                    ? '  ✓ PDF scanné → mode vision  (pages converties en JPEG)'
                    : '  ✓ texte extrait du document', 'ok');

                const modelUsed = isVision ? '<?= $visionModel ?>' : '<?= $textModel ?>';
                termLog(`$ ollama  model=${modelUsed}`, 'cmd');

                // ── Étape 2 : split (texte uniquement) ──
                let splitCount = 1;
                if (!isVision) {
                    termLog('  → découpage des formations…', 'dim');
                    const splitBody = new FormData();
                    splitBody.append('action', 'split');
                    splitBody.append('token', token);
                    const splitResp = await fetch(window.location.href, { method: 'POST', body: splitBody, signal });
                    const splitData = await splitResp.json();
                    if (!splitData.ok) throw new Error(splitData.error);
                    splitCount = splitData.count || 1;
                    if (splitCount > 1) {
                        termLog(`  ✓ ${splitCount} formations détectées → analyse séquentielle`, 'ok');
                    } else {
                        termLog('  ✓ document unique → analyse directe', 'ok');
                    }
                }

                // ── Étape 3 : analyse ──
                const allFormations = [];
                const globalStart   = Date.now();
                const waitLine = termLog('  → vérification de la disponibilité du serveur…', 'info');
                await waitForAnalysisSlot(signal, waitLine, token);
                waitLine.textContent = '  ✓ serveur libre, analyse démarrée';
                waitLine.className = 'tl tl-ok';

                // Réserver le slot pour toute la durée de l'analyse multi-formations
                const reserveBody = new FormData();
                reserveBody.append('action', 'session-reserve');
                reserveBody.append('token', token);
                await fetch(window.location.href, { method: 'POST', body: reserveBody, signal });

                if (splitCount > 1) {
                    for (let i = 0; i < splitCount; i++) {
                        if (signal.aborted) break;

                        const fNum      = i + 1;
                        const fStart    = Date.now();
                        const fLine     = termLog(`  → formation ${fNum}/${splitCount}…  0s`, 'info');
                        const stopTimer = runTimer(fLine, `  → formation ${fNum}/${splitCount}…`);

                        const aBody = new FormData();
                        aBody.append('action', 'analyze-one');
                        aBody.append('token', token);
                        aBody.append('index', String(i));

                        let aData;
                        try {
                            const aResp = await fetch(window.location.href, { method: 'POST', body: aBody, signal });
                            aData = await aResp.json();
                        } finally {
                            stopTimer();
                        }

                        const fElapsed = ((Date.now() - fStart) / 1000).toFixed(1);
                        if (!aData || !aData.ok) {
                            fLine.textContent = `  ✗ formation ${fNum}/${splitCount} : ${aData?.error || 'erreur'}`;
                            fLine.className   = 'tl tl-err';
                            continue;
                        }

                        fLine.textContent = `  ✓ formation ${fNum}/${splitCount}  (${fElapsed}s)`;
                        fLine.className   = 'tl tl-ok';
                        allFormations.push(...(aData.data?.formations || []));
                    }
                } else {
                    await waitForAnalysisSlot(signal, waitLine, token);
                    const tLine = termLog('  → en attente de la réponse…  0s', 'info');
                    const stopTimer = runTimer(tLine, '  → en attente de la réponse…');

                    const analyzeBody = new FormData();
                    analyzeBody.append('action', 'analyze');
                    analyzeBody.append('token', token);

                    let analyzeData;
                    try {
                        const analyzeResp = await fetch(window.location.href, { method: 'POST', body: analyzeBody, signal });
                        analyzeData = await analyzeResp.json();
                    } finally {
                        stopTimer();
                    }

                    if (!analyzeData.ok) throw new Error(analyzeData.error);
                    const elapsed = ((Date.now() - globalStart) / 1000).toFixed(1);
                    tLine.textContent = `  ✓ réponse reçue en ${elapsed}s`;
                    tLine.className   = 'tl tl-ok';
                    allFormations.push(...(analyzeData.data?.formations || []));
                    showDebug(analyzeData.raw, analyzeData.data);
                }

                if (signal.aborted) {
                    termLog('  ✗ analyse annulée', 'err');
                    return;
                }

                const totalElapsed = ((Date.now() - globalStart) / 1000).toFixed(1);
                const nFormations  = allFormations.length;
                termLog(`  ✓ ${nFormations} formation${nFormations !== 1 ? 's' : ''} en ${totalElapsed}s`, 'ok');

                termLog('$ fill  formulaire', 'cmd');
                const mergedData = { formations: allFormations };
                if (splitCount > 1) showDebug(null, mergedData);
                fillForm(mergedData);

                termLog(`  ✓ ${nFormations} formation${nFormations !== 1 ? 's' : ''}`, 'ok');
                termLog('  ✓ done', 'ok');

            } catch (err) {
                if (err.name === 'AbortError') {
                    termLog('  ✗ analyse annulée', 'err');
                } else {
                    termLog(`  ✗ ${err.message}`, 'err');
                    statusEl.textContent = err.message;
                }
            } finally {
                if (token) {
                    const cb = new FormData();
                    cb.append('action', 'cleanup');
                    cb.append('token', token);
                    fetch(window.location.href, { method: 'POST', body: cb }).catch(() => {});
                }
                hideWaitModal();
                activeToken = null;
                button.disabled = false;
                cancelButton.style.display = 'none';
                abortCtrl = null;
            }
        });

        function showDebug(raw, normalized) {
            debugJson.textContent = JSON.stringify({
                rawFromAI: raw,
                usedByForm: normalized
            }, null, 2);
            debugPanel.style.display = 'block';
        }

        // ── Remplissage formulaire ──
        function fillForm(data) {
            allFormations = data.formations || [];
            if (allFormations.length === 0) return;

            const formationRow    = document.getElementById('formationRow');
            const formationSelect = document.getElementById('formationSelect');

            if (allFormations.length > 1) {
                formationSelect.innerHTML = '';
                allFormations.forEach((f, i) => {
                    const opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = f.title || `Formation ${i + 1}`;
                    formationSelect.appendChild(opt);
                });
                formationRow.style.display = '';
                formationSelect.onchange = () => fillFormation(allFormations[parseInt(formationSelect.value)]);
            } else {
                formationRow.style.display = 'none';
            }
            fillFormation(allFormations[0]);
        }

        function fillFormation(formation) {
            for (const field of scalarFields) {
                const el = document.getElementById(field);
                if (!el) continue;
                const v = formation[field];
                el.value = (v === null || v === undefined) ? '' : (typeof v === 'boolean' ? String(v) : v);
            }
        }
    </script>
</body>
</html>
    <?php
}
