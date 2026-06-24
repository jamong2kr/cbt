<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/database.php';

$qualificationLevel = (string) ($_GET['level'] ?? '기능사');
$certificationName = (string) ($_GET['certification'] ?? '자동차정비기능사');
$roundName = (string) ($_GET['round'] ?? '1회');
$electiveSubjectId = (int) ($_GET['elective_subject_id'] ?? 0);
$electiveSubject = trim((string) ($_GET['elective_subject'] ?? ''));
$applicantName = trim((string) ($_GET['applicant_name'] ?? ''));
$examNumber = preg_replace('/\D+/', '', (string) ($_GET['exam_number'] ?? ''));

if ($applicantName === '') {
    $applicantName = '미입력';
}

if (!preg_match('/^\d{8}$/', $examNumber)) {
    $examNumber = '00000000';
}

$defaultTimeLimitMinutes = 60;
$questionCachePath = __DIR__ . '/data/questions.json';

function openExamDb(): PDO
{
    $pdo = cbtOpenDatabase();
    ensureExamSessionsTable($pdo);

    return $pdo;
}

function ensureExamSessionsTable(PDO $pdo): void
{
    cbtEnsureExamSessionsTable($pdo);
}

function examExists(PDO $pdo, string $level, string $certification, string $round): bool
{
    $stmt = $pdo->prepare('SELECT 1
        FROM exam_rounds r
        JOIN certifications c ON c.id = r.certification_id
        JOIN qualification_levels l ON l.id = c.level_id
        WHERE l.name = :level
        AND c.name = :certification
        AND r.round_name = :round
        LIMIT 1');
    $stmt->execute([
        ':level' => $level,
        ':certification' => $certification,
        ':round' => $round,
    ]);

    return (bool) $stmt->fetchColumn();
}

function getDefaultExam(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT
            l.name AS level_name,
            c.name AS certification_name,
            r.round_name
        FROM exam_rounds r
        JOIN certifications c ON c.id = r.certification_id
        JOIN qualification_levels l ON l.id = c.level_id
        ORDER BY l.id, c.id, r.id
        LIMIT 1');
    $exam = $stmt->fetch();

    return $exam ?: null;
}

function getElectiveSubjects(PDO $pdo, string $level, string $certification, string $round): array
{
    $stmt = $pdo->prepare('SELECT es.id, es.name
        FROM exam_subjects es
        JOIN exam_rounds r ON r.id = es.round_id
        JOIN certifications c ON c.id = r.certification_id
        JOIN qualification_levels l ON l.id = c.level_id
        WHERE l.name = :level
        AND c.name = :certification
        AND r.round_name = :round
        AND es.subject_type = \'elective\'
        AND es.active = 1
        ORDER BY es.display_order, es.id');
    $stmt->execute([':level' => $level, ':certification' => $certification, ':round' => $round]);
    return $stmt->fetchAll();
}

try {
    $validationPdo = openExamDb();
    if (!examExists($validationPdo, $qualificationLevel, $certificationName, $roundName)) {
        $defaultExam = getDefaultExam($validationPdo);
        if ($defaultExam !== null) {
            $qualificationLevel = $defaultExam['level_name'];
            $certificationName = $defaultExam['certification_name'];
            $roundName = $defaultExam['round_name'];
        }
    }
} catch (Throwable) {
    $qualificationLevel = '기능사';
    $certificationName = '자동차정비기능사';
    $roundName = '1회';
}

try {
    $subjectPdo = $validationPdo ?? openExamDb();
    $electiveSubjects = getElectiveSubjects($subjectPdo, $qualificationLevel, $certificationName, $roundName);
    if ($electiveSubjects) {
        $selectedElective = null;
        foreach ($electiveSubjects as $subject) {
            if (($electiveSubjectId > 0 && (int) $subject['id'] === $electiveSubjectId)
                || ($electiveSubjectId <= 0 && $electiveSubject !== '' && $subject['name'] === $electiveSubject)
            ) {
                $selectedElective = $subject;
                break;
            }
        }
        $selectedElective ??= $electiveSubjects[0];
        $electiveSubjectId = (int) $selectedElective['id'];
        $electiveSubject = (string) $selectedElective['name'];
    } else {
        $electiveSubjectId = 0;
        $electiveSubject = '';
    }
} catch (Throwable) {
    $electiveSubjectId = 0;
    $electiveSubject = '';
}

function sendJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getSessionWhereSql(): string
{
    return 'exam_number = :exam_number
        AND applicant_name = :applicant_name
        AND qualification_level = :level
        AND certification_name = :certification
        AND round_name = :round
        AND (
            elective_subject_id = :elective_subject_id
            OR (elective_subject_id IS NULL AND (elective_subject = :elective_subject OR elective_subject = \'\'))
        )';
}

function getSessionParams(): array
{
    global $examNumber, $applicantName, $qualificationLevel, $certificationName, $roundName, $electiveSubjectId, $electiveSubject;

    return [
        ':exam_number' => $examNumber,
        ':applicant_name' => $applicantName,
        ':level' => $qualificationLevel,
        ':certification' => $certificationName,
        ':round' => $roundName,
        ':elective_subject_id' => $electiveSubjectId > 0 ? $electiveSubjectId : null,
        ':elective_subject' => $electiveSubject,
    ];
}

$questionSql = 'SELECT
    q.question_number AS q_number,
    q.subject AS subject_name,
    q.question_text,
    q.question_image,
    q.question_image_width_percent,
    q.option_1_text AS option_1,
    q.option_1_image,
    q.option_1_image_width_percent,
    q.option_2_text AS option_2,
    q.option_2_image,
    q.option_2_image_width_percent,
    q.option_3_text AS option_3,
    q.option_3_image,
    q.option_3_image_width_percent,
    q.option_4_text AS option_4,
    q.option_4_image,
    q.option_4_image_width_percent,
    q.answer,
    q.explanation_text AS explanation,
    q.explanation_image,
    q.explanation_image_width_percent,
    r.time_limit_minutes
FROM exam_questions q
JOIN exam_rounds r ON r.id = q.round_id
JOIN certifications c ON c.id = r.certification_id
JOIN qualification_levels l ON l.id = c.level_id
LEFT JOIN exam_subjects es ON es.id = q.subject_id
WHERE l.name = :level AND c.name = :certification AND r.round_name = :round
AND (COALESCE(es.subject_type, \'common\') = \'common\' OR q.subject_id = :elective_subject_id)
ORDER BY q.question_number, q.id';

if (($_GET['api'] ?? '') === 'session') {
    try {
        $pdo = openExamDb();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $payload = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($payload)) {
                throw new RuntimeException('저장할 진행 정보가 올바르지 않습니다.');
            }

            $answers = $payload['answers'] ?? [];
            if (!is_array($answers)) {
                $answers = [];
            }

            $currentQuestionIndex = max(0, (int) ($payload['currentQuestionIndex'] ?? 0));
            $remainingSeconds = max(0, (int) ($payload['remainingSeconds'] ?? $defaultTimeLimitMinutes * 60));
            $submitted = !empty($payload['submitted']) ? 1 : 0;

            $update = $pdo->prepare('UPDATE exam_sessions
                SET answers_json = :answers_json,
                    current_question_index = :current_question_index,
                    remaining_seconds = :remaining_seconds,
                    submitted = :submitted,
                    elective_subject = :session_elective_subject,
                    elective_subject_id = :session_elective_subject_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE ' . getSessionWhereSql());
            $update->execute([
                ':answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                ':current_question_index' => $currentQuestionIndex,
                ':remaining_seconds' => $remainingSeconds,
                ':submitted' => $submitted,
                ':session_elective_subject' => $electiveSubject,
                ':session_elective_subject_id' => $electiveSubjectId > 0 ? $electiveSubjectId : null,
                ...getSessionParams(),
            ]);

            if ($update->rowCount() === 0) {
                throw new RuntimeException('수험성명과 수험번호가 일치하는 진행 정보를 찾을 수 없습니다.');
            }

            sendJson(['ok' => true]);
        }

        $select = $pdo->prepare('SELECT answers_json, current_question_index, remaining_seconds, submitted, updated_at
            FROM exam_sessions
            WHERE ' . getSessionWhereSql() . '
            LIMIT 1');
        $select->execute(getSessionParams());
        $session = $select->fetch();

        if (!$session) {
            sendJson(['ok' => true, 'session' => null]);
        }

        $answers = json_decode((string) $session['answers_json'], true);
        if (!is_array($answers)) {
            $answers = [];
        }

        sendJson([
            'ok' => true,
            'session' => [
                'answers' => $answers,
                'currentQuestionIndex' => (int) $session['current_question_index'],
                'remainingSeconds' => $session['remaining_seconds'] === null
                    ? null
                    : (int) $session['remaining_seconds'],
                'submitted' => (bool) $session['submitted'],
                'updatedAt' => $session['updated_at'],
            ],
        ]);
    } catch (Throwable $error) {
        sendJson([
            'error' => '진행 정보를 처리하지 못했습니다.',
            'detail' => $error->getMessage(),
        ], 500);
    }
}

if (($_GET['api'] ?? '') === 'questions') {
    try {
        $pdo = cbtOpenDatabase();
        $stmt = $pdo->prepare($questionSql);
        $stmt->execute([
            ':level' => $qualificationLevel,
            ':certification' => $certificationName,
            ':round' => $roundName,
            ':elective_subject_id' => $electiveSubjectId > 0 ? $electiveSubjectId : null,
        ]);
        $questions = $stmt->fetchAll();

        if (!$questions) {
            throw new RuntimeException('시험 데이터가 없습니다.');
        }

        sendJson([
            'meta' => [
                'qualificationLevel' => $qualificationLevel,
                'certificationName' => $certificationName,
                'roundName' => $roundName,
                'electiveSubjectId' => $electiveSubjectId,
                'electiveSubject' => $electiveSubject,
                'testName' => $certificationName . ' ' . $roundName,
                'timeLimitMinutes' => (int) ($questions[0]['time_limit_minutes'] ?? $defaultTimeLimitMinutes),
            ],
            'questions' => $questions,
        ]);
    } catch (Throwable $error) {
        sendJson([
            'error' => 'DB를 불러오지 못했습니다.',
            'detail' => $error->getMessage(),
        ], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($certificationName . ' ' . $roundName, ENT_QUOTES, 'UTF-8') ?> CBT 시험장</title>
    <meta name="description" content="자동차정비기능사 CBT 모의시험 화면">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="style/cbt_main.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined&amp;icon_names=content_paste,description,nest_clock_farsight_analog&amp;display=block">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard-dynamic-subset.css" crossorigin>
</head>
<body>
    <div id="app">
        <header class="main-header">
            <div class="header-left">
                <button class="test-icon home-link-icon" id="back-to-start" type="button" aria-label="시험 선택 페이지로 돌아가기"></button>
                <h1 class="test-title"><?= htmlspecialchars($certificationName . ' ' . $roundName, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="header-right">
                <div class="user-info">
                    <label for="exam-number">수험번호:</label>
                    <textarea id="exam-number" rows="1" readonly><?= htmlspecialchars($examNumber, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <label for="exam-name">수험성명:</label>
                    <textarea id="exam-name" rows="1" readonly><?= htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="timer-group">
                    <span class="timer-icon material-symbols-outlined">
                        nest_clock_farsight_analog
                    </span>
                    <div class="timer-texts">
                        <span class="time-limit">제한 시간 : 60분</span><br>
                        <span class="time-remaining">남은 시간 : 59분 30초</span>
                    </div>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="exam-panel">
                <nav class="toolbar">
                    <div class="stats-group">
                        <span>전체 문제 수 : <span class="stats-count" id="total-question-count">0</span></span>
                        <span>안 푼 문제 수 : <span class="stats-count" id="unanswered-question-count">0</span></span>
                    </div>
                </nav>

                <section class="questions-area">
                    <div class="question-container">
                        <div class="question-header">
                            <span class="question-number">1.</span>
                            <p class="question-text">문제를 불러오는 중입니다.</p>
                        </div>
                        <div class="options-container">
                            <label class="option"><input type="radio" name="loading" disabled> DB 파일을 읽고 있습니다.</label>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="omr-sidebar">
                <div class="omr-header">답안 표기란</div>
                <div class="omr-sheet" aria-live="polite">
                    <div class="omr-loading">답안지를 불러오는 중입니다.</div>
                </div>
            </aside>
        </main>

        <footer>
            <div class="footer-center">
                <button class="nav-page-btn" id="prev-page">◀ 이전</button>
                <span class="page-info">1/20</span>
                <button class="nav-page-btn" id="next-page">다음 ▶</button>
            </div>
            <div class="footer-right">
                <button class="action-btn unanswered" id="first-unanswered">
                    <span class="material-symbols-outlined">description</span>
                    안 푼 문제
                </button>
                <button class="action-btn submit" id="submit-exam">
                    <span class="material-symbols-outlined">content_paste</span>
                    답안 제출
                </button>
            </div>
        </footer>

        <div class="modal-backdrop" id="unanswered-modal" hidden>
            <section class="unanswered-modal" role="dialog" aria-modal="true" aria-labelledby="unanswered-modal-title">
                <div class="modal-title-row">
                    <h2 id="unanswered-modal-title">안 푼 문제</h2>
                    <button class="modal-close" id="close-unanswered-modal" type="button" aria-label="닫기">&times;</button>
                </div>
                <div class="unanswered-list" id="unanswered-list"></div>
            </section>
        </div>
    </div>
    <noscript>이 CBT 시험장은 JavaScript를 사용할 수 있어야 실행됩니다.</noscript>
    <script>
        window.CBT_EXAM_CONFIG = {
            apiUrl: <?= json_encode('main.php?' . http_build_query([
                'api' => 'questions',
                'level' => $qualificationLevel,
                'certification' => $certificationName,
                'round' => $roundName,
                'elective_subject_id' => $electiveSubjectId,
                'elective_subject' => $electiveSubject,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            sessionUrl: <?= json_encode('main.php?' . http_build_query([
                'api' => 'session',
                'level' => $qualificationLevel,
                'certification' => $certificationName,
                'round' => $roundName,
                'elective_subject_id' => $electiveSubjectId,
                'elective_subject' => $electiveSubject,
                'applicant_name' => $applicantName,
                'exam_number' => $examNumber,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            applicantName: <?= json_encode($applicantName, JSON_UNESCAPED_UNICODE) ?>,
            examNumber: <?= json_encode($examNumber, JSON_UNESCAPED_UNICODE) ?>,
            qualificationLevel: <?= json_encode($qualificationLevel, JSON_UNESCAPED_UNICODE) ?>,
            electiveSubjectId: <?= (int) $electiveSubjectId ?>,
            electiveSubject: <?= json_encode($electiveSubject, JSON_UNESCAPED_UNICODE) ?>,
            testName: <?= json_encode($certificationName . ' ' . $roundName, JSON_UNESCAPED_UNICODE) ?>,
            timeLimitMinutes: <?= (int) $defaultTimeLimitMinutes ?>
        };
    </script>
    <script src="js/cbt_exam.js" defer></script>
</body>
</html>
