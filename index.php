<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/database.php';

$sessionPath = __DIR__ . '/data/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_start();

const ADMIN_ID = '골든벨';
const ADMIN_PASSWORD = 'k7134135';
$catalogError = null;
$examCatalog = loadExamCatalog($catalogError);
$errors = $catalogError === null ? [] : [$catalogError];
$selectedCategory = $_POST['category'] ?? '';
$selectedExam = $_POST['exam'] ?? '';
$selectedElectiveSubjectId = (int) ($_POST['elective_subject_id'] ?? 0);
$applicantName = trim((string) ($_POST['applicant_name'] ?? ''));
$rawExamNumber = trim((string) ($_POST['exam_number'] ?? ''));
$examNumber = preg_replace('/\D+/', '', $rawExamNumber);

if (($_GET['admin_required'] ?? '') === '1') {
    $errors[] = '문제 수정 페이지는 수험성명과 수험번호에 관리자 계정을 입력한 뒤 이용할 수 있습니다.';
}

if (($_GET['admin_logout'] ?? '') === '1') {
    unset($_SESSION['cbt_admin_logged_in']);
    header('Location: index.php');
    exit;
}

function openExamDb(): PDO
{
    $pdo = cbtOpenDatabase();
    ensureExamSessionsTable($pdo);
    cleanupOldExamSessions($pdo);

    return $pdo;
}

function ensureExamSessionsTable(PDO $pdo): void
{
    cbtEnsureExamSessionsTable($pdo);
}

function cleanupOldExamSessions(PDO $pdo): void
{
    $markerPath = __DIR__ . '/data/session_cleanup.txt';
    $today = date('Y-m-d');

    if (is_file($markerPath) && trim((string) file_get_contents($markerPath)) === $today) {
        return;
    }

    if (!is_dir(dirname($markerPath))) {
        mkdir(dirname($markerPath), 0755, true);
    }

    cbtCleanupOldExamSessions($pdo);

    file_put_contents($markerPath, $today, LOCK_EX);
}

function loadExamCatalog(?string &$error): array
{
    $catalog = [];

    try {
        $pdo = openExamDb();
        $levels = $pdo->query('SELECT name FROM qualification_levels ORDER BY id')->fetchAll();
        foreach ($levels as $level) {
            $catalog[$level['name']] = [];
        }

        $rows = $pdo->query('SELECT
                l.name AS level_name,
                c.name AS certification_name,
                r.round_name,
                r.time_limit_minutes,
                c.id AS certification_id,
                r.id AS round_id
            FROM qualification_levels l
            JOIN certifications c ON c.level_id = l.id
            JOIN exam_rounds r ON r.certification_id = c.id
            ORDER BY l.id, c.id, r.id')->fetchAll();

        $subjectRows = $pdo->query('SELECT
                id,
                round_id,
                name,
                slug,
                subject_type,
                elective_group,
                question_start,
                question_end,
                question_count,
                display_order,
                active
            FROM exam_subjects
            WHERE active = 1
            ORDER BY round_id, display_order, id')->fetchAll();
        $subjectsByRound = [];
        foreach ($subjectRows as $subjectRow) {
            $roundId = (string) $subjectRow['round_id'];
            $subjectsByRound[$roundId][] = [
                'id' => (int) $subjectRow['id'],
                'name' => (string) $subjectRow['name'],
                'slug' => (string) $subjectRow['slug'],
                'type' => (string) $subjectRow['subject_type'],
                'electiveGroup' => (string) $subjectRow['elective_group'],
                'questionStart' => (int) $subjectRow['question_start'],
                'questionEnd' => (int) $subjectRow['question_end'],
                'questionCount' => (int) $subjectRow['question_count'],
                'displayOrder' => (int) $subjectRow['display_order'],
            ];
        }

        $roundCounts = [];
        foreach ($rows as $row) {
            $roundCounts[(string) $row['certification_id']] = ($roundCounts[(string) $row['certification_id']] ?? 0) + 1;
        }

        foreach ($rows as $row) {
            $label = $row['certification_name'];
            if (($roundCounts[(string) $row['certification_id']] ?? 0) > 1) {
                $label .= ' ' . $row['round_name'];
            }

            $catalog[$row['level_name']][$label] = [
                'level' => $row['level_name'],
                'certification' => $row['certification_name'],
                'round' => $row['round_name'],
                'timeLimitMinutes' => (int) $row['time_limit_minutes'],
                'subjects' => $subjectsByRound[(string) $row['round_id']] ?? [],
            ];
        }
    } catch (Throwable $exception) {
        $error = '시험 목록을 DB에서 불러오지 못했습니다: ' . $exception->getMessage();
    }

    return $catalog;
}

function findExamSession(
    PDO $pdo,
    string $examNumber,
    string $level,
    string $certification,
    string $round,
    ?int $electiveSubjectId,
    string $electiveSubject
): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM exam_sessions
        WHERE exam_number = :exam_number
        AND qualification_level = :level
        AND certification_name = :certification
        AND round_name = :round
        AND (
            elective_subject_id = :elective_subject_id
            OR (elective_subject_id IS NULL AND (elective_subject = :elective_subject OR elective_subject = \'\'))
        )
        LIMIT 1');
    $stmt->execute([
        ':exam_number' => $examNumber,
        ':level' => $level,
        ':certification' => $certification,
        ':round' => $round,
        ':elective_subject_id' => $electiveSubjectId,
        ':elective_subject' => $electiveSubject,
    ]);

    $session = $stmt->fetch();
    return $session ?: null;
}

function examNumberExists(PDO $pdo, string $examNumber): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM exam_sessions WHERE exam_number = :exam_number LIMIT 1');
    $stmt->execute([':exam_number' => $examNumber]);

    return (bool) $stmt->fetchColumn();
}

function createExamSession(
    PDO $pdo,
    string $examNumber,
    string $applicantName,
    array $exam,
    ?int $electiveSubjectId,
    string $electiveSubject
): void
{
    $stmt = $pdo->prepare('INSERT INTO exam_sessions (
        exam_number,
        applicant_name,
        qualification_level,
        certification_name,
        round_name,
        answers_json,
        elective_subject,
        elective_subject_id
    ) VALUES (
        :exam_number,
        :applicant_name,
        :level,
        :certification,
        :round,
        :answers_json,
        :elective_subject,
        :elective_subject_id
    )');
    $stmt->execute([
        ':exam_number' => $examNumber,
        ':applicant_name' => $applicantName,
        ':level' => $exam['level'],
        ':certification' => $exam['certification'],
        ':round' => $exam['round'],
        ':answers_json' => '[]',
        ':elective_subject' => $electiveSubject,
        ':elective_subject_id' => $electiveSubjectId,
    ]);
}

function issueExamNumber(PDO $pdo): string
{
    do {
        $examNumber = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    } while (examNumberExists($pdo, $examNumber));

    return $examNumber;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && hash_equals(ADMIN_ID, $applicantName)
    && hash_equals(ADMIN_PASSWORD, $rawExamNumber)
) {
        session_regenerate_id(true);
        $_SESSION['cbt_admin_logged_in'] = true;
        header('Location: admin_questions.php');
        exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!array_key_exists($selectedCategory, $examCatalog)) {
        $errors[] = '올바른 시험 분류를 선택해주세요.';
    }

    $availableExams = $examCatalog[$selectedCategory] ?? [];
    if (!isset($availableExams[$selectedExam])) {
        $errors[] = '올바른 시험을 선택해주세요.';
    }

    if ($applicantName === '') {
        $errors[] = '수험성명을 입력해주세요.';
    }

    if (!$errors) {
        $exam = $availableExams[$selectedExam];
        $electiveSubject = '';
        $electiveSubjectId = null;
        $electiveSubjects = array_values(array_filter(
            $exam['subjects'] ?? [],
            static fn (array $subject): bool => $subject['type'] === 'elective'
        ));

        if ($electiveSubjects) {
            $selectedSubject = null;
            foreach ($electiveSubjects as $subject) {
                if ($subject['id'] === $selectedElectiveSubjectId) {
                    $selectedSubject = $subject;
                    break;
                }
            }
            if ($selectedSubject === null) {
                $errors[] = '선택과목을 선택해주세요.';
            } else {
                $electiveSubjectId = $selectedSubject['id'];
                $electiveSubject = $selectedSubject['name'];
            }
        }

        try {
            if ($errors) {
                throw new RuntimeException('입력값을 다시 확인해주세요.');
            }

            $pdo = openExamDb();
            $issuedExamNumber = $examNumber;

            if ($issuedExamNumber !== '') {
                if (!preg_match('/^\d{8}$/', $issuedExamNumber)) {
                    $errors[] = '수험번호는 8자리 숫자로 입력해주세요.';
                } else {
                    $existingSession = findExamSession(
                        $pdo,
                        $issuedExamNumber,
                        $exam['level'],
                        $exam['certification'],
                        $exam['round'],
                        $electiveSubjectId,
                        $electiveSubject
                    );

                    if ($existingSession !== null && $existingSession['applicant_name'] !== $applicantName) {
                        $errors[] = '수험성명과 수험번호가 일치하지 않습니다.';
                    } elseif ($existingSession === null && examNumberExists($pdo, $issuedExamNumber)) {
                        $errors[] = '이미 다른 시험에서 사용 중인 수험번호입니다.';
                    } elseif ($existingSession === null) {
                        $errors[] = '입력하신 수험번호의 저장된 시험 정보가 없습니다. 새 시험을 시작하려면 수험번호를 비워두세요.';
                    }
                }
            } else {
                $issuedExamNumber = issueExamNumber($pdo);
                createExamSession($pdo, $issuedExamNumber, $applicantName, $exam, $electiveSubjectId, $electiveSubject);
            }

            if ($errors) {
                throw new RuntimeException('입력값을 다시 확인해주세요.');
            }

            $query = http_build_query([
                'level' => $exam['level'],
                'certification' => $exam['certification'],
                'round' => $exam['round'],
                'applicant_name' => $applicantName,
                'exam_number' => $issuedExamNumber,
                'elective_subject_id' => $electiveSubjectId,
                'elective_subject' => $electiveSubject,
            ]);

            header('Location: main.php?' . $query);
            exit;
        } catch (Throwable $error) {
            if (!$errors) {
                $errors[] = '시험 진행 정보를 DB에 저장하지 못했습니다: ' . $error->getMessage();
            }
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT 시험 선택</title>
    <link rel="stylesheet" href="style/cbt_start.css">
</head>
<body>
    <section class="start-panel">
        <header class="panel-header">
            <div class="test-icon" aria-hidden="true"></div>
            <div>
                <h1 class="panel-title">CBT 시험 선택</h1>
                <p class="panel-subtitle">시험과 수험자 정보를 입력해주세요.</p>
            </div>
        </header>

        <form method="post" action="index.php">
            <?php if ($errors): ?>
                <div class="errors">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="category">시험 분류</label>
                <select id="category" name="category" required>
                    <option value="">선택하세요</option>
                    <?php foreach (array_keys($examCatalog) as $category): ?>
                        <option value="<?= e($category) ?>"<?= $category === $selectedCategory ? ' selected' : '' ?>>
                            <?= e($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="exam">시험 선택</label>
                <select id="exam" name="exam" required></select>
                <div id="exam-summary" class="exam-summary" aria-live="polite"></div>
            </div>

            <div class="field" id="elective-field" hidden>
                <label for="elective_subject">선택과목</label>
                <select id="elective_subject" name="elective_subject_id"></select>
            </div>

            <div class="row">
                <div class="field">
                    <label for="applicant_name">수험성명</label>
                    <input id="applicant_name" type="text" name="applicant_name" placeholder="수험성명" value="<?= e($applicantName) ?>" required>
                </div>
                <div class="field">
                    <label for="exam_number">수험번호</label>
                    <div class="input-with-clear">
                        <input id="exam_number" type="text" name="exam_number" placeholder="수험번호" value="<?= e($rawExamNumber) ?>" title="새 시험을 시작하려면 수험번호를 비워두세요. 이전 시험을 계속 풀거나 검토하려면 이전에 받은 8자리 수험번호를 입력하세요.">
                        <button class="clear-input" type="button" aria-label="수험번호 지우기" title="수험번호 지우기">&times;</button>
                    </div>
                </div>
            </div>
            <div class="help-text">
                새 시험을 시작하려면 수험번호를 비워두세요. 이전 시험을 계속 풀거나 검토하려면 이전에 받은 8자리 수험번호를 입력하세요.
            </div>

            <button type="submit">시험시작</button>
        </form>
    </section>

    <script id="cbt-start-data" type="application/json"><?= json_encode([
        'examCatalog' => $examCatalog,
        'selectedExam' => $selectedExam,
        'selectedElectiveSubjectId' => $selectedElectiveSubjectId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="js/cbt_start.js" defer></script>
</body>
</html>
