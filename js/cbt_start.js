const startDataElement = document.querySelector("#cbt-start-data");
const startData = startDataElement ? JSON.parse(startDataElement.textContent) : {};
const examCatalog = startData.examCatalog || {};
const selectedExam = startData.selectedExam || "";
const selectedElectiveSubjectId = Number(startData.selectedElectiveSubjectId || 0);
const categorySelect = document.querySelector("#category");
const examSelect = document.querySelector("#exam");
const examSummary = document.querySelector("#exam-summary");
const electiveField = document.querySelector("#elective-field");
const electiveSelect = document.querySelector("#elective_subject");
const examNumberInput = document.querySelector("#exam_number");
const clearExamNumberButton = document.querySelector(".clear-input");

function getSelectedExam() {
    return examCatalog[categorySelect.value]?.[examSelect.value] || null;
}

function renderExamSummary() {
    const exam = getSelectedExam();
    if (!exam || !examSummary) {
        if (examSummary) {
            examSummary.textContent = "";
            examSummary.hidden = true;
        }
        return;
    }

    const subjects = exam.subjects || [];
    const electiveSubjects = subjects.filter((subject) => subject.type === "elective");
    const commonSubjects = subjects.filter((subject) => subject.type !== "elective");
    const subjectText = commonSubjects
        .map((subject) => `${subject.name} ${subject.questionCount}문제`)
        .join(", ");
    const electiveText = electiveSubjects.length
        ? `선택과목 ${electiveSubjects.map((subject) => subject.name).join(", ")} 중 1과목 선택`
        : "";
    const timeText = exam.timeLimitMinutes ? `제한시간 ${exam.timeLimitMinutes}분` : "";
    const parts = [timeText, subjectText, electiveText].filter(Boolean);

    examSummary.textContent = parts.length ? parts.join(" · ") : "";
    examSummary.hidden = !parts.length;
}

function renderElectiveOptions() {
    const exam = getSelectedExam();
    const electiveSubjects = (exam?.subjects || []).filter((subject) => subject.type === "elective");

    if (!electiveField || !electiveSelect) {
        return;
    }

    electiveSelect.innerHTML = "";
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "선택하세요";
    electiveSelect.append(placeholder);

    if (!electiveSubjects.length) {
        electiveField.hidden = true;
        electiveSelect.required = false;
        electiveSelect.disabled = true;
        return;
    }

    electiveField.hidden = false;
    electiveSelect.required = true;
    electiveSelect.disabled = false;
    electiveSubjects.forEach((subject) => {
        const option = document.createElement("option");
        option.value = String(subject.id);
        option.textContent = subject.name;
        option.selected = Number(subject.id) === selectedElectiveSubjectId;
        electiveSelect.append(option);
    });
}

function renderExamOptions() {
    const category = categorySelect.value;
    const exams = category ? Object.keys(examCatalog[category] || {}) : [];
    examSelect.innerHTML = "";

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "선택하세요";
    examSelect.append(placeholder);

    if (!exams.length) {
        examSelect.disabled = !category;
        if (category) {
            placeholder.textContent = "선택 가능한 시험이 없습니다";
        }
        if (examSummary) {
            examSummary.textContent = "";
            examSummary.hidden = true;
        }
        renderElectiveOptions();
        return;
    }

    examSelect.disabled = false;
    exams.forEach((examName) => {
        const option = document.createElement("option");
        option.value = examName;
        option.textContent = examName;
        option.selected = examName === selectedExam;
        examSelect.append(option);
    });
    renderElectiveOptions();
    renderExamSummary();
}

function syncClearExamNumberButton() {
    clearExamNumberButton.classList.toggle("is-visible", examNumberInput.value.length > 0);
}

categorySelect.addEventListener("change", renderExamOptions);
examSelect.addEventListener("change", () => {
    renderElectiveOptions();
    renderExamSummary();
});
examNumberInput.addEventListener("input", syncClearExamNumberButton);
clearExamNumberButton.addEventListener("click", () => {
    examNumberInput.value = "";
    examNumberInput.focus();
    syncClearExamNumberButton();
});

renderExamOptions();
syncClearExamNumberButton();
