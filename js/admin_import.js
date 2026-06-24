const dataElement = document.querySelector("#admin-import-data");
const data = dataElement ? JSON.parse(dataElement.textContent) : {};
const catalog = data.catalog || [];
const posted = data.posted || {};

const el = {
    level: document.querySelector("#level-id"),
    certification: document.querySelector("#certification-id"),
    round: document.querySelector("#round-id"),
    subject: document.querySelector("#subject-id"),
    newLevelField: document.querySelector("#new-level-field"),
    newLevelName: document.querySelector("#new-level-name"),
    newCertificationField: document.querySelector("#new-certification-field"),
    newCertificationName: document.querySelector("#new-certification-name"),
    newRoundField: document.querySelector("#new-round-field"),
    newRoundName: document.querySelector("#new-round-name"),
    newSubjectField: document.querySelector("#new-subject-field"),
    newSubjectName: document.querySelector("#new-subject-name"),
    levelSlug: document.querySelector("#level-slug"),
    certificationSlug: document.querySelector("#certification-slug"),
    roundSlug: document.querySelector("#round-slug"),
    subjectSlug: document.querySelector("#subject-slug"),
    subjectType: document.querySelector("#subject-type"),
    electiveGroupField: document.querySelector("#elective-group-field"),
    electiveGroup: document.querySelector("#elective-group"),
    questionStart: document.querySelector("#question-start"),
    questionEnd: document.querySelector("#question-end"),
    questionCount: document.querySelector("#question-count"),
    timeLimit: document.querySelector("#time-limit"),
    pathPreview: document.querySelector("#path-preview"),
    replaceConfirm: document.querySelector("#replace-confirm"),
};

function option(value, text, selected = false) {
    const item = document.createElement("option");
    item.value = String(value);
    item.textContent = text;
    item.selected = selected;
    return item;
}

function selectedLevel() {
    return catalog.find((level) => Number(level.id) === Number(el.level.value)) || null;
}

function selectedCertification() {
    return selectedLevel()?.certifications.find(
        (certification) => Number(certification.id) === Number(el.certification.value)
    ) || null;
}

function selectedRound() {
    return selectedCertification()?.rounds.find(
        (round) => Number(round.id) === Number(el.round.value)
    ) || null;
}

function selectedSubject() {
    return selectedRound()?.subjects.find(
        (subject) => Number(subject.id) === Number(el.subject.value)
    ) || null;
}

function setNewField(field, input, visible) {
    field.hidden = !visible;
    input.required = visible;
    input.disabled = !visible;
}

function updatePathPreview() {
    const slugs = [el.levelSlug.value, el.certificationSlug.value, el.roundSlug.value]
        .map((slug) => slug.trim() || "...");
    const subjectSlug = el.subjectSlug.value.trim() || "...";
    el.pathPreview.textContent = `images/exams/${slugs.join("/")}/subjects/${subjectSlug}/`;
}

function syncElectiveGroup() {
    const elective = el.subjectType.value === "elective";
    el.electiveGroupField.hidden = !elective;
    el.electiveGroup.disabled = !elective;
    el.electiveGroup.required = elective;
    if (elective && !el.electiveGroup.value) {
        el.electiveGroup.value = "elective-1";
    }
}

function syncSubject({ preservePosted = false } = {}) {
    const subject = selectedSubject();
    const isNew = el.subject.value === "0";
    setNewField(el.newSubjectField, el.newSubjectName, isNew);

    if (subject) {
        el.subjectSlug.value = preservePosted && posted.subjectSlug ? posted.subjectSlug : subject.slug;
        el.subjectType.value = preservePosted && posted.subjectType ? posted.subjectType : subject.type;
        el.electiveGroup.value = preservePosted && posted.electiveGroup !== undefined
            ? posted.electiveGroup
            : subject.electiveGroup;
        el.questionStart.value = preservePosted && posted.questionStart ? posted.questionStart : subject.questionStart;
        el.questionEnd.value = preservePosted && posted.questionEnd ? posted.questionEnd : subject.questionEnd;
        el.questionCount.value = preservePosted && posted.questionCount ? posted.questionCount : subject.questionCount;
    } else if (isNew) {
        if (!preservePosted) {
            el.subjectSlug.value = "";
            el.subjectType.value = "common";
            el.electiveGroup.value = "elective-1";
            el.questionStart.value = "1";
            el.questionEnd.value = "60";
            el.questionCount.value = "60";
        }
    } else {
        el.subjectSlug.value = "";
    }

    syncElectiveGroup();
    updatePathPreview();
}

function renderSubjects({ preservePosted = false } = {}) {
    const round = selectedRound();
    const isNewRound = el.round.value === "0";
    el.subject.innerHTML = "";
    el.subject.append(option("", "선택하세요"));

    if (round) {
        round.subjects.forEach((subject) => {
            const typeLabel = subject.type === "elective" ? "선택" : "공통";
            el.subject.append(option(
                subject.id,
                `${subject.name} (${typeLabel}, ${subject.questionStart}~${subject.questionEnd})`,
                preservePosted && Number(posted.subjectId) === Number(subject.id)
            ));
        });
    }

    if (round || isNewRound) {
        el.subject.append(option(
            "0",
            "+ 새 과목 등록",
            preservePosted && Number(posted.subjectId) === 0 && data.wasPosted
        ));
        el.subject.disabled = false;
    } else {
        el.subject.disabled = true;
    }

    syncSubject({ preservePosted });
}

function renderRounds({ preservePosted = false } = {}) {
    const certification = selectedCertification();
    const isNewCertification = el.certification.value === "0";
    el.round.innerHTML = "";
    el.round.append(option("", "선택하세요"));

    if (certification) {
        certification.rounds.forEach((round) => {
            el.round.append(option(round.id, round.name, preservePosted && Number(posted.roundId) === Number(round.id)));
        });
    }

    if (certification || isNewCertification) {
        el.round.append(option("0", "+ 새 회차 등록", preservePosted && Number(posted.roundId) === 0 && data.wasPosted));
        el.round.disabled = false;
    } else {
        el.round.disabled = true;
    }

    syncRound({ preservePosted });
}

function syncRound({ preservePosted = false } = {}) {
    const round = selectedRound();
    const isNew = el.round.value === "0";
    setNewField(el.newRoundField, el.newRoundName, isNew);

    if (round) {
        el.roundSlug.value = preservePosted && posted.roundSlug ? posted.roundSlug : round.slug;
        el.timeLimit.value = round.timeLimitMinutes;
    } else if (isNew) {
        if (!preservePosted || !posted.roundSlug) {
            el.roundSlug.value = "round-01";
        }
        if (!el.timeLimit.value) {
            el.timeLimit.value = "60";
        }
    } else {
        el.roundSlug.value = "";
    }
    renderSubjects({ preservePosted });
    updatePathPreview();
}

function renderCertifications({ preservePosted = false } = {}) {
    const level = selectedLevel();
    const isNewLevel = el.level.value === "0";
    el.certification.innerHTML = "";
    el.certification.append(option("", "선택하세요"));

    if (level) {
        level.certifications.forEach((certification) => {
            el.certification.append(option(
                certification.id,
                certification.name,
                preservePosted && Number(posted.certificationId) === Number(certification.id)
            ));
        });
    }

    if (level || isNewLevel) {
        el.certification.append(option(
            "0",
            "+ 새 시험 등록",
            preservePosted && Number(posted.certificationId) === 0 && data.wasPosted
        ));
        el.certification.disabled = false;
    } else {
        el.certification.disabled = true;
    }

    syncCertification({ preservePosted });
}

function syncCertification({ preservePosted = false } = {}) {
    const certification = selectedCertification();
    const isNew = el.certification.value === "0";
    setNewField(el.newCertificationField, el.newCertificationName, isNew);

    if (certification) {
        el.certificationSlug.value = preservePosted && posted.certificationSlug
            ? posted.certificationSlug
            : certification.slug;
    } else if (!isNew) {
        el.certificationSlug.value = "";
    } else if (!preservePosted) {
        el.certificationSlug.value = "";
    }

    renderRounds({ preservePosted });
    updatePathPreview();
}

function syncLevel({ preservePosted = false } = {}) {
    const level = selectedLevel();
    const isNew = el.level.value === "0";
    setNewField(el.newLevelField, el.newLevelName, isNew);

    if (level) {
        el.levelSlug.value = preservePosted && posted.levelSlug ? posted.levelSlug : level.slug;
    } else if (!isNew) {
        el.levelSlug.value = "";
    } else if (!preservePosted) {
        el.levelSlug.value = "";
    }

    renderCertifications({ preservePosted });
    updatePathPreview();
}

function renderLevels() {
    el.level.innerHTML = "";
    el.level.append(option("", "선택하세요"));
    catalog.forEach((level) => {
        el.level.append(option(level.id, level.name, Number(posted.levelId) === Number(level.id)));
    });
    el.level.append(option("0", "+ 새 시험 분류 등록", Number(posted.levelId) === 0 && data.wasPosted));
    syncLevel({ preservePosted: true });
}

el.level.addEventListener("change", () => syncLevel());
el.certification.addEventListener("change", () => syncCertification());
el.round.addEventListener("change", () => syncRound());
el.subject.addEventListener("change", () => syncSubject());
el.subjectType.addEventListener("change", syncElectiveGroup);
[el.levelSlug, el.certificationSlug, el.roundSlug, el.subjectSlug].forEach((input) => {
    input.addEventListener("input", updatePathPreview);
});

document.querySelectorAll('input[name="import_mode"]').forEach((radio) => {
    radio.addEventListener("change", () => {
        const replace = radio.checked && radio.value === "replace";
        if (replace) {
            el.replaceConfirm.hidden = false;
        } else if (radio.checked) {
            el.replaceConfirm.hidden = true;
            el.replaceConfirm.querySelector("input").checked = false;
        }
    });
});

renderLevels();
