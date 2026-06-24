<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/database.php';

$sessionPath = __DIR__ . '/data/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_start();

if (empty($_SESSION['cbt_admin_logged_in'])) {
    header('Location: index.php?admin_required=1');
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function openImportDb(): PDO
{
    return cbtOpenDatabase();
}

function catalog(PDO $pdo): array
{
    $levels = [];
    foreach ($pdo->query('SELECT id, name, slug FROM qualification_levels ORDER BY id') as $level) {
        $level['certifications'] = [];
        $levels[(int) $level['id']] = $level;
    }

    $rows = $pdo->query('SELECT
            c.id AS certification_id,
            c.level_id,
            c.name AS certification_name,
            c.slug AS certification_slug,
            r.id AS round_id,
            r.round_name,
            r.slug AS round_slug,
            r.time_limit_minutes
        FROM certifications c
        LEFT JOIN exam_rounds r ON r.certification_id = c.id
        ORDER BY c.level_id, c.id, r.id')->fetchAll();

    foreach ($rows as $row) {
        $levelId = (int) $row['level_id'];
        if (!isset($levels[$levelId])) {
            continue;
        }

        $certificationId = (int) $row['certification_id'];
        $levels[$levelId]['certifications'][$certificationId] ??= [
            'id' => $certificationId,
            'name' => (string) $row['certification_name'],
            'slug' => (string) ($row['certification_slug'] ?? ''),
            'rounds' => [],
        ];

        if ($row['round_id'] !== null) {
            $levels[$levelId]['certifications'][$certificationId]['rounds'][] = [
                'id' => (int) $row['round_id'],
                'name' => (string) $row['round_name'],
                'slug' => (string) ($row['round_slug'] ?? ''),
                'timeLimitMinutes' => (int) $row['time_limit_minutes'],
                'subjects' => [],
            ];
        }
    }

    $subjectsByRound = [];
    foreach ($pdo->query('SELECT id, round_id, name, slug, subject_type, elective_group,
            question_start, question_end, question_count, display_order, active
        FROM exam_subjects ORDER BY round_id, display_order, id') as $subject) {
        $subjectsByRound[(int) $subject['round_id']][] = [
            'id' => (int) $subject['id'],
            'name' => (string) $subject['name'],
            'slug' => (string) $subject['slug'],
            'type' => (string) $subject['subject_type'],
            'electiveGroup' => (string) $subject['elective_group'],
            'questionStart' => (int) $subject['question_start'],
            'questionEnd' => (int) $subject['question_end'],
            'questionCount' => (int) $subject['question_count'],
            'displayOrder' => (int) $subject['display_order'],
            'active' => (bool) $subject['active'],
        ];
    }

    foreach ($levels as &$level) {
        foreach ($level['certifications'] as &$certification) {
            foreach ($certification['rounds'] as &$round) {
                $round['subjects'] = $subjectsByRound[(int) $round['id']] ?? [];
            }
            unset($round);
        }
        unset($certification);
        $level['certifications'] = array_values($level['certifications']);
    }
    unset($level);

    return array_values($levels);
}

function requireSlug(string $value, string $label): string
{
    $value = trim($value);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
        throw new RuntimeException($label . '은 영문 소문자, 숫자, 하이픈만 사용할 수 있습니다.');
    }

    return $value;
}

function requireText(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException($label . '을(를) 입력해주세요.');
    }

    return $value;
}

function trimBom(string $value): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function csvAsUtf8(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('CSV 파일을 읽을 수 없습니다.');
    }

    if (mb_check_encoding($contents, 'UTF-8')) {
        return $contents;
    }

    foreach (['CP949', 'EUC-KR'] as $encoding) {
        $converted = @iconv($encoding, 'UTF-8//IGNORE', $contents);
        if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
    }

    throw new RuntimeException('CSV 인코딩을 확인해주세요. UTF-8 또는 CP949 CSV를 사용할 수 있습니다.');
}

function normalizeHeader(string $header): string
{
    $header = trim(trimBom($header));
    $aliases = [
        '문제번호' => 'question_number', '문항번호' => 'question_number', '번호' => 'question_number',
        '과목' => 'subject', '과목명' => 'subject',
        '문제' => 'question_text', '문제지문' => 'question_text', '문항' => 'question_text',
        '문제이미지' => 'question_image', '문제 이미지' => 'question_image',
        '문제이미지너비' => 'question_image_width_percent', '문제 이미지 너비' => 'question_image_width_percent',
        '보기1' => 'option_1_text', '1번보기' => 'option_1_text', '보기 1' => 'option_1_text',
        '보기2' => 'option_2_text', '2번보기' => 'option_2_text', '보기 2' => 'option_2_text',
        '보기3' => 'option_3_text', '3번보기' => 'option_3_text', '보기 3' => 'option_3_text',
        '보기4' => 'option_4_text', '4번보기' => 'option_4_text', '보기 4' => 'option_4_text',
        '보기1이미지' => 'option_1_image', '보기1 이미지' => 'option_1_image',
        '보기2이미지' => 'option_2_image', '보기2 이미지' => 'option_2_image',
        '보기3이미지' => 'option_3_image', '보기3 이미지' => 'option_3_image',
        '보기4이미지' => 'option_4_image', '보기4 이미지' => 'option_4_image',
        '보기1이미지너비' => 'option_1_image_width_percent', '보기1 이미지 너비' => 'option_1_image_width_percent',
        '보기2이미지너비' => 'option_2_image_width_percent', '보기2 이미지 너비' => 'option_2_image_width_percent',
        '보기3이미지너비' => 'option_3_image_width_percent', '보기3 이미지 너비' => 'option_3_image_width_percent',
        '보기4이미지너비' => 'option_4_image_width_percent', '보기4 이미지 너비' => 'option_4_image_width_percent',
        '정답' => 'answer',
        '해설' => 'explanation_text',
        '해설이미지' => 'explanation_image', '해설 이미지' => 'explanation_image',
        '해설이미지너비' => 'explanation_image_width_percent', '해설 이미지 너비' => 'explanation_image_width_percent',
    ];

    return $aliases[$header] ?? $header;
}

function nullableText(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullablePercent(mixed $value, int $line, string $column): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value) || (int) $value < 10 || (int) $value > 100) {
        throw new RuntimeException("CSV {$line}행의 {$column} 값은 10~100 사이 정수여야 합니다.");
    }

    return (int) $value;
}

function normalizeImagePath(mixed $value, string $baseWebPath, string $directory): ?string
{
    $value = trim(str_replace('\\', '/', (string) $value));
    if ($value === '') {
        return null;
    }

    if (str_starts_with($value, 'images/')) {
        return $value;
    }

    if (preg_match('#^(questions|options|explanations)/#', $value)) {
        return $baseWebPath . '/' . $value;
    }

    return $baseWebPath . '/' . $directory . '/' . basename($value);
}

function parseCsv(string $path, string $baseWebPath): array
{
    $stream = fopen('php://temp', 'rb+');
    if ($stream === false) {
        throw new RuntimeException('CSV 임시 스트림을 만들 수 없습니다.');
    }
    fwrite($stream, csvAsUtf8($path));
    rewind($stream);

    $headerRow = fgetcsv($stream);
    if ($headerRow === false) {
        throw new RuntimeException('CSV 파일이 비어 있습니다.');
    }
    $headers = array_map(static fn ($header) => normalizeHeader((string) $header), $headerRow);
    $required = ['question_number', 'subject', 'question_text', 'option_1_text', 'option_2_text', 'option_3_text', 'option_4_text', 'answer'];
    foreach ($required as $column) {
        if (!in_array($column, $headers, true)) {
            throw new RuntimeException('필수 CSV 컬럼이 없습니다: ' . $column . ' / 감지된 컬럼: ' . implode(', ', $headers));
        }
    }

    $questions = [];
    $seen = [];
    $line = 1;
    while (($row = fgetcsv($stream)) !== false) {
        $line++;
        if (count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
        $data = array_combine($headers, $row);
        if ($data === false) {
            throw new RuntimeException("CSV {$line}행 형식이 올바르지 않습니다.");
        }

        $number = (int) trim((string) $data['question_number']);
        $subject = requireText((string) $data['subject'], "CSV {$line}행 과목");
        $answer = (int) trim((string) $data['answer']);
        if ($number <= 0 || $answer < 1 || $answer > 4) {
            throw new RuntimeException("CSV {$line}행의 문제번호 또는 정답을 확인해주세요.");
        }

        $key = $number . '|' . $subject;
        if (isset($seen[$key])) {
            throw new RuntimeException("CSV {$line}행에 {$number}번 {$subject} 문제가 중복되어 있습니다.");
        }
        $seen[$key] = true;

        $questions[] = [
            'question_number' => $number,
            'subject' => $subject,
            'question_text' => requireText((string) $data['question_text'], "CSV {$line}행 문제"),
            'question_image' => normalizeImagePath($data['question_image'] ?? null, $baseWebPath, 'questions'),
            'question_image_width_percent' => nullablePercent($data['question_image_width_percent'] ?? null, $line, '문제이미지너비'),
            'option_1_text' => nullableText($data['option_1_text'] ?? null),
            'option_1_image' => normalizeImagePath($data['option_1_image'] ?? null, $baseWebPath, 'options'),
            'option_1_image_width_percent' => nullablePercent($data['option_1_image_width_percent'] ?? null, $line, '보기1이미지너비'),
            'option_2_text' => nullableText($data['option_2_text'] ?? null),
            'option_2_image' => normalizeImagePath($data['option_2_image'] ?? null, $baseWebPath, 'options'),
            'option_2_image_width_percent' => nullablePercent($data['option_2_image_width_percent'] ?? null, $line, '보기2이미지너비'),
            'option_3_text' => nullableText($data['option_3_text'] ?? null),
            'option_3_image' => normalizeImagePath($data['option_3_image'] ?? null, $baseWebPath, 'options'),
            'option_3_image_width_percent' => nullablePercent($data['option_3_image_width_percent'] ?? null, $line, '보기3이미지너비'),
            'option_4_text' => nullableText($data['option_4_text'] ?? null),
            'option_4_image' => normalizeImagePath($data['option_4_image'] ?? null, $baseWebPath, 'options'),
            'option_4_image_width_percent' => nullablePercent($data['option_4_image_width_percent'] ?? null, $line, '보기4이미지너비'),
            'answer' => $answer,
            'explanation_text' => nullableText($data['explanation_text'] ?? null),
            'explanation_image' => normalizeImagePath($data['explanation_image'] ?? null, $baseWebPath, 'explanations'),
            'explanation_image_width_percent' => nullablePercent($data['explanation_image_width_percent'] ?? null, $line, '해설이미지너비'),
        ];
    }
    fclose($stream);

    if (!$questions) {
        throw new RuntimeException('등록할 문제가 없습니다.');
    }

    return $questions;
}

function preparedZipFiles(?array $upload): array
{
    if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('이미지 ZIP 업로드에 실패했습니다.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('서버에서 ZIP 압축 해제를 지원하지 않습니다.');
    }

    $zip = new ZipArchive();
    if ($zip->open((string) $upload['tmp_name']) !== true) {
        throw new RuntimeException('ZIP 파일을 열 수 없습니다.');
    }

    $allowedDirectories = ['questions', 'options', 'explanations'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $files = [];
    $totalSize = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, '../')) {
            continue;
        }

        $parts = array_values(array_filter(explode('/', trim($name, '/')), static fn ($part) => $part !== ''));
        $directoryIndex = null;
        foreach ($parts as $index => $part) {
            if (in_array($part, $allowedDirectories, true)) {
                $directoryIndex = $index;
                break;
            }
        }
        if ($directoryIndex === null) {
            continue;
        }

        $relativeParts = array_slice($parts, $directoryIndex);
        $relativePath = implode('/', $relativeParts);
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('ZIP에 허용되지 않은 파일이 있습니다: ' . $name);
        }

        $size = (int) ($stat['size'] ?? 0);
        $totalSize += $size;
        if ($size > 10 * 1024 * 1024 || $totalSize > 50 * 1024 * 1024) {
            throw new RuntimeException('ZIP 이미지 용량 제한을 초과했습니다.');
        }

        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            throw new RuntimeException('ZIP 내부 파일을 읽을 수 없습니다: ' . $name);
        }
        $files[$relativePath] = $contents;
    }
    $zip->close();

    return $files;
}

function writeImageFiles(array $files, string $baseDirectory): int
{
    foreach (['questions', 'options', 'explanations'] as $directory) {
        $path = $baseDirectory . '/' . $directory;
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('이미지 폴더를 만들 수 없습니다: ' . $path);
        }
    }

    $written = 0;
    foreach ($files as $relativePath => $contents) {
        $destination = $baseDirectory . '/' . $relativePath;
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('이미지 하위 폴더를 만들 수 없습니다.');
        }
        if (file_put_contents($destination, $contents, LOCK_EX) === false) {
            throw new RuntimeException('이미지를 저장할 수 없습니다: ' . $relativePath);
        }
        $written++;
    }

    return $written;
}

function createBackup(PDO $pdo): string
{
    $directory = __DIR__ . '/data/backups';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('DB 백업 폴더를 만들 수 없습니다.');
    }

    $suffix = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    if (!cbtIsMariaDb($pdo)) {
        $config = cbtDatabaseConfig();
        $source = (string) ($config['sqlite']['path'] ?? __DIR__ . '/cbt_exam.db');
        $filename = 'cbt_exam-' . $suffix . '.db';
        if (!copy($source, $directory . '/' . $filename)) {
            throw new RuntimeException('DB 백업을 만들 수 없습니다.');
        }
        return 'data/backups/' . $filename;
    }

    $backup = [];
    foreach (['qualification_levels', 'certifications', 'exam_rounds', 'exam_subjects', 'exam_questions', 'exam_sessions'] as $table) {
        $backup[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    }
    $filename = 'cbt_data-' . $suffix . '.json';
    $json = json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($directory . '/' . $filename, $json, LOCK_EX) === false) {
        throw new RuntimeException('MariaDB 데이터 백업을 만들 수 없습니다.');
    }
    return 'data/backups/' . $filename;
}

$pdo = openImportDb();
$errors = [];
$success = null;

if (!isset($_SESSION['cbt_admin_csrf'])) {
    $_SESSION['cbt_admin_csrf'] = bin2hex(random_bytes(24));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string) $_SESSION['cbt_admin_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('요청 확인에 실패했습니다. 페이지를 새로고침해주세요.');
        }

        $levelId = (int) ($_POST['level_id'] ?? 0);
        $certificationId = (int) ($_POST['certification_id'] ?? 0);
        $roundId = (int) ($_POST['round_id'] ?? 0);
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $levelSlug = requireSlug((string) ($_POST['level_slug'] ?? ''), '분류 폴더명');
        $certificationSlug = requireSlug((string) ($_POST['certification_slug'] ?? ''), '시험 폴더명');
        $roundSlug = requireSlug((string) ($_POST['round_slug'] ?? ''), '회차 폴더명');
        $subjectSlug = requireSlug((string) ($_POST['subject_slug'] ?? ''), '과목 폴더명');
        $subjectType = (string) ($_POST['subject_type'] ?? 'common');
        $electiveGroup = trim((string) ($_POST['elective_group'] ?? ''));
        $questionStart = (int) ($_POST['question_start'] ?? 0);
        $questionEnd = (int) ($_POST['question_end'] ?? 0);
        $questionCount = (int) ($_POST['question_count'] ?? 0);
        $timeLimit = (int) ($_POST['time_limit_minutes'] ?? 0);
        $importMode = (string) ($_POST['import_mode'] ?? 'upsert');

        if ($timeLimit <= 0 || $timeLimit > 600) {
            throw new RuntimeException('제한시간은 1~600분 사이로 입력해주세요.');
        }
        if (!in_array($importMode, ['upsert', 'replace'], true)) {
            throw new RuntimeException('등록 방식을 확인해주세요.');
        }
        if (!in_array($subjectType, ['common', 'elective'], true)) {
            throw new RuntimeException('과목 유형을 확인해주세요.');
        }
        if ($subjectType === 'elective') {
            $electiveGroup = requireSlug($electiveGroup !== '' ? $electiveGroup : 'elective-1', '선택과목 그룹');
        } else {
            $electiveGroup = '';
        }
        if ($questionStart <= 0 || $questionEnd < $questionStart || $questionCount <= 0) {
            throw new RuntimeException('과목 문제 범위와 문항 수를 확인해주세요.');
        }
        if ($importMode === 'replace' && empty($_POST['confirm_replace'])) {
            throw new RuntimeException('선택한 과목 교체 확인 항목을 체크해주세요.');
        }
        if (!isset($_FILES['questions_csv']) || (int) $_FILES['questions_csv']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('문제 CSV 파일을 선택해주세요.');
        }

        if ($levelId > 0) {
            $stmt = $pdo->prepare('SELECT id, name FROM qualification_levels WHERE id = :id');
            $stmt->execute([':id' => $levelId]);
            $level = $stmt->fetch();
            if (!$level) {
                throw new RuntimeException('시험 분류를 찾을 수 없습니다.');
            }
            $levelName = (string) $level['name'];
        } else {
            $levelName = requireText((string) ($_POST['new_level_name'] ?? ''), '새 시험 분류명');
            $stmt = $pdo->prepare('SELECT 1 FROM qualification_levels WHERE name = :name OR slug = :slug LIMIT 1');
            $stmt->execute([':name' => $levelName, ':slug' => $levelSlug]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('같은 이름 또는 폴더명의 시험 분류가 이미 있습니다. 기존 분류를 선택해주세요.');
            }
        }

        $stmt = $pdo->prepare('SELECT 1 FROM qualification_levels WHERE slug = :slug AND id <> :id LIMIT 1');
        $stmt->execute([':slug' => $levelSlug, ':id' => $levelId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('다른 시험 분류에서 사용 중인 분류 폴더명입니다.');
        }

        if ($certificationId > 0) {
            $stmt = $pdo->prepare('SELECT id, name, level_id FROM certifications WHERE id = :id AND level_id = :level_id');
            $stmt->execute([':id' => $certificationId, ':level_id' => $levelId]);
            $certification = $stmt->fetch();
            if (!$certification) {
                throw new RuntimeException('시험을 찾을 수 없습니다.');
            }
            $certificationName = (string) $certification['name'];
        } else {
            $certificationName = requireText((string) ($_POST['new_certification_name'] ?? ''), '새 시험명');
            if ($levelId > 0) {
                $stmt = $pdo->prepare('SELECT 1 FROM certifications
                    WHERE level_id = :level_id AND (name = :name OR slug = :slug) LIMIT 1');
                $stmt->execute([':level_id' => $levelId, ':name' => $certificationName, ':slug' => $certificationSlug]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('같은 이름 또는 폴더명의 시험이 이미 있습니다. 기존 시험을 선택해주세요.');
                }
            }
        }

        if ($levelId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM certifications
                WHERE level_id = :level_id AND slug = :slug AND id <> :id LIMIT 1');
            $stmt->execute([':level_id' => $levelId, ':slug' => $certificationSlug, ':id' => $certificationId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('같은 시험 분류에서 사용 중인 시험 폴더명입니다.');
            }
        }

        if ($roundId > 0) {
            $stmt = $pdo->prepare('SELECT id, round_name, certification_id FROM exam_rounds WHERE id = :id AND certification_id = :certification_id');
            $stmt->execute([':id' => $roundId, ':certification_id' => $certificationId]);
            $round = $stmt->fetch();
            if (!$round) {
                throw new RuntimeException('시험 회차를 찾을 수 없습니다.');
            }
            $roundName = (string) $round['round_name'];
        } else {
            $roundName = requireText((string) ($_POST['new_round_name'] ?? ''), '새 회차명');
            if ($certificationId > 0) {
                $stmt = $pdo->prepare('SELECT 1 FROM exam_rounds
                    WHERE certification_id = :certification_id AND (round_name = :name OR slug = :slug) LIMIT 1');
                $stmt->execute([':certification_id' => $certificationId, ':name' => $roundName, ':slug' => $roundSlug]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('같은 이름 또는 폴더명의 회차가 이미 있습니다. 기존 회차를 선택해주세요.');
                }
            }
        }

        if ($certificationId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM exam_rounds
                WHERE certification_id = :certification_id AND slug = :slug AND id <> :id LIMIT 1');
            $stmt->execute([':certification_id' => $certificationId, ':slug' => $roundSlug, ':id' => $roundId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('같은 시험에서 사용 중인 회차 폴더명입니다.');
            }
        }

        if ($subjectId > 0) {
            $stmt = $pdo->prepare('SELECT id, name, round_id FROM exam_subjects WHERE id = :id AND round_id = :round_id');
            $stmt->execute([':id' => $subjectId, ':round_id' => $roundId]);
            $subject = $stmt->fetch();
            if (!$subject) {
                throw new RuntimeException('등록 대상 과목을 찾을 수 없습니다.');
            }
            $subjectName = (string) $subject['name'];
        } else {
            $subjectName = requireText((string) ($_POST['new_subject_name'] ?? ''), '새 과목명');
            if ($roundId > 0) {
                $stmt = $pdo->prepare('SELECT 1 FROM exam_subjects
                    WHERE round_id = :round_id AND (name = :name OR slug = :slug) LIMIT 1');
                $stmt->execute([':round_id' => $roundId, ':name' => $subjectName, ':slug' => $subjectSlug]);
                if ($stmt->fetchColumn()) {
                    throw new RuntimeException('같은 이름 또는 폴더명의 과목이 이미 있습니다. 기존 과목을 선택해주세요.');
                }
            }
        }

        if ($roundId > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM exam_subjects
                WHERE round_id = :round_id AND slug = :slug AND id <> :id LIMIT 1');
            $stmt->execute([':round_id' => $roundId, ':slug' => $subjectSlug, ':id' => $subjectId]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('같은 회차에서 사용 중인 과목 폴더명입니다.');
            }

            if ($subjectType === 'elective') {
                $groupStmt = $pdo->prepare('SELECT question_start, question_end, question_count
                    FROM exam_subjects
                    WHERE round_id = :round_id AND subject_type = \'elective\'
                    AND elective_group = :elective_group AND id <> :id
                    ORDER BY id LIMIT 1');
                $groupStmt->execute([
                    ':round_id' => $roundId,
                    ':elective_group' => $electiveGroup,
                    ':id' => $subjectId,
                ]);
                $groupSubject = $groupStmt->fetch();
                if ($groupSubject && (
                    (int) $groupSubject['question_start'] !== $questionStart
                    || (int) $groupSubject['question_end'] !== $questionEnd
                    || (int) $groupSubject['question_count'] !== $questionCount
                )) {
                    throw new RuntimeException('같은 선택과목 그룹은 문제 범위와 문항 수가 같아야 합니다.');
                }
            }
        }

        $baseWebPath = 'images/exams/' . $levelSlug . '/' . $certificationSlug . '/' . $roundSlug;
        $subjectWebPath = $baseWebPath . '/subjects/' . $subjectSlug;
        $subjectDirectory = __DIR__ . '/' . $subjectWebPath;
        $questions = parseCsv((string) $_FILES['questions_csv']['tmp_name'], $subjectWebPath);
        foreach ($questions as $question) {
            if ($question['subject'] !== $subjectName) {
                throw new RuntimeException('CSV 과목명은 선택한 등록 대상 과목명과 모두 같아야 합니다: ' . $subjectName);
            }
            if ($question['question_number'] < $questionStart || $question['question_number'] > $questionEnd) {
                throw new RuntimeException("{$subjectName} 문제번호는 {$questionStart}~{$questionEnd} 범위여야 합니다.");
            }
        }
        if (($subjectId <= 0 || $importMode === 'replace') && count($questions) !== $questionCount) {
            throw new RuntimeException("{$subjectName}은(는) {$questionCount}문제를 등록해야 합니다.");
        }
        $zipFiles = preparedZipFiles($_FILES['images_zip'] ?? null);
        $backupPath = createBackup($pdo);

        $pdo->beginTransaction();
        try {
            if ($levelId > 0) {
                $stmt = $pdo->prepare('UPDATE qualification_levels SET slug = :slug WHERE id = :id');
                $stmt->execute([':slug' => $levelSlug, ':id' => $levelId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO qualification_levels (name, slug) VALUES (:name, :slug)');
                $stmt->execute([':name' => $levelName, ':slug' => $levelSlug]);
                $levelId = (int) $pdo->lastInsertId();
            }

            if ($certificationId > 0) {
                $stmt = $pdo->prepare('UPDATE certifications SET slug = :slug WHERE id = :id');
                $stmt->execute([':slug' => $certificationSlug, ':id' => $certificationId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO certifications (level_id, name, slug) VALUES (:level_id, :name, :slug)');
                $stmt->execute([':level_id' => $levelId, ':name' => $certificationName, ':slug' => $certificationSlug]);
                $certificationId = (int) $pdo->lastInsertId();
            }

            if ($roundId > 0) {
                $stmt = $pdo->prepare('UPDATE exam_rounds SET slug = :slug, time_limit_minutes = :time WHERE id = :id');
                $stmt->execute([':slug' => $roundSlug, ':time' => $timeLimit, ':id' => $roundId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO exam_rounds (certification_id, round_name, time_limit_minutes, slug)
                    VALUES (:certification_id, :round_name, :time, :slug)');
                $stmt->execute([
                    ':certification_id' => $certificationId,
                    ':round_name' => $roundName,
                    ':time' => $timeLimit,
                    ':slug' => $roundSlug,
                ]);
                $roundId = (int) $pdo->lastInsertId();
            }

            if ($subjectId > 0) {
                $stmt = $pdo->prepare('UPDATE exam_subjects SET
                    slug = :slug, subject_type = :subject_type, elective_group = :elective_group,
                    question_start = :question_start, question_end = :question_end,
                    question_count = :question_count, active = 1
                    WHERE id = :id');
                $stmt->execute([
                    ':slug' => $subjectSlug,
                    ':subject_type' => $subjectType,
                    ':elective_group' => $electiveGroup,
                    ':question_start' => $questionStart,
                    ':question_end' => $questionEnd,
                    ':question_count' => $questionCount,
                    ':id' => $subjectId,
                ]);
            } else {
                $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM exam_subjects WHERE round_id = :round_id');
                $orderStmt->execute([':round_id' => $roundId]);
                $displayOrder = (int) $orderStmt->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO exam_subjects (
                    round_id, name, slug, subject_type, elective_group,
                    question_start, question_end, question_count, display_order, active
                ) VALUES (
                    :round_id, :name, :slug, :subject_type, :elective_group,
                    :question_start, :question_end, :question_count, :display_order, 1
                )');
                $stmt->execute([
                    ':round_id' => $roundId,
                    ':name' => $subjectName,
                    ':slug' => $subjectSlug,
                    ':subject_type' => $subjectType,
                    ':elective_group' => $electiveGroup,
                    ':question_start' => $questionStart,
                    ':question_end' => $questionEnd,
                    ':question_count' => $questionCount,
                    ':display_order' => $displayOrder,
                ]);
                $subjectId = (int) $pdo->lastInsertId();
            }

            if ($importMode === 'replace') {
                $delete = $pdo->prepare('DELETE FROM exam_questions
                    WHERE round_id = :round_id AND (subject_id = :subject_id OR (subject_id IS NULL AND subject = :subject))');
                $delete->execute([':round_id' => $roundId, ':subject_id' => $subjectId, ':subject' => $subjectName]);
            }

            $insert = $pdo->prepare('REPLACE INTO exam_questions (
                    round_id, subject_id, question_number, subject, question_text,
                    question_image, question_image_width_percent,
                    option_1_text, option_1_image, option_1_image_width_percent,
                    option_2_text, option_2_image, option_2_image_width_percent,
                    option_3_text, option_3_image, option_3_image_width_percent,
                    option_4_text, option_4_image, option_4_image_width_percent,
                    answer, explanation_text, explanation_image, explanation_image_width_percent
                ) VALUES (
                    :round_id, :subject_id, :question_number, :subject, :question_text,
                    :question_image, :question_image_width_percent,
                    :option_1_text, :option_1_image, :option_1_image_width_percent,
                    :option_2_text, :option_2_image, :option_2_image_width_percent,
                    :option_3_text, :option_3_image, :option_3_image_width_percent,
                    :option_4_text, :option_4_image, :option_4_image_width_percent,
                    :answer, :explanation_text, :explanation_image, :explanation_image_width_percent
                )');

            foreach ($questions as $question) {
                $insert->execute([':round_id' => $roundId, ':subject_id' => $subjectId] + array_combine(
                    array_map(static fn ($key) => ':' . $key, array_keys($question)),
                    array_values($question)
                ));
            }

            $imageCount = writeImageFiles($zipFiles, $subjectDirectory);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        $success = [
            'message' => count($questions) . '문제를 등록했습니다.',
            'imageCount' => $imageCount,
            'path' => $subjectWebPath,
            'backup' => $backupPath,
        ];
        $_POST = [];
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
}

$examCatalog = catalog($pdo);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT 시험 및 문제 등록</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style/admin_questions.css?v=20260619-2">
    <link rel="stylesheet" href="style/admin_import.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard-dynamic-subset.css" crossorigin>
</head>
<body>
    <div id="admin-app">
        <header class="admin-header">
            <div class="header-left">
                <div class="test-icon" aria-hidden="true"></div>
                <div>
                    <h1>CBT 시험 및 문제 등록</h1>
                    <p>시험 정보와 영문 폴더명을 정하고 CSV 및 이미지 ZIP을 등록하세요.</p>
                </div>
            </div>
            <div class="header-actions">
                <a class="home-link" href="admin_questions.php">문제 수정</a>
                <a class="home-link" href="index.php">시험 선택으로</a>
                <a class="home-link" href="index.php?admin_logout=1">로그아웃</a>
            </div>
        </header>

        <main class="import-layout">
            <?php if ($errors): ?>
                <div class="notice error-notice">
                    <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="notice success-notice">
                    <strong><?= e($success['message']) ?></strong>
                    <p>이미지 <?= (int) $success['imageCount'] ?>개 · 폴더 <?= e($success['path']) ?></p>
                    <p>DB 백업: <?= e($success['backup']) ?></p>
                </div>
            <?php endif; ?>

            <form class="import-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e((string) $_SESSION['cbt_admin_csrf']) ?>">

                <section class="form-section">
                    <div class="section-heading">
                        <span>1</span>
                        <div><h2>시험 정보</h2><p>기존 항목을 선택하거나 새 항목을 생성합니다.</p></div>
                    </div>

                    <div class="form-grid">
                        <label>시험 분류
                            <select id="level-id" name="level_id" required></select>
                        </label>
                        <label id="new-level-field" hidden>새 시험 분류명
                            <input id="new-level-name" name="new_level_name" type="text" value="<?= e((string) ($_POST['new_level_name'] ?? '')) ?>">
                        </label>
                        <label>분류 폴더명
                            <input id="level-slug" name="level_slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="gineungsa" required>
                        </label>

                        <label>시험
                            <select id="certification-id" name="certification_id" required></select>
                        </label>
                        <label id="new-certification-field" hidden>새 시험명
                            <input id="new-certification-name" name="new_certification_name" type="text" value="<?= e((string) ($_POST['new_certification_name'] ?? '')) ?>">
                        </label>
                        <label>시험 폴더명
                            <input id="certification-slug" name="certification_slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="welding" required>
                        </label>

                        <label>회차
                            <select id="round-id" name="round_id" required></select>
                        </label>
                        <label id="new-round-field" hidden>새 회차명
                            <input id="new-round-name" name="new_round_name" type="text" value="<?= e((string) ($_POST['new_round_name'] ?? '1회')) ?>">
                        </label>
                        <label>회차 폴더명
                            <input id="round-slug" name="round_slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="round-01" required>
                        </label>
                        <label>제한시간(분)
                            <input id="time-limit" name="time_limit_minutes" type="number" min="1" max="600" value="<?= e((string) ($_POST['time_limit_minutes'] ?? '60')) ?>" required>
                        </label>

                        <label>등록 대상 과목
                            <select id="subject-id" name="subject_id" required></select>
                        </label>
                        <label id="new-subject-field" hidden>새 과목명
                            <input id="new-subject-name" name="new_subject_name" type="text" value="<?= e((string) ($_POST['new_subject_name'] ?? '')) ?>">
                        </label>
                        <label>과목 폴더명
                            <input id="subject-slug" name="subject_slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="railway-law" required>
                        </label>
                        <label>과목 유형
                            <select id="subject-type" name="subject_type" required>
                                <option value="common">공통과목</option>
                                <option value="elective">선택과목</option>
                            </select>
                        </label>
                        <label id="elective-group-field" hidden>선택과목 그룹
                            <input id="elective-group" name="elective_group" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= e((string) ($_POST['elective_group'] ?? 'elective-1')) ?>" placeholder="elective-1">
                        </label>
                        <label>시작 문제번호
                            <input id="question-start" name="question_start" type="number" min="1" value="<?= e((string) ($_POST['question_start'] ?? '1')) ?>" required>
                        </label>
                        <label>종료 문제번호
                            <input id="question-end" name="question_end" type="number" min="1" value="<?= e((string) ($_POST['question_end'] ?? '60')) ?>" required>
                        </label>
                        <label>과목 문항 수
                            <input id="question-count" name="question_count" type="number" min="1" value="<?= e((string) ($_POST['question_count'] ?? '60')) ?>" required>
                        </label>
                    </div>

                    <div class="path-preview">
                        <span>생성될 이미지 경로</span>
                        <code id="path-preview">images/exams/</code>
                    </div>
                    <p class="field-warning">기존 항목의 폴더명을 변경해도 이전 이미지 파일은 자동 이동하지 않습니다.</p>
                </section>

                <section class="form-section">
                    <div class="section-heading">
                        <span>2</span>
                        <div><h2>문제 및 이미지</h2><p>CSV는 필수이며 이미지 ZIP은 선택입니다.</p></div>
                    </div>

                    <div class="upload-grid">
                        <label class="upload-field">문제 CSV
                            <input name="questions_csv" type="file" accept=".csv,text/csv" required>
                            <small>question_import_template_excel.csv 형식</small>
                        </label>
                        <label class="upload-field">이미지 ZIP (선택)
                            <input name="images_zip" type="file" accept=".zip,application/zip">
                            <small>questions / options / explanations 폴더 포함</small>
                        </label>
                    </div>
                </section>

                <section class="form-section">
                    <div class="section-heading">
                        <span>3</span>
                        <div><h2>등록 방식</h2><p>등록 전에 DB 백업이 자동 생성됩니다.</p></div>
                    </div>

                    <div class="mode-options">
                        <label><input type="radio" name="import_mode" value="upsert" checked> 추가 또는 갱신</label>
                        <label><input type="radio" name="import_mode" value="replace"> 선택한 과목 전체 교체</label>
                    </div>
                    <label class="replace-confirm" id="replace-confirm" hidden>
                        <input type="checkbox" name="confirm_replace" value="1">
                        선택한 과목의 기존 문제를 모두 삭제하고 교체하는 것을 확인했습니다.
                    </label>
                </section>

                <div class="submit-row">
                    <button type="submit">시험 및 문제 등록</button>
                </div>
            </form>
        </main>
    </div>

    <script id="admin-import-data" type="application/json"><?= json_encode([
        'catalog' => $examCatalog,
        'wasPosted' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $success === null,
        'posted' => [
            'levelId' => (int) ($_POST['level_id'] ?? 0),
            'certificationId' => (int) ($_POST['certification_id'] ?? 0),
            'roundId' => (int) ($_POST['round_id'] ?? 0),
            'subjectId' => (int) ($_POST['subject_id'] ?? 0),
            'levelSlug' => (string) ($_POST['level_slug'] ?? ''),
            'certificationSlug' => (string) ($_POST['certification_slug'] ?? ''),
            'roundSlug' => (string) ($_POST['round_slug'] ?? ''),
            'subjectSlug' => (string) ($_POST['subject_slug'] ?? ''),
            'subjectType' => (string) ($_POST['subject_type'] ?? 'common'),
            'electiveGroup' => (string) ($_POST['elective_group'] ?? ''),
            'questionStart' => (int) ($_POST['question_start'] ?? 1),
            'questionEnd' => (int) ($_POST['question_end'] ?? 60),
            'questionCount' => (int) ($_POST['question_count'] ?? 60),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="js/admin_import.js" defer></script>
</body>
</html>
