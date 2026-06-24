const CONFIG = window.CBT_ADMIN_CONFIG || {};
const API_BASE = CONFIG.apiBase || "admin_api.php";

const state = {
    catalog: {},
    selectedExam: null,
    questions: [],
    selectedQuestionId: null,
};

const el = {
    level: document.querySelector("#level-select"),
    exam: document.querySelector("#exam-select"),
    reload: document.querySelector("#reload-questions"),
    list: document.querySelector("#question-list"),
    count: document.querySelector("#question-count"),
    form: document.querySelector("#question-form"),
    questionId: document.querySelector("#question-id"),
    title: document.querySelector("#editor-title"),
    meta: document.querySelector("#selected-meta"),
    save: document.querySelector("#save-question"),
    preview: document.querySelector("#preview-exam"),
    toast: document.querySelector("#toast"),
};

function showToast(message, type = "info") {
    el.toast.textContent = message;
    el.toast.className = `toast is-visible ${type}`;
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => {
        el.toast.className = "toast";
    }, 2600);
}

function apiUrl(action, params = {}) {
    const url = new URL(API_BASE, window.location.href);
    url.searchParams.set("action", action);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
            url.searchParams.set(key, value);
        }
    });
    return url;
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            Accept: "application/json",
            ...(options.headers || {}),
        },
        ...options,
    });
    const data = await response.json();
    if (!response.ok || data.error) {
        throw new Error(data.detail || data.error || "요청을 처리하지 못했습니다.");
    }
    return data;
}

function selectedExamFromControls() {
    const exams = state.catalog[el.level.value] || [];
    return exams[Number(el.exam.value)] || null;
}

function renderExamOptions() {
    const exams = state.catalog[el.level.value] || [];
    el.exam.innerHTML = exams.map((exam, index) => (
        `<option value="${index}">${escapeHtml(exam.label)}</option>`
    )).join("");
    state.selectedExam = selectedExamFromControls();
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function shortText(value) {
    const text = String(value || "").replace(/\s+/g, " ").trim();
    return text.length > 68 ? `${text.slice(0, 68)}...` : text;
}

async function loadCatalog() {
    const data = await fetchJson(apiUrl("catalog"));
    state.catalog = data.catalog || {};

    const levels = Object.keys(state.catalog);
    el.level.innerHTML = levels.map((level) => (
        `<option value="${escapeHtml(level)}">${escapeHtml(level)}</option>`
    )).join("");

    if (!levels.length) {
        el.list.innerHTML = '<p class="empty-state">등록된 시험이 없습니다.</p>';
        return;
    }

    renderExamOptions();
    await loadQuestions();
}

async function loadQuestions() {
    state.selectedExam = selectedExamFromControls();
    if (!state.selectedExam) {
        el.list.innerHTML = '<p class="empty-state">시험을 선택해주세요.</p>';
        return;
    }

    el.list.innerHTML = '<p class="empty-state">문제를 불러오는 중입니다.</p>';
    const data = await fetchJson(apiUrl("questions", {
        level: el.level.value,
        certification: state.selectedExam.certification,
        round: state.selectedExam.round,
    }));

    state.questions = data.questions || [];
    state.selectedQuestionId = null;
    el.count.textContent = `${state.questions.length}문제`;
    renderQuestionList();
    clearEditor();
}

function renderQuestionList() {
    if (!state.questions.length) {
        el.list.innerHTML = '<p class="empty-state">이 시험에는 문제가 없습니다.</p>';
        return;
    }

    el.list.innerHTML = state.questions.map((question) => {
        const active = Number(question.id) === Number(state.selectedQuestionId) ? " is-active" : "";
        return `
            <button class="question-list-item${active}" type="button" data-id="${question.id}">
                <span class="question-no">${question.question_number}</span>
                <span class="question-summary">${escapeHtml(shortText(question.question_text))}</span>
                <span class="answer-chip">정답 ${question.answer}</span>
            </button>
        `;
    }).join("");
}

function clearEditor() {
    el.form.reset();
    el.questionId.value = "";
    el.title.textContent = "문제 수정";
    el.meta.textContent = "문제를 선택해주세요.";
    el.save.disabled = true;
}

function setField(name, value) {
    const field = el.form.elements[name];
    if (field) {
        field.value = value ?? "";
    }
}

async function selectQuestion(id) {
    const data = await fetchJson(apiUrl("question", { id }));
    const question = data.question;
    state.selectedQuestionId = Number(question.id);
    renderQuestionList();

    el.questionId.value = question.id;
    el.title.textContent = `${question.question_number}번 문제 수정`;
    el.meta.textContent = `${question.level_name} / ${question.certification_name} / ${question.round_name}`;
    el.save.disabled = false;

    [
        "subject",
        "question_text",
        "question_image",
        "question_image_width_percent",
        "option_1_text",
        "option_1_image",
        "option_1_image_width_percent",
        "option_2_text",
        "option_2_image",
        "option_2_image_width_percent",
        "option_3_text",
        "option_3_image",
        "option_3_image_width_percent",
        "option_4_text",
        "option_4_image",
        "option_4_image_width_percent",
        "answer",
        "explanation_text",
        "explanation_image",
        "explanation_image_width_percent",
    ].forEach((name) => setField(name, question[name]));
}

function formPayload() {
    const formData = new FormData(el.form);
    const payload = {};
    formData.forEach((value, key) => {
        payload[key] = value;
    });
    payload.id = Number(el.questionId.value);
    payload.answer = Number(payload.answer);
    return payload;
}

async function saveQuestion() {
    if (!el.questionId.value) {
        showToast("저장할 문제를 선택해주세요.", "error");
        return;
    }

    const savedQuestionId = Number(el.questionId.value);
    el.save.disabled = true;
    try {
        await fetchJson(apiUrl("update"), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(formPayload()),
        });
        showToast("문제를 저장했습니다.", "success");
        await loadQuestions();
        await selectQuestion(savedQuestionId);
    } catch (error) {
        showToast(error.message, "error");
        el.save.disabled = false;
    }
}

function openPreview() {
    state.selectedExam = selectedExamFromControls();
    if (!state.selectedExam) {
        showToast("먼저 시험을 선택해주세요.", "error");
        return;
    }

    const url = new URL("main.php", window.location.href);
    url.searchParams.set("level", el.level.value);
    url.searchParams.set("certification", state.selectedExam.certification);
    url.searchParams.set("round", state.selectedExam.round);
    url.searchParams.set("applicant_name", "관리자");
    url.searchParams.set("exam_number", "00000000");
    window.open(url, "_blank", "noopener");
}

el.level.addEventListener("change", async () => {
    renderExamOptions();
    await loadQuestions().catch((error) => showToast(error.message, "error"));
});

el.exam.addEventListener("change", async () => {
    await loadQuestions().catch((error) => showToast(error.message, "error"));
});

el.reload.addEventListener("click", async () => {
    await loadQuestions().catch((error) => showToast(error.message, "error"));
});

el.list.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-id]");
    if (!button) {
        return;
    }
    await selectQuestion(button.dataset.id).catch((error) => showToast(error.message, "error"));
});

el.save.addEventListener("click", () => {
    saveQuestion();
});

el.preview.addEventListener("click", openPreview);

loadCatalog().catch((error) => {
    showToast(error.message, "error");
    el.list.innerHTML = '<p class="empty-state">관리자 데이터를 불러오지 못했습니다.</p>';
});
