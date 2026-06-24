<?php
declare(strict_types=1);

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
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT 문제 수정</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="style/admin_questions.css?v=20260619-2">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard-dynamic-subset.css" crossorigin>
</head>
<body>
    <div id="admin-app">
        <header class="admin-header">
            <div class="header-left">
                <div class="test-icon" aria-hidden="true"></div>
                <div>
                    <h1>CBT 문제 수정</h1>
                    <p>시험 회차를 고르고 문제 지문, 보기, 정답, 해설을 수정하세요.</p>
                </div>
            </div>
            <div class="header-actions">
                <a class="home-link" href="admin_import.php">시험/문제 등록</a>
                <a class="home-link" href="index.php">시험 선택으로</a>
                <a class="home-link" href="index.php?admin_logout=1">로그아웃</a>
            </div>
        </header>

        <section class="selector-bar" aria-label="시험 선택">
            <label>
                시험 분류
                <select id="level-select"></select>
            </label>
            <label>
                시험 회차
                <select id="exam-select"></select>
            </label>
            <button id="reload-questions" type="button">문제 불러오기</button>
        </section>

        <main class="admin-layout">
            <aside class="question-list-panel">
                <div class="panel-title-row">
                    <h2>문제 목록</h2>
                    <span id="question-count">0문제</span>
                </div>
                <div id="question-list" class="question-list">
                    <p class="empty-state">시험을 선택하면 문제가 표시됩니다.</p>
                </div>
            </aside>

            <section class="editor-panel">
                <div class="editor-toolbar">
                    <div>
                        <span id="selected-meta" class="selected-meta">문제를 선택해주세요.</span>
                        <h2 id="editor-title">문제 수정</h2>
                    </div>
                    <div class="toolbar-actions">
                        <button id="preview-exam" type="button">응시 화면 보기</button>
                        <button id="save-question" type="button" disabled>저장</button>
                    </div>
                </div>

                <form id="question-form" class="question-form">
                    <input id="question-id" type="hidden" name="id">

                    <div class="field-row compact">
                        <label>
                            과목
                            <input name="subject" type="text" autocomplete="off">
                        </label>
                        <label>
                            정답
                            <select name="answer" required>
                                <option value="1">1번</option>
                                <option value="2">2번</option>
                                <option value="3">3번</option>
                                <option value="4">4번</option>
                            </select>
                        </label>
                    </div>

                    <label>
                        문제 지문
                        <textarea name="question_text" rows="5" required></textarea>
                    </label>

                    <div class="field-row image-row">
                        <label>
                            문제 이미지 경로
                            <input name="question_image" type="text" placeholder="images/exams/...">
                        </label>
                        <label>
                            너비 %
                            <input name="question_image_width_percent" type="number" min="10" max="100" step="1">
                        </label>
                    </div>

                    <fieldset>
                        <legend>보기</legend>
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="option-group">
                                <label>
                                    <?= $i ?>번 보기
                                    <textarea name="option_<?= $i ?>_text" rows="2"></textarea>
                                </label>
                                <div class="field-row image-row">
                                    <label>
                                        <?= $i ?>번 이미지 경로
                                        <input name="option_<?= $i ?>_image" type="text" placeholder="images/exams/...">
                                    </label>
                                    <label>
                                        너비 %
                                        <input name="option_<?= $i ?>_image_width_percent" type="number" min="10" max="100" step="1">
                                    </label>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </fieldset>

                    <label>
                        해설
                        <textarea name="explanation_text" rows="5"></textarea>
                    </label>

                    <div class="field-row image-row">
                        <label>
                            해설 이미지 경로
                            <input name="explanation_image" type="text" placeholder="images/exams/...">
                        </label>
                        <label>
                            너비 %
                            <input name="explanation_image_width_percent" type="number" min="10" max="100" step="1">
                        </label>
                    </div>
                </form>
            </section>
        </main>

        <div id="toast" class="toast" role="status" aria-live="polite"></div>
    </div>

    <script>
        window.CBT_ADMIN_CONFIG = {
            apiBase: <?= json_encode('admin_api.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        };
    </script>
    <script src="js/admin_questions.js" defer></script>
</body>
</html>
