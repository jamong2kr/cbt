<?php
declare(strict_types=1);

function cbtDatabaseConfig(): array
{
    static $config;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config/database.php';
    }

    return $config;
}

function cbtOpenDatabase(): PDO
{
    $config = cbtDatabaseConfig();
    $driver = strtolower((string) ($config['driver'] ?? 'sqlite'));
    $options = [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($driver === 'mysql' || $driver === 'mariadb') {
        $mysql = $config['mysql'] ?? [];
        $database = trim((string) ($mysql['database'] ?? ''));
        $username = trim((string) ($mysql['username'] ?? ''));
        if ($database === '' || $username === '') {
            throw new RuntimeException('MariaDB 접속 설정의 DB명과 사용자명을 입력해주세요.');
        }

        $charset = (string) ($mysql['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($mysql['host'] ?? 'localhost'),
            (int) ($mysql['port'] ?? 3306),
            $database,
            $charset
        );
        $pdo = new PDO($dsn, $username, (string) ($mysql['password'] ?? ''), $options);
        $pdo->exec("SET NAMES {$charset} COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+09:00'");
        return $pdo;
    }

    $sqlite = $config['sqlite'] ?? [];
    $path = (string) ($sqlite['path'] ?? dirname(__DIR__) . '/cbt_exam.db');
    $pdo = new PDO('sqlite:' . $path, null, null, $options);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function cbtDatabaseDriver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function cbtIsMariaDb(PDO $pdo): bool
{
    return cbtDatabaseDriver($pdo) === 'mysql';
}

function cbtTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    if (cbtIsMariaDb($pdo)) {
        $stmt = $pdo->prepare('SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
            AND COLUMN_NAME = :column_name
            LIMIT 1');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (bool) $stmt->fetchColumn();
    }

    $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    return in_array($column, array_column($columns, 'name'), true);
}

function cbtEnsureExamSessionsTable(PDO $pdo): void
{
    if (cbtIsMariaDb($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS exam_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            exam_number VARCHAR(8) NOT NULL,
            applicant_name VARCHAR(100) NOT NULL,
            qualification_level VARCHAR(100) NOT NULL,
            certification_name VARCHAR(150) NOT NULL,
            round_name VARCHAR(100) NOT NULL,
            elective_subject VARCHAR(100) NOT NULL DEFAULT '',
            elective_subject_id INT UNSIGNED NULL,
            answers_json LONGTEXT NOT NULL,
            current_question_index INT NOT NULL DEFAULT 0,
            remaining_seconds INT NULL,
            submitted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_exam_sessions_identity (exam_number, qualification_level, certification_name, round_name),
            KEY idx_exam_sessions_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS exam_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exam_number TEXT NOT NULL,
            applicant_name TEXT NOT NULL,
            qualification_level TEXT NOT NULL,
            certification_name TEXT NOT NULL,
            round_name TEXT NOT NULL,
            elective_subject TEXT NOT NULL DEFAULT "",
            elective_subject_id INTEGER,
            answers_json TEXT NOT NULL DEFAULT "[]",
            current_question_index INTEGER NOT NULL DEFAULT 0,
            remaining_seconds INTEGER,
            submitted INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (exam_number, qualification_level, certification_name, round_name)
        )');
    }

    if (!cbtTableHasColumn($pdo, 'exam_sessions', 'elective_subject')) {
        if (cbtIsMariaDb($pdo)) {
            $pdo->exec("ALTER TABLE exam_sessions ADD COLUMN elective_subject VARCHAR(100) NOT NULL DEFAULT '' AFTER round_name");
        } else {
            $pdo->exec("ALTER TABLE exam_sessions ADD COLUMN elective_subject TEXT NOT NULL DEFAULT ''");
        }
    }

    if (!cbtTableHasColumn($pdo, 'exam_sessions', 'elective_subject_id')) {
        if (cbtIsMariaDb($pdo)) {
            $pdo->exec('ALTER TABLE exam_sessions ADD COLUMN elective_subject_id INT UNSIGNED NULL AFTER elective_subject');
        } else {
            $pdo->exec('ALTER TABLE exam_sessions ADD COLUMN elective_subject_id INTEGER');
        }
    }
}

function cbtCleanupOldExamSessions(PDO $pdo): void
{
    if (cbtIsMariaDb($pdo)) {
        $pdo->exec('DELETE FROM exam_sessions
            WHERE (submitted = 1 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
            OR (submitted = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY))');
        return;
    }

    $pdo->exec("DELETE FROM exam_sessions
        WHERE (submitted = 1 AND updated_at < datetime('now', '-30 days'))
        OR (submitted = 0 AND updated_at < datetime('now', '-90 days'))");
}
