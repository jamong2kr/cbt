<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI에서만 실행할 수 있습니다.\n");
    exit(1);
}

$root = dirname(__DIR__);
$sourcePath = $argv[1] ?? $root . '/cbt_exam.db';
$outputPath = $argv[2] ?? $root . '/database/mariadb_import.sql';

if (!is_file($sourcePath)) {
    fwrite(STDERR, "SQLite DB를 찾을 수 없습니다: {$sourcePath}\n");
    exit(1);
}

function sqlValue(mixed $value, string $column): string
{
    if ($value === null) {
        if ($column === 'subject' || $column === 'slug' || $column === 'elective_subject') {
            return "''";
        }
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $string = (string) $value;
    if ($string === '') {
        return "''";
    }

    return 'CONVERT(0x' . bin2hex($string) . ' USING utf8mb4)';
}

function tableColumns(PDO $pdo, string $table): array
{
    return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
}

$tables = [
    'qualification_levels',
    'certifications',
    'exam_rounds',
    'exam_subjects',
    'exam_questions',
    'exam_sessions',
];

$sqlite = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$schema = file_get_contents($root . '/database/mariadb_schema.sql');
if ($schema === false) {
    fwrite(STDERR, "MariaDB 스키마를 읽을 수 없습니다.\n");
    exit(1);
}

$handle = fopen($outputPath, 'wb');
if ($handle === false) {
    fwrite(STDERR, "출력 파일을 만들 수 없습니다: {$outputPath}\n");
    exit(1);
}

fwrite($handle, "-- Generated from cbt_exam.db at " . date('c') . "\n\n");
fwrite($handle, $schema . "\n\nSET FOREIGN_KEY_CHECKS = 0;\n");
foreach (array_reverse($tables) as $table) {
    fwrite($handle, "TRUNCATE TABLE `{$table}`;\n");
}
fwrite($handle, "\n");

$counts = [];
foreach ($tables as $table) {
    $columns = tableColumns($sqlite, $table);
    if (!$columns) {
        continue;
    }

    $rows = $sqlite->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    $counts[$table] = count($rows);
    foreach ($rows as $row) {
        if ($table === 'exam_questions' && $row['subject'] === null) {
            $row['subject'] = '';
        }
        if ($table === 'exam_sessions' && !array_key_exists('elective_subject', $row)) {
            $row['elective_subject'] = '';
            $columns[] = 'elective_subject';
        }

        $columnSql = implode(', ', array_map(static fn ($column) => '`' . $column . '`', $columns));
        $valueSql = implode(', ', array_map(
            static fn ($column) => sqlValue($row[$column] ?? null, $column),
            $columns
        ));
        fwrite($handle, "INSERT INTO `{$table}` ({$columnSql}) VALUES ({$valueSql});\n");
    }
    fwrite($handle, "\n");
}

fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($handle);

echo "MariaDB import SQL generated: {$outputPath}\n";
foreach ($counts as $table => $count) {
    echo "{$table}: {$count} rows\n";
}
