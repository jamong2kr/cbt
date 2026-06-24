<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/database.php';

$sessionPath = __DIR__ . '/data/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_start();

function openAdminDb(): PDO
{
    return cbtOpenDatabase();
}

function sendJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['cbt_admin_logged_in'])) {
    sendJson([
        'error' => '관리자 로그인이 필요합니다.',
    ], 401);
}

function readJsonPayload(): array
{
    $rawPayload = (string) file_get_contents('php://input');
    if ($rawPayload === '' && PHP_SAPI === 'cli') {
        $rawPayload = (string) file_get_contents('php://stdin');
    }

    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        throw new RuntimeException('요청 데이터가 올바르지 않습니다.');
    }

    return $payload;
}

function catalog(PDO $pdo): array
{
    $rows = $pdo->query('SELECT
            l.name AS level_name,
            c.name AS certification_name,
            r.round_name
        FROM qualification_levels l
        JOIN certifications c ON c.level_id = l.id
        JOIN exam_rounds r ON r.certification_id = c.id
        ORDER BY l.id, c.id, r.id')->fetchAll();

    $catalog = [];
    foreach ($rows as $row) {
        $level = (string) $row['level_name'];
        $catalog[$level] ??= [];
        $catalog[$level][] = [
            'certification' => (string) $row['certification_name'],
            'round' => (string) $row['round_name'],
            'label' => trim((string) $row['certification_name'] . ' ' . (string) $row['round_name']),
        ];
    }

    return $catalog;
}

function requireText(array $payload, string $key): string
{
    return trim((string) ($payload[$key] ?? ''));
}

function nullableText(array $payload, string $key): ?string
{
    $value = trim((string) ($payload[$key] ?? ''));
    return $value === '' ? null : $value;
}

function nullablePercent(array $payload, string $key): ?int
{
    $value = trim((string) ($payload[$key] ?? ''));
    if ($value === '') {
        return null;
    }

    $percent = (int) $value;
    if ($percent < 10 || $percent > 100) {
        throw new RuntimeException('이미지 너비는 10부터 100 사이로 입력해주세요.');
    }

    return $percent;
}

function getQuestionById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT
            q.id,
            q.question_number,
            q.subject,
            q.question_text,
            q.question_image,
            q.question_image_width_percent,
            q.option_1_text,
            q.option_1_image,
            q.option_1_image_width_percent,
            q.option_2_text,
            q.option_2_image,
            q.option_2_image_width_percent,
            q.option_3_text,
            q.option_3_image,
            q.option_3_image_width_percent,
            q.option_4_text,
            q.option_4_image,
            q.option_4_image_width_percent,
            q.answer,
            q.explanation_text,
            q.explanation_image,
            q.explanation_image_width_percent,
            l.name AS level_name,
            c.name AS certification_name,
            r.round_name
        FROM exam_questions q
        JOIN exam_rounds r ON r.id = q.round_id
        JOIN certifications c ON c.id = r.certification_id
        JOIN qualification_levels l ON l.id = c.level_id
        WHERE q.id = :id
        LIMIT 1');
    $stmt->execute([':id' => $id]);
    $question = $stmt->fetch();

    return $question ?: null;
}

try {
    $pdo = openAdminDb();
    $action = (string) ($_GET['action'] ?? '');

    if ($action === 'catalog') {
        sendJson(['ok' => true, 'catalog' => catalog($pdo)]);
    }

    if ($action === 'questions') {
        $level = trim((string) ($_GET['level'] ?? ''));
        $certification = trim((string) ($_GET['certification'] ?? ''));
        $round = trim((string) ($_GET['round'] ?? ''));

        if ($level === '' || $certification === '' || $round === '') {
            throw new RuntimeException('시험 정보를 선택해주세요.');
        }

        $stmt = $pdo->prepare('SELECT
                q.id,
                q.question_number,
                q.subject,
                q.question_text,
                q.answer
            FROM exam_questions q
            JOIN exam_rounds r ON r.id = q.round_id
            JOIN certifications c ON c.id = r.certification_id
            JOIN qualification_levels l ON l.id = c.level_id
            WHERE l.name = :level
            AND c.name = :certification
            AND r.round_name = :round
            ORDER BY q.question_number');
        $stmt->execute([
            ':level' => $level,
            ':certification' => $certification,
            ':round' => $round,
        ]);

        sendJson(['ok' => true, 'questions' => $stmt->fetchAll()]);
    }

    if ($action === 'question') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('문제 ID가 올바르지 않습니다.');
        }

        $question = getQuestionById($pdo, $id);
        if ($question === null) {
            throw new RuntimeException('문제를 찾을 수 없습니다.');
        }

        sendJson(['ok' => true, 'question' => $question]);
    }

    if ($action === 'update') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            sendJson(['error' => 'POST 요청만 사용할 수 있습니다.'], 405);
        }

        $payload = readJsonPayload();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('문제 ID가 올바르지 않습니다.');
        }

        $answer = (int) ($payload['answer'] ?? 0);
        if ($answer < 1 || $answer > 4) {
            throw new RuntimeException('정답은 1부터 4 사이로 선택해주세요.');
        }

        $questionText = requireText($payload, 'question_text');
        if ($questionText === '') {
            throw new RuntimeException('문제 지문을 입력해주세요.');
        }

        $subjectName = requireText($payload, 'subject');
        if ($subjectName === '') {
            throw new RuntimeException('과목을 입력해주세요.');
        }
        $subjectStmt = $pdo->prepare('SELECT es.id
            FROM exam_questions q
            JOIN exam_subjects es ON es.round_id = q.round_id AND es.name = :subject
            WHERE q.id = :id
            LIMIT 1');
        $subjectStmt->execute([':subject' => $subjectName, ':id' => $id]);
        $subjectId = $subjectStmt->fetchColumn();
        if ($subjectId === false) {
            throw new RuntimeException('등록된 과목 구성을 찾을 수 없습니다. 시험/문제 등록 페이지에서 과목을 먼저 추가해주세요.');
        }

        $stmt = $pdo->prepare('UPDATE exam_questions
            SET subject_id = :subject_id,
                subject = :subject,
                question_text = :question_text,
                question_image = :question_image,
                question_image_width_percent = :question_image_width_percent,
                option_1_text = :option_1_text,
                option_1_image = :option_1_image,
                option_1_image_width_percent = :option_1_image_width_percent,
                option_2_text = :option_2_text,
                option_2_image = :option_2_image,
                option_2_image_width_percent = :option_2_image_width_percent,
                option_3_text = :option_3_text,
                option_3_image = :option_3_image,
                option_3_image_width_percent = :option_3_image_width_percent,
                option_4_text = :option_4_text,
                option_4_image = :option_4_image,
                option_4_image_width_percent = :option_4_image_width_percent,
                answer = :answer,
                explanation_text = :explanation_text,
                explanation_image = :explanation_image,
                explanation_image_width_percent = :explanation_image_width_percent
            WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':subject_id' => (int) $subjectId,
            ':subject' => $subjectName,
            ':question_text' => $questionText,
            ':question_image' => nullableText($payload, 'question_image'),
            ':question_image_width_percent' => nullablePercent($payload, 'question_image_width_percent'),
            ':option_1_text' => requireText($payload, 'option_1_text'),
            ':option_1_image' => nullableText($payload, 'option_1_image'),
            ':option_1_image_width_percent' => nullablePercent($payload, 'option_1_image_width_percent'),
            ':option_2_text' => requireText($payload, 'option_2_text'),
            ':option_2_image' => nullableText($payload, 'option_2_image'),
            ':option_2_image_width_percent' => nullablePercent($payload, 'option_2_image_width_percent'),
            ':option_3_text' => requireText($payload, 'option_3_text'),
            ':option_3_image' => nullableText($payload, 'option_3_image'),
            ':option_3_image_width_percent' => nullablePercent($payload, 'option_3_image_width_percent'),
            ':option_4_text' => requireText($payload, 'option_4_text'),
            ':option_4_image' => nullableText($payload, 'option_4_image'),
            ':option_4_image_width_percent' => nullablePercent($payload, 'option_4_image_width_percent'),
            ':answer' => $answer,
            ':explanation_text' => nullableText($payload, 'explanation_text'),
            ':explanation_image' => nullableText($payload, 'explanation_image'),
            ':explanation_image_width_percent' => nullablePercent($payload, 'explanation_image_width_percent'),
        ]);

        $question = getQuestionById($pdo, $id);
        sendJson(['ok' => true, 'question' => $question]);
    }

    sendJson(['error' => '지원하지 않는 요청입니다.'], 404);
} catch (Throwable $error) {
    sendJson([
        'error' => '관리자 API 요청을 처리하지 못했습니다.',
        'detail' => $error->getMessage(),
    ], 500);
}
