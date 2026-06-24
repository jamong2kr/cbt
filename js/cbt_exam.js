const CONFIG = window.CBT_EXAM_CONFIG || {};
const API_URL = CONFIG.apiUrl || "main.php?api=questions";
const SESSION_URL = CONFIG.sessionUrl || "";
let TEST_NAME = CONFIG.testName || "자동차정비기능사 1회";
let TIME_LIMIT_SECONDS = Number(CONFIG.timeLimitMinutes || 60) * 60;
const OPTION_LABELS = ["①", "②", "③", "④"];
const DEFAULT_IMAGE_WIDTH_PERCENT = 100;
const DEFAULT_IMAGE_MAX_HEIGHT = 360;
const MIN_IMAGE_SCALE = 0.25;

const state = {
    questions: [],
    answers: [],
    revealedQuestions: new Set(),
    pages: [[0, 0]],
    page: 0,
    submitted: false,
    remainingSeconds: TIME_LIMIT_SECONDS,
    timerId: null,
    saveTimerId: null,
    resizeTimerId: null,
    paginationToken: 0,
    sessionLoaded: false,
};

const el = {
    title: document.querySelector(".test-title"),
    questionsArea: document.querySelector(".questions-area"),
    omrSheet: document.querySelector(".omr-sheet"),
    totalCount: document.querySelector("#total-question-count"),
    unansweredCount: document.querySelector("#unanswered-question-count"),
    pageInfo: document.querySelector(".page-info"),
    prev: document.querySelector("#prev-page"),
    next: document.querySelector("#next-page"),
    firstUnanswered: document.querySelector("#first-unanswered"),
    submit: document.querySelector("#submit-exam"),
    timeLimit: document.querySelector(".time-limit"),
    timeRemaining: document.querySelector(".time-remaining"),
    unansweredModal: document.querySelector("#unanswered-modal"),
    unansweredList: document.querySelector("#unanswered-list"),
    closeUnansweredModal: document.querySelector("#close-unanswered-modal"),
    backToStart: document.querySelector("#back-to-start"),
};

const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

const toDisplayText = (value) => escapeHtml(value).replaceAll("\n", "<br>");

function toExplanationText(value) {
    return toDisplayText(value).replace(
        /[①②③④⑤⑥⑦⑧⑨⑩⑪⑫⑬⑭⑮⑯⑰⑱⑲⑳]/g,
        (match) => `<span class="explanation-option-number">${match}</span>`
    );
}

function renderImage(src, alt, widthPercent) {
    if (!src) {
        return "";
    }

    const imageWidth = Number(widthPercent) || DEFAULT_IMAGE_WIDTH_PERCENT;
    const style = ` style="--image-width: ${imageWidth}%;"`;
    return `<img class="question-image" src="${escapeHtml(src)}" alt="${escapeHtml(alt)}" data-image-width="${imageWidth}"${style}>`;
}

function setStatus(message) {
    el.questionsArea.innerHTML = `<div class="question-container"><p class="question-text">${message}</p></div>`;
}

async function loadQuestions() {
    try {
        const response = await fetch(API_URL, {
            headers: {
                Accept: "application/json",
            },
        });

        if (!response.ok) {
            throw new Error("시험 데이터를 불러올 수 없습니다.");
        }

        const data = await response.json();
        if (data.error) {
            throw new Error(data.detail || data.error);
        }

        TEST_NAME = data.meta?.testName || TEST_NAME;
        TIME_LIMIT_SECONDS = Number(data.meta?.timeLimitMinutes || 60) * 60;
        state.remainingSeconds = TIME_LIMIT_SECONDS;
        state.questions = data.questions || [];

        if (!state.questions.length) {
            throw new Error(`${TEST_NAME} 시험 데이터가 없습니다.`);
        }

        state.answers = Array(state.questions.length).fill(null);
        const restoredQuestionIndex = await loadSession();
        await render(restoredQuestionIndex);

        if (!state.submitted) {
            startTimer();
        }
    } catch (error) {
        console.error(error);
        setStatus("시험 데이터를 불러오지 못했습니다. PHP 서버의 SQLite 확장과 cbt_exam.db 파일 경로를 확인해주세요.");
    }
}

async function loadSession() {
    if (!SESSION_URL) {
        state.sessionLoaded = true;
        return null;
    }

    try {
        const response = await fetch(SESSION_URL, {
            headers: {
                Accept: "application/json",
            },
        });
        if (!response.ok) {
            throw new Error("진행 정보를 불러오지 못했습니다.");
        }

        const data = await response.json();
        state.sessionLoaded = true;
        const session = data.session;
        if (!session) {
            return null;
        }

        if (Array.isArray(session.answers) && session.answers.length === state.questions.length) {
            state.answers = session.answers.map((answer) => {
                const value = Number(answer);
                return Number.isInteger(value) && value >= 1 && value <= 4 ? value : null;
            });
        }

        if (session.remainingSeconds !== null && Number.isFinite(Number(session.remainingSeconds))) {
            state.remainingSeconds = Math.max(0, Math.min(TIME_LIMIT_SECONDS, Number(session.remainingSeconds)));
        }

        state.submitted = Boolean(session.submitted);
        return Math.max(0, Math.min(state.questions.length - 1, Number(session.currentQuestionIndex || 0)));
    } catch (error) {
        console.error(error);
        return null;
    }
}

function getCurrentQuestionIndex() {
    return state.pages[state.page]?.[0] ?? 0;
}

function buildSessionPayload() {
    return {
        answers: state.answers,
        currentQuestionIndex: getCurrentQuestionIndex(),
        remainingSeconds: state.remainingSeconds,
        submitted: state.submitted,
    };
}

async function saveSession({ keepalive = false } = {}) {
    if (!SESSION_URL || !state.sessionLoaded || !state.questions.length) {
        return;
    }

    const body = JSON.stringify(buildSessionPayload());

    if (keepalive && navigator.sendBeacon) {
        navigator.sendBeacon(SESSION_URL, new Blob([body], { type: "application/json" }));
        return;
    }

    try {
        await fetch(SESSION_URL, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
            },
            body,
            keepalive,
        });
    } catch (error) {
        console.error(error);
    }
}

function scheduleSessionSave(delay = 300) {
    window.clearTimeout(state.saveTimerId);
    state.saveTimerId = window.setTimeout(() => {
        saveSession();
    }, delay);
}

async function render(anchorQuestionIndex = null) {
    await paginateQuestions(anchorQuestionIndex);
    await renderQuestions();
    renderOmr();
    updateStats();
}

function getCurrentPageSubject() {
    const [start] = state.pages[state.page] ?? [0, 0];
    const subject = state.questions[start]?.subject_name;
    return String(subject || "").trim();
}

function updateTitle() {
    const subject = getCurrentPageSubject();
    const showSubject = CONFIG.qualificationLevel === "교통안전관리자";
    el.title.textContent = showSubject && subject ? `${TEST_NAME} - ${subject}` : TEST_NAME;
}

function formatTime(totalSeconds) {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes}분 ${String(seconds).padStart(2, "0")}초`;
}

function updateTimerDisplay() {
    el.timeLimit.textContent = `제한 시간 : ${Math.floor(TIME_LIMIT_SECONDS / 60)}분`;
    el.timeRemaining.textContent = `남은 시간 : ${formatTime(state.remainingSeconds)}`;
}

function startTimer() {
    updateTimerDisplay();
    if (state.timerId) {
        clearInterval(state.timerId);
    }

    state.timerId = setInterval(() => {
        state.remainingSeconds = Math.max(0, state.remainingSeconds - 1);
        updateTimerDisplay();

        if (state.remainingSeconds === 0) {
            clearInterval(state.timerId);
            state.timerId = null;
            submitExam("제한 시간이 종료되어 자동 채점합니다.");
        } else if (state.remainingSeconds % 10 === 0) {
            saveSession();
        }
    }, 1000);
}

function waitForImages(container) {
    const images = Array.from(container.querySelectorAll("img"));
    return Promise.all(images.map((image) => {
        if (image.complete) {
            return Promise.resolve();
        }

        if (image.decode) {
            return image.decode().catch(() => undefined);
        }

        return new Promise((resolve) => {
            image.addEventListener("load", resolve, { once: true });
            image.addEventListener("error", resolve, { once: true });
        });
    }));
}

function nextFrame() {
    return new Promise((resolve) => requestAnimationFrame(resolve));
}

function applyImageScale(scale) {
    el.questionsArea.querySelectorAll(".question-image").forEach((image) => {
        const baseWidth = Number(image.dataset.imageWidth) || DEFAULT_IMAGE_WIDTH_PERCENT;
        image.style.setProperty("--image-width", `${baseWidth * scale}%`);
        image.style.maxHeight = `${DEFAULT_IMAGE_MAX_HEIGHT * scale}px`;
    });
}

function resetImageScale() {
    el.questionsArea.querySelectorAll(".question-image").forEach((image) => {
        const baseWidth = Number(image.dataset.imageWidth) || DEFAULT_IMAGE_WIDTH_PERCENT;
        image.style.setProperty("--image-width", `${baseWidth}%`);
        image.style.maxHeight = "";
    });
}

async function fitImagesToQuestionArea() {
    await waitForImages(el.questionsArea);
    resetImageScale();
    await nextFrame();

    if (el.questionsArea.scrollHeight <= el.questionsArea.clientHeight + 1) {
        return;
    }

    let scale = Math.max(MIN_IMAGE_SCALE, el.questionsArea.clientHeight / el.questionsArea.scrollHeight);

    for (let attempt = 0; attempt < 8; attempt += 1) {
        applyImageScale(scale);
        await nextFrame();

        if (el.questionsArea.scrollHeight <= el.questionsArea.clientHeight + 1 || scale <= MIN_IMAGE_SCALE) {
            break;
        }

        const ratio = el.questionsArea.clientHeight / el.questionsArea.scrollHeight;
        scale = Math.max(MIN_IMAGE_SCALE, scale * ratio * 0.98);
    }
}

function buildQuestionMarkup(questionIndex) {
    const question = state.questions[questionIndex];
    const selected = state.answers[questionIndex];
    const options = [question.option_1, question.option_2, question.option_3, question.option_4]
        .map((option, optionIndex) => {
            const value = optionIndex + 1;
            const checked = selected === value ? " checked" : "";
            const selectedClass = selected === value ? " selected" : "";
            const image = question[`option_${value}_image`];
            const imageWidth = question[`option_${value}_image_width_percent`];
            return `
                <label class="option${selectedClass}">
                    <input type="radio" name="q${question.q_number}" value="${value}" data-question-index="${questionIndex}"${checked}>
                    <span class="option-number">${OPTION_LABELS[optionIndex]}</span>
                    <span class="option-content">
                        <span class="option-text">${toDisplayText(option)}</span>
                        ${renderImage(image, `${question.q_number}번 ${value}번 보기 이미지`, imageWidth)}
                    </span>
                </label>
            `;
        }).join("");

    const isRevealed = state.submitted || state.revealedQuestions.has(questionIndex);
    const revealButton = state.submitted ? "" : `
        <div class="question-actions">
            <button class="explanation-toggle" type="button" data-reveal-question-index="${questionIndex}">
                ${isRevealed ? "풀이 닫기" : "풀이 보기"}
            </button>
        </div>
    `;
    const result = isRevealed ? renderResult(question, selected) : "";

    return `
        <div class="question-container" id="question-${question.q_number}">
            <div class="question-header">
                <span class="question-number">${question.q_number}.</span>
                <p class="question-text">
                    ${toDisplayText(question.question_text)}
                    ${renderImage(question.question_image, `${question.q_number}번 문제 이미지`, question.question_image_width_percent)}
                </p>
            </div>
            <div class="options-container">${options}</div>
            ${revealButton}
            ${result}
        </div>
    `;
}

function getPageForQuestion(questionIndex) {
    return Math.max(0, state.pages.findIndex(([start, end]) => questionIndex >= start && questionIndex < end));
}

async function paginateQuestions(anchorQuestionIndex = null) {
    if (!state.questions.length) {
        state.pages = [[0, 0]];
        return;
    }

    const token = ++state.paginationToken;
    const pages = [];
    const previousPageStart = state.pages[state.page]?.[0] ?? 0;
    const anchor = anchorQuestionIndex ?? previousPageStart;
    let start = 0;

    while (start < state.questions.length) {
        let end = start + 1;
        let bestEnd = end;

        while (end <= state.questions.length) {
            el.questionsArea.innerHTML = Array
                .from({ length: end - start }, (_, offset) => buildQuestionMarkup(start + offset))
                .join("");
            await waitForImages(el.questionsArea);

            if (token !== state.paginationToken) {
                return;
            }

            const fits = el.questionsArea.scrollHeight <= el.questionsArea.clientHeight + 1;
            if (!fits && end > start + 1) {
                break;
            }

            bestEnd = end;
            if (!fits) {
                break;
            }
            end += 1;
        }

        pages.push([start, bestEnd]);
        start = bestEnd;
    }

    state.pages = pages;
    state.page = getPageForQuestion(Math.min(anchor, state.questions.length - 1));
}

async function renderQuestions() {
    const [start, end] = state.pages[state.page] ?? [0, 0];

    updateTitle();

    el.questionsArea.innerHTML = Array
        .from({ length: end - start }, (_, offset) => buildQuestionMarkup(start + offset))
        .join("");

    el.questionsArea.querySelectorAll("input[type='radio']").forEach((input) => {
        input.addEventListener("change", (event) => {
            const questionIndex = Number(event.target.dataset.questionIndex);
            state.answers[questionIndex] = Number(event.target.value);
            state.submitted = false;
            state.revealedQuestions.delete(questionIndex);
            el.questionsArea
                .querySelectorAll(`input[name="${event.target.name}"]`)
                .forEach((radio) => {
                    radio.closest(".option").classList.toggle("selected", radio.checked);
                });
            el.questionsArea.querySelectorAll(".result-box").forEach((box) => box.remove());
            renderOmr();
            updateStats();
            scheduleSessionSave();
        });
    });

    el.questionsArea.querySelectorAll("[data-reveal-question-index]").forEach((button) => {
        button.addEventListener("click", async () => {
            const questionIndex = Number(button.dataset.revealQuestionIndex);
            if (state.answers[questionIndex] == null) {
                alert("답안을 선택한 후 풀이를 확인할 수 있습니다.");
                return;
            }

            if (state.revealedQuestions.has(questionIndex)) {
                state.revealedQuestions.delete(questionIndex);
            } else {
                state.revealedQuestions.add(questionIndex);
            }

            await renderQuestions();
        });
    });

    const totalPages = state.pages.length;
    el.pageInfo.textContent = `${state.page + 1}/${totalPages}`;
    el.prev.disabled = state.page === 0;
    el.next.disabled = state.page >= totalPages - 1;

    await fitImagesToQuestionArea();
}

function renderResult(question, selected) {
    const correct = Number(question.answer);
    const isCorrect = selected === correct;
    const statusText = selected == null ? "미답" : isCorrect ? "정답" : "오답";
    const explanation = question.explanation || question.explanation_image
        ? `<div class="explanation">${toExplanationText(question.explanation)}${renderImage(question.explanation_image, `${question.q_number}번 해설 이미지`, question.explanation_image_width_percent)}</div>`
        : "";

    return `
        <div class="result-box ${isCorrect ? "correct" : "wrong"}">
            ${statusText} / 정답 <span class="explanation-option-number">${OPTION_LABELS[correct - 1]}</span>
            ${explanation}
        </div>
    `;
}

function renderOmr() {
    el.omrSheet.innerHTML = state.questions.map((question, index) => {
        const selected = state.answers[index];
        const circles = OPTION_LABELS.map((label, optionIndex) => {
            const value = optionIndex + 1;
            const marked = selected === value ? " marked" : "";
            return `<button class="omr-circle${marked}" type="button" data-question-index="${index}" data-value="${value}">${label}</button>`;
        }).join("");

        return `
            <div class="omr-row">
                <button class="row-num" type="button" data-question-index="${index}">${question.q_number}</button>
                <div class="omr-circles">${circles}</div>
            </div>
        `;
    }).join("");

    el.omrSheet.querySelectorAll("[data-question-index]").forEach((button) => {
        button.addEventListener("click", async () => {
            const questionIndex = Number(button.dataset.questionIndex);
            if (button.dataset.value) {
                const selectedValue = Number(button.dataset.value);
                const question = state.questions[questionIndex];

                state.answers[questionIndex] = selectedValue;
                state.submitted = false;
                state.revealedQuestions.delete(questionIndex);

                button.closest(".omr-row").querySelectorAll(".omr-circle").forEach((circle) => {
                    circle.classList.toggle("marked", Number(circle.dataset.value) === selectedValue);
                });

                el.questionsArea
                    .querySelectorAll(`input[name="q${question.q_number}"]`)
                    .forEach((radio) => {
                        radio.checked = Number(radio.value) === selectedValue;
                        radio.closest(".option").classList.toggle("selected", radio.checked);
                    });

                el.questionsArea.querySelectorAll(".result-box").forEach((box) => box.remove());
                updateStats();
                scheduleSessionSave();
                return;
            }

            await render(questionIndex);
            scheduleSessionSave();
        });
    });
}

function updateStats() {
    const total = state.questions.length;
    const unanswered = state.answers.filter((answer) => answer == null).length;
    el.totalCount.textContent = total;
    el.unansweredCount.textContent = unanswered;
}

function getUnansweredQuestionIndexes() {
    return state.answers
        .map((answer, index) => (answer == null ? index : null))
        .filter((index) => index !== null);
}

function closeUnansweredModal() {
    if (el.unansweredModal) {
        el.unansweredModal.hidden = true;
    }
}

function openUnansweredModal() {
    if (!el.unansweredModal || !el.unansweredList) {
        return;
    }

    const unansweredIndexes = getUnansweredQuestionIndexes();
    if (!unansweredIndexes.length) {
        el.unansweredList.innerHTML = '<p class="modal-empty">안 푼 문제가 없습니다.</p>';
    } else {
        el.unansweredList.innerHTML = unansweredIndexes.map((questionIndex) => {
            const question = state.questions[questionIndex];
            return `
                <button class="unanswered-number" type="button" data-question-index="${questionIndex}">
                    ${question.q_number}
                </button>
            `;
        }).join("");
    }

    el.unansweredModal.hidden = false;
}

el.prev.addEventListener("click", async () => {
    if (state.page > 0) {
        state.page -= 1;
        await renderQuestions();
        scheduleSessionSave();
    }
});

el.next.addEventListener("click", async () => {
    const totalPages = state.pages.length;
    if (state.page < totalPages - 1) {
        state.page += 1;
        await renderQuestions();
        scheduleSessionSave();
    }
});

el.firstUnanswered.addEventListener("click", () => {
    openUnansweredModal();
});

el.closeUnansweredModal?.addEventListener("click", closeUnansweredModal);

el.unansweredModal?.addEventListener("click", async (event) => {
    if (event.target === el.unansweredModal) {
        closeUnansweredModal();
        return;
    }

    const button = event.target.closest("[data-question-index]");
    if (!button) {
        return;
    }

    const questionIndex = Number(button.dataset.questionIndex);
    closeUnansweredModal();
    await render(questionIndex);
    scheduleSessionSave();
});

window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && el.unansweredModal && !el.unansweredModal.hidden) {
        closeUnansweredModal();
    }
});

async function submitExam(message) {
    const correctCount = state.questions.reduce((count, question, index) => {
        return count + (state.answers[index] === Number(question.answer) ? 1 : 0);
    }, 0);
    const convertedScore = Math.round((correctCount / state.questions.length) * 1000) / 10;
    const passStatus = convertedScore >= 60 ? "합격" : "불합격";
    state.submitted = true;
    await render(state.pages[state.page]?.[0] ?? 0);
    if (state.timerId) {
        clearInterval(state.timerId);
        state.timerId = null;
    }
    await saveSession();
    alert(`${message ? `${message}\n` : ""}채점 결과
정답 수: ${correctCount}/${state.questions.length}
환산 점수: ${convertedScore}점
판정: ${passStatus}`);
}

el.submit.addEventListener("click", async () => {
    await submitExam();
});

el.backToStart?.addEventListener("click", async () => {
    if (!confirm("시험을 중단하고 선택페이지로 돌아가시겠습니까?")) {
        return;
    }

    await saveSession();
    window.location.href = "index.php";
});

window.addEventListener("resize", () => {
    if (!state.questions.length) {
        return;
    }

    window.clearTimeout(state.resizeTimerId);
    state.resizeTimerId = window.setTimeout(() => {
        render(state.pages[state.page]?.[0] ?? 0);
    }, 150);
});

window.addEventListener("beforeunload", () => {
    saveSession({ keepalive: true });
});

loadQuestions();
