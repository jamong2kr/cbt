<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer can only be run from the command line.\n");
    exit(1);
}

function usage(): void
{
    $script = basename(__FILE__);
    echo <<<TEXT
Usage:
  php {$script} --file=questions.csv --level="교통안전관리자" --certification="도로교통안전관리자" --round="1회" --time=125 [--replace]

Required CSV columns, Korean or English headers are accepted:
  question_number / 문제번호
  subject / 과목
  question_text / 문제
  option_1_text / 보기1
  option_2_text / 보기2
  option_3_text / 보기3
  option_4_text / 보기4
  answer / 정답

Optional columns:
  question_image / 문제이미지
  question_image_width_percent / 문제이미지너비
  option_1_image / 보기1이미지
  option_1_image_width_percent / 보기1이미지너비
  option_2_image / 보기2이미지
  option_2_image_width_percent / 보기2이미지너비
  option_3_image / 보기3이미지
  option_3_image_width_percent / 보기3이미지너비
  option_4_image / 보기4이미지
  option_4_image_width_percent / 보기4이미지너비
  explanation_text / 해설
  explanation_image / 해설이미지
  explanation_image_width_percent / 해설이미지너비

TEXT;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function optionValue(array $options, string $name, ?string $default = null): ?string
{
    return array_key_exists($name, $options) ? trim((string) $options[$name]) : $default;
}

function trimBom(string $value): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function looksLikeUtf8(string $value): bool
{
    return mb_check_encoding($value, 'UTF-8');
}

function readCsvAsUtf8(string $file): string
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        fail("Cannot read CSV file: {$file}");
    }

    if (looksLikeUtf8($contents)) {
        return $contents;
    }

    foreach (['CP949', 'EUC-KR'] as $encoding) {
        $converted = @iconv($encoding, 'UTF-8//IGNORE', $contents);
        if ($converted !== false && looksLikeUtf8($converted)) {
            return $converted;
        }
    }

    fail('CSV encoding is not supported. Save it as CSV UTF-8 or Korean CP949/EUC-KR CSV.');
}

function normalizeHeader(string $header): string
{
    $header = trim(trimBom($header));
    $aliases = [
        '문제번호' => 'question_number',
        '문항번호' => 'question_number',
        '문번' => 'question_number',
        'No' => 'question_number',
        'NO' => 'question_number',
        'no' => 'question_number',
        '연번' => 'question_number',
        '번호' => 'question_number',
        '과목' => 'subject',
        '과목명' => 'subject',
        '문제' => 'question_text',
        '문제지문' => 'question_text',
        '문항' => 'question_text',
        '질문' => 'question_text',
        '문제 이미지' => 'question_image',
        '문제이미지' => 'question_image',
        '문제 이미지 너비' => 'question_image_width_percent',
        '문제이미지너비' => 'question_image_width_percent',
        '보기1' => 'option_1_text',
        '1번보기' => 'option_1_text',
        '보기 1' => 'option_1_text',
        '보기1 이미지' => 'option_1_image',
        '보기1이미지' => 'option_1_image',
        '보기1 이미지 너비' => 'option_1_image_width_percent',
        '보기1이미지너비' => 'option_1_image_width_percent',
        '보기2' => 'option_2_text',
        '2번보기' => 'option_2_text',
        '보기 2' => 'option_2_text',
        '보기2 이미지' => 'option_2_image',
        '보기2이미지' => 'option_2_image',
        '보기2 이미지 너비' => 'option_2_image_width_percent',
        '보기2이미지너비' => 'option_2_image_width_percent',
        '보기3' => 'option_3_text',
        '3번보기' => 'option_3_text',
        '보기 3' => 'option_3_text',
        '보기3 이미지' => 'option_3_image',
        '보기3이미지' => 'option_3_image',
        '보기3 이미지 너비' => 'option_3_image_width_percent',
        '보기3이미지너비' => 'option_3_image_width_percent',
        '보기4' => 'option_4_text',
        '4번보기' => 'option_4_text',
        '보기 4' => 'option_4_text',
        '보기4 이미지' => 'option_4_image',
        '보기4이미지' => 'option_4_image',
        '보기4 이미지 너비' => 'option_4_image_width_percent',
        '보기4이미지너비' => 'option_4_image_width_percent',
        '정답' => 'answer',
        '해설' => 'explanation_text',
        '해설 이미지' => 'explanation_image',
        '해설이미지' => 'explanation_image',
        '해설 이미지 너비' => 'explanation_image_width_percent',
        '해설이미지너비' => 'explanation_image_width_percent',
    ];

    return $aliases[$header] ?? $header;
}

function nullableString(?string $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function nullablePercent(?string $value, int $lineNumber, string $column): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $percent = (int) $value;
    if ((string) $percent !== $value || $percent < 10 || $percent > 100) {
        fail("Line {$lineNumber}: {$column} must be an integer between 10 and 100.");
    }

    return $percent;
}

function slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9가-힣]+/u', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    return $slug === '' ? 'item' : $slug;
}

function findOrCreateLevel(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM qualification_levels WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO qualification_levels (name, slug) VALUES (:name, :slug)');
    $stmt->execute([':name' => $name, ':slug' => slugify($name)]);
    return (int) $pdo->lastInsertId();
}

function findOrCreateCertification(PDO $pdo, int $levelId, string $name): int
{
    $stmt = $pdo->prepare('SELECT id FROM certifications WHERE level_id = :level_id AND name = :name LIMIT 1');
    $stmt->execute([':level_id' => $levelId, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO certifications (level_id, name, slug) VALUES (:level_id, :name, :slug)');
    $stmt->execute([':level_id' => $levelId, ':name' => $name, ':slug' => slugify($name)]);
    return (int) $pdo->lastInsertId();
}

function findOrCreateRound(PDO $pdo, int $certificationId, string $name, int $timeLimitMinutes): int
{
    $stmt = $pdo->prepare('SELECT id FROM exam_rounds WHERE certification_id = :certification_id AND round_name = :name LIMIT 1');
    $stmt->execute([':certification_id' => $certificationId, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        $update = $pdo->prepare('UPDATE exam_rounds SET time_limit_minutes = :time_limit_minutes WHERE id = :id');
        $update->execute([':time_limit_minutes' => $timeLimitMinutes, ':id' => $id]);
        return (int) $id;
    }

    $stmt = $pdo->prepare('INSERT INTO exam_rounds (certification_id, round_name, time_limit_minutes, slug)
        VALUES (:certification_id, :round_name, :time_limit_minutes, :slug)');
    $stmt->execute([
        ':certification_id' => $certificationId,
        ':round_name' => $name,
        ':time_limit_minutes' => $timeLimitMinutes,
        ':slug' => slugify($name),
    ]);
    return (int) $pdo->lastInsertId();
}

function findOrCreateExamSubject(PDO $pdo, int $roundId, string $name, int $questionNumber): int
{
    $stmt = $pdo->prepare('SELECT id FROM exam_subjects WHERE round_id = :round_id AND name = :name LIMIT 1');
    $stmt->execute([':round_id' => $roundId, ':name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM exam_subjects WHERE round_id = :round_id');
    $orderStmt->execute([':round_id' => $roundId]);
    $displayOrder = (int) $orderStmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO exam_subjects (
        round_id, name, slug, subject_type, elective_group,
        question_start, question_end, question_count, display_order, active
    ) VALUES (
        :round_id, :name, :slug, \'common\', \'\',
        :question_start, :question_end, 1, :display_order, 1
    )');
    $stmt->execute([
        ':round_id' => $roundId,
        ':name' => $name,
        ':slug' => 'subject-' . str_pad((string) $displayOrder, 2, '0', STR_PAD_LEFT),
        ':question_start' => $questionNumber,
        ':question_end' => $questionNumber,
        ':display_order' => $displayOrder,
    ]);
    return (int) $pdo->lastInsertId();
}

$options = getopt('', [
    'file:',
    'level:',
    'certification:',
    'round:',
    'time:',
    'replace',
    'help',
]);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$file = optionValue($options, 'file');
$level = optionValue($options, 'level');
$certification = optionValue($options, 'certification');
$round = optionValue($options, 'round', '1회');
$timeLimitMinutes = (int) optionValue($options, 'time', '60');
$replace = array_key_exists('replace', $options);

if ($file === null || $level === null || $certification === null || $round === null) {
    usage();
    fail('Missing required options.');
}

if (!is_file($file)) {
    fail("CSV file not found: {$file}");
}

if ($timeLimitMinutes <= 0) {
    fail('--time must be greater than 0.');
}

$csvContents = readCsvAsUtf8($file);
$handle = fopen('php://temp', 'rb+');
if ($handle === false) {
    fail('Cannot create a temporary CSV stream.');
}
fwrite($handle, $csvContents);
rewind($handle);

$headerRow = fgetcsv($handle);
if ($headerRow === false) {
    fail('CSV file is empty.');
}

$headers = array_map(static fn ($header) => normalizeHeader((string) $header), $headerRow);
$requiredColumns = [
    'question_number',
    'subject',
    'question_text',
    'option_1_text',
    'option_2_text',
    'option_3_text',
    'option_4_text',
    'answer',
];

foreach ($requiredColumns as $column) {
    if (!in_array($column, $headers, true)) {
        fail("Missing required CSV column: {$column}\nDetected columns: " . implode(', ', $headers));
    }
}

$pdo = cbtOpenDatabase();

$insert = $pdo->prepare('REPLACE INTO exam_questions (
        round_id,
        subject_id,
        question_number,
        subject,
        question_text,
        question_image,
        question_image_width_percent,
        option_1_text,
        option_1_image,
        option_1_image_width_percent,
        option_2_text,
        option_2_image,
        option_2_image_width_percent,
        option_3_text,
        option_3_image,
        option_3_image_width_percent,
        option_4_text,
        option_4_image,
        option_4_image_width_percent,
        answer,
        explanation_text,
        explanation_image,
        explanation_image_width_percent
    ) VALUES (
        :round_id,
        :subject_id,
        :question_number,
        :subject,
        :question_text,
        :question_image,
        :question_image_width_percent,
        :option_1_text,
        :option_1_image,
        :option_1_image_width_percent,
        :option_2_text,
        :option_2_image,
        :option_2_image_width_percent,
        :option_3_text,
        :option_3_image,
        :option_3_image_width_percent,
        :option_4_text,
        :option_4_image,
        :option_4_image_width_percent,
        :answer,
        :explanation_text,
        :explanation_image,
        :explanation_image_width_percent
    )');

$pdo->beginTransaction();

try {
    $levelId = findOrCreateLevel($pdo, $level);
    $certificationId = findOrCreateCertification($pdo, $levelId, $certification);
    $roundId = findOrCreateRound($pdo, $certificationId, $round, $timeLimitMinutes);

    if ($replace) {
        $delete = $pdo->prepare('DELETE FROM exam_questions WHERE round_id = :round_id');
        $delete->execute([':round_id' => $roundId]);
    }

    $seenNumbers = [];
    $importedSubjectIds = [];
    $imported = 0;
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        if (count(array_filter($row, static fn ($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        $data = array_combine($headers, array_pad($row, count($headers), ''));
        if ($data === false) {
            fail("Line {$lineNumber}: invalid CSV row.");
        }

        $questionNumber = (int) trim((string) $data['question_number']);
        $subjectName = trim((string) $data['subject']);
        $answer = (int) trim((string) $data['answer']);
        $questionText = trim((string) $data['question_text']);

        if ($questionNumber <= 0) {
            fail("Line {$lineNumber}: question_number must be greater than 0.");
        }

        if (isset($seenNumbers[$questionNumber])) {
            fail("Line {$lineNumber}: duplicated question_number {$questionNumber} in CSV.");
        }
        $seenNumbers[$questionNumber] = true;

        if ($questionText === '') {
            fail("Line {$lineNumber}: question_text is required.");
        }

        if ($subjectName === '') {
            fail("Line {$lineNumber}: subject is required.");
        }

        if ($answer < 1 || $answer > 4) {
            fail("Line {$lineNumber}: answer must be 1, 2, 3, or 4.");
        }

        $subjectId = findOrCreateExamSubject($pdo, $roundId, $subjectName, $questionNumber);
        $importedSubjectIds[$subjectId] = true;
        $insert->execute([
            ':round_id' => $roundId,
            ':subject_id' => $subjectId,
            ':question_number' => $questionNumber,
            ':subject' => $subjectName,
            ':question_text' => $questionText,
            ':question_image' => nullableString($data['question_image'] ?? null),
            ':question_image_width_percent' => nullablePercent($data['question_image_width_percent'] ?? null, $lineNumber, 'question_image_width_percent'),
            ':option_1_text' => nullableString($data['option_1_text'] ?? null),
            ':option_1_image' => nullableString($data['option_1_image'] ?? null),
            ':option_1_image_width_percent' => nullablePercent($data['option_1_image_width_percent'] ?? null, $lineNumber, 'option_1_image_width_percent'),
            ':option_2_text' => nullableString($data['option_2_text'] ?? null),
            ':option_2_image' => nullableString($data['option_2_image'] ?? null),
            ':option_2_image_width_percent' => nullablePercent($data['option_2_image_width_percent'] ?? null, $lineNumber, 'option_2_image_width_percent'),
            ':option_3_text' => nullableString($data['option_3_text'] ?? null),
            ':option_3_image' => nullableString($data['option_3_image'] ?? null),
            ':option_3_image_width_percent' => nullablePercent($data['option_3_image_width_percent'] ?? null, $lineNumber, 'option_3_image_width_percent'),
            ':option_4_text' => nullableString($data['option_4_text'] ?? null),
            ':option_4_image' => nullableString($data['option_4_image'] ?? null),
            ':option_4_image_width_percent' => nullablePercent($data['option_4_image_width_percent'] ?? null, $lineNumber, 'option_4_image_width_percent'),
            ':answer' => $answer,
            ':explanation_text' => nullableString($data['explanation_text'] ?? null),
            ':explanation_image' => nullableString($data['explanation_image'] ?? null),
            ':explanation_image_width_percent' => nullablePercent($data['explanation_image_width_percent'] ?? null, $lineNumber, 'explanation_image_width_percent'),
        ]);

        $imported++;
    }

    $refreshSubject = $pdo->prepare('UPDATE exam_subjects SET
        question_start = (SELECT MIN(question_number) FROM exam_questions WHERE subject_id = :subject_id_min),
        question_end = (SELECT MAX(question_number) FROM exam_questions WHERE subject_id = :subject_id_max),
        question_count = (SELECT COUNT(*) FROM exam_questions WHERE subject_id = :subject_id_count)
        WHERE id = :subject_id');
    foreach (array_keys($importedSubjectIds) as $subjectId) {
        $refreshSubject->execute([
            ':subject_id_min' => $subjectId,
            ':subject_id_max' => $subjectId,
            ':subject_id_count' => $subjectId,
            ':subject_id' => $subjectId,
        ]);
    }

    $pdo->commit();
    fclose($handle);

    echo "Imported {$imported} questions.\n";
    echo "Level: {$level}\n";
    echo "Certification: {$certification}\n";
    echo "Round: {$round}\n";
    echo "Time limit: {$timeLimitMinutes} minutes\n";
} catch (Throwable $error) {
    $pdo->rollBack();
    fclose($handle);
    fail($error->getMessage());
}
