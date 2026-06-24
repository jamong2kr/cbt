<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI에서만 실행할 수 있습니다.\n");
    exit(1);
}

function subjectSlug(string $level, string $certification, string $subject, int $order): string
{
    $known = [
        '교통법규' => 'traffic-law',
        '교통안전관리론' => 'traffic-safety-management',
        '자동차정비' => 'auto-maintenance',
        '자동차공학' => 'automotive-engineering',
        '교통심리학' => 'traffic-psychology',
    ];
    if ($level === '교통안전관리자' && $certification === '도로교통안전관리자' && isset($known[$subject])) {
        return $known[$subject];
    }

    return 'subject-' . str_pad((string) $order, 2, '0', STR_PAD_LEFT);
}

function ensureSubjectSchema(PDO $pdo): void
{
    if (cbtIsMariaDb($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS exam_subjects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            round_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            slug VARCHAR(150) NOT NULL,
            subject_type VARCHAR(20) NOT NULL DEFAULT 'common',
            elective_group VARCHAR(100) NOT NULL DEFAULT '',
            question_start INT UNSIGNED NOT NULL,
            question_end INT UNSIGNED NOT NULL,
            question_count INT UNSIGNED NOT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_exam_subjects_round_name (round_id, name),
            UNIQUE KEY uq_exam_subjects_round_slug (round_id, slug),
            KEY idx_exam_subjects_round_type (round_id, subject_type, display_order),
            CONSTRAINT fk_exam_subjects_round FOREIGN KEY (round_id) REFERENCES exam_rounds(id)
                ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!cbtTableHasColumn($pdo, 'exam_questions', 'subject_id')) {
            $pdo->exec('ALTER TABLE exam_questions ADD COLUMN subject_id INT UNSIGNED NULL AFTER round_id');
        }
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS exam_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            round_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            subject_type TEXT NOT NULL DEFAULT 'common',
            elective_group TEXT NOT NULL DEFAULT '',
            question_start INTEGER NOT NULL,
            question_end INTEGER NOT NULL,
            question_count INTEGER NOT NULL,
            display_order INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            UNIQUE (round_id, name),
            UNIQUE (round_id, slug),
            FOREIGN KEY (round_id) REFERENCES exam_rounds(id) ON DELETE CASCADE
        )");
        if (!cbtTableHasColumn($pdo, 'exam_questions', 'subject_id')) {
            $pdo->exec('ALTER TABLE exam_questions ADD COLUMN subject_id INTEGER');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_exam_subjects_round_type ON exam_subjects(round_id, subject_type, display_order)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_exam_questions_subject ON exam_questions(subject_id, question_number)');
    }
    cbtEnsureExamSessionsTable($pdo);
}

function migrateSubjects(PDO $pdo): void
{
    ensureSubjectSchema($pdo);
    $rows = $pdo->query('SELECT
            l.name AS level_name,
            c.name AS certification_name,
            r.id AS round_id,
            q.subject,
            MIN(q.question_number) AS question_start,
            MAX(q.question_number) AS question_end,
            COUNT(*) AS question_count
        FROM exam_questions q
        JOIN exam_rounds r ON r.id = q.round_id
        JOIN certifications c ON c.id = r.certification_id
        JOIN qualification_levels l ON l.id = c.level_id
        GROUP BY l.name, c.name, r.id, q.subject
        ORDER BY r.id, MIN(q.question_number), q.subject')->fetchAll();

    $roundOrder = [];
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $roundId = (int) $row['round_id'];
            $roundOrder[$roundId] = ($roundOrder[$roundId] ?? 0) + 1;
            $isRoadElective = $row['level_name'] === '교통안전관리자'
                && $row['certification_name'] === '도로교통안전관리자'
                && in_array($row['subject'], ['자동차공학', '교통심리학'], true);
            $values = [
                ':round_id' => $roundId,
                ':name' => (string) $row['subject'],
                ':slug' => subjectSlug((string) $row['level_name'], (string) $row['certification_name'], (string) $row['subject'], $roundOrder[$roundId]),
                ':subject_type' => $isRoadElective ? 'elective' : 'common',
                ':elective_group' => $isRoadElective ? 'elective-1' : '',
                ':question_start' => (int) $row['question_start'],
                ':question_end' => (int) $row['question_end'],
                ':question_count' => (int) $row['question_count'],
                ':display_order' => $roundOrder[$roundId],
            ];

            if (cbtIsMariaDb($pdo)) {
                $stmt = $pdo->prepare('INSERT INTO exam_subjects (
                        round_id, name, slug, subject_type, elective_group,
                        question_start, question_end, question_count, display_order, active
                    ) VALUES (
                        :round_id, :name, :slug, :subject_type, :elective_group,
                        :question_start, :question_end, :question_count, :display_order, 1
                    ) ON DUPLICATE KEY UPDATE
                        slug = VALUES(slug), subject_type = VALUES(subject_type),
                        elective_group = VALUES(elective_group), question_start = VALUES(question_start),
                        question_end = VALUES(question_end), question_count = VALUES(question_count),
                        display_order = VALUES(display_order), active = 1');
            } else {
                $stmt = $pdo->prepare('REPLACE INTO exam_subjects (
                        round_id, name, slug, subject_type, elective_group,
                        question_start, question_end, question_count, display_order, active
                    ) VALUES (
                        :round_id, :name, :slug, :subject_type, :elective_group,
                        :question_start, :question_end, :question_count, :display_order, 1
                    )');
            }
            $stmt->execute($values);
        }

        $pdo->exec('UPDATE exam_questions
            SET subject_id = (
                SELECT es.id FROM exam_subjects es
                WHERE es.round_id = exam_questions.round_id AND es.name = exam_questions.subject
                LIMIT 1
            )');

        if (cbtIsMariaDb($pdo)) {
            $pdo->exec('UPDATE exam_sessions s
                LEFT JOIN qualification_levels l ON l.name = s.qualification_level
                LEFT JOIN certifications c ON c.level_id = l.id AND c.name = s.certification_name
                LEFT JOIN exam_rounds r ON r.certification_id = c.id AND r.round_name = s.round_name
                LEFT JOIN exam_subjects es ON es.round_id = r.id AND es.name = s.elective_subject
                SET s.elective_subject_id = es.id');
        } else {
            $pdo->exec('UPDATE exam_sessions
                SET elective_subject_id = (
                    SELECT es.id
                    FROM qualification_levels l
                    JOIN certifications c ON c.level_id = l.id
                    JOIN exam_rounds r ON r.certification_id = c.id
                    JOIN exam_subjects es ON es.round_id = r.id
                    WHERE l.name = exam_sessions.qualification_level
                    AND c.name = exam_sessions.certification_name
                    AND r.round_name = exam_sessions.round_name
                    AND es.name = exam_sessions.elective_subject
                    LIMIT 1
                )');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    if (cbtIsMariaDb($pdo)) {
        $index = $pdo->query("SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_questions'
            AND INDEX_NAME = 'idx_exam_questions_subject' LIMIT 1")->fetchColumn();
        if (!$index) {
            $pdo->exec('CREATE INDEX idx_exam_questions_subject ON exam_questions(subject_id, question_number)');
        }
        $constraint = $pdo->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_questions'
            AND CONSTRAINT_NAME = 'fk_exam_questions_subject' LIMIT 1")->fetchColumn();
        if (!$constraint) {
            $pdo->exec('ALTER TABLE exam_questions ADD CONSTRAINT fk_exam_questions_subject
                FOREIGN KEY (subject_id) REFERENCES exam_subjects(id)
                ON UPDATE CASCADE ON DELETE RESTRICT');
        }
        $sessionIndex = $pdo->query("SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_sessions'
            AND INDEX_NAME = 'idx_exam_sessions_elective_subject' LIMIT 1")->fetchColumn();
        if (!$sessionIndex) {
            $pdo->exec('CREATE INDEX idx_exam_sessions_elective_subject ON exam_sessions(elective_subject_id)');
        }
        $sessionConstraint = $pdo->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_sessions'
            AND CONSTRAINT_NAME = 'fk_exam_sessions_elective_subject' LIMIT 1")->fetchColumn();
        if (!$sessionConstraint) {
            $pdo->exec('ALTER TABLE exam_sessions ADD CONSTRAINT fk_exam_sessions_elective_subject
                FOREIGN KEY (elective_subject_id) REFERENCES exam_subjects(id)
                ON UPDATE CASCADE ON DELETE SET NULL');
        }
    }
}

$databases = [
    'configured database' => cbtOpenDatabase(),
    'SQLite source' => new PDO('sqlite:' . dirname(__DIR__) . '/cbt_exam.db', null, null, [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]),
];

foreach ($databases as $label => $pdo) {
    migrateSubjects($pdo);
    $subjectCount = $pdo->query('SELECT COUNT(*) FROM exam_subjects')->fetchColumn();
    $linkedCount = $pdo->query('SELECT COUNT(*) FROM exam_questions WHERE subject_id IS NOT NULL')->fetchColumn();
    echo "{$label}: {$subjectCount} subjects, {$linkedCount} linked questions\n";
}
