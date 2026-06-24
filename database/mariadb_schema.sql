SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS qualification_levels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qualification_levels_name (name),
    UNIQUE KEY uq_qualification_levels_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certifications_level_name (level_id, name),
    UNIQUE KEY uq_certifications_level_slug (level_id, slug),
    CONSTRAINT fk_certifications_level
        FOREIGN KEY (level_id) REFERENCES qualification_levels(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_rounds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    certification_id INT UNSIGNED NOT NULL,
    round_name VARCHAR(100) NOT NULL,
    time_limit_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    slug VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_rounds_cert_name (certification_id, round_name),
    UNIQUE KEY uq_exam_rounds_cert_slug (certification_id, slug),
    CONSTRAINT fk_exam_rounds_certification
        FOREIGN KEY (certification_id) REFERENCES certifications(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_subjects (
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
    CONSTRAINT fk_exam_subjects_round
        FOREIGN KEY (round_id) REFERENCES exam_rounds(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    round_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NULL,
    question_number INT UNSIGNED NOT NULL,
    subject VARCHAR(150) NOT NULL DEFAULT '',
    question_text LONGTEXT NOT NULL,
    question_image VARCHAR(500) NULL,
    question_image_width_percent TINYINT UNSIGNED NULL,
    option_1_text LONGTEXT NULL,
    option_1_image VARCHAR(500) NULL,
    option_1_image_width_percent TINYINT UNSIGNED NULL,
    option_2_text LONGTEXT NULL,
    option_2_image VARCHAR(500) NULL,
    option_2_image_width_percent TINYINT UNSIGNED NULL,
    option_3_text LONGTEXT NULL,
    option_3_image VARCHAR(500) NULL,
    option_3_image_width_percent TINYINT UNSIGNED NULL,
    option_4_text LONGTEXT NULL,
    option_4_image VARCHAR(500) NULL,
    option_4_image_width_percent TINYINT UNSIGNED NULL,
    answer TINYINT UNSIGNED NOT NULL,
    explanation_text LONGTEXT NULL,
    explanation_image VARCHAR(500) NULL,
    explanation_image_width_percent TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_questions_round_number_subject (round_id, question_number, subject),
    KEY idx_exam_questions_round_number (round_id, question_number),
    KEY idx_exam_questions_subject (subject_id, question_number),
    CONSTRAINT fk_exam_questions_round
        FOREIGN KEY (round_id) REFERENCES exam_rounds(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_exam_questions_subject
        FOREIGN KEY (subject_id) REFERENCES exam_subjects(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_sessions (
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
    KEY idx_exam_sessions_updated_at (updated_at),
    KEY idx_exam_sessions_elective_subject (elective_subject_id),
    CONSTRAINT fk_exam_sessions_elective_subject
        FOREIGN KEY (elective_subject_id) REFERENCES exam_subjects(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
