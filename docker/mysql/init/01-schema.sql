CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin', 'student') NOT NULL DEFAULT 'student',
    username VARCHAR(80) DEFAULT NULL,
    full_name VARCHAR(120) NOT NULL,
    class_name VARCHAR(20) DEFAULT NULL,
    team_id INT DEFAULT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_admin_username (username),
    UNIQUE KEY uniq_student_identity (role, full_name, class_name)
);

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('new', 'in_progress', 'done') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(80) NOT NULL,
    title VARCHAR(150) NOT NULL,
    level_label VARCHAR(80) NOT NULL,
    status ENUM('draft', 'active', 'finished') NOT NULL DEFAULT 'draft',
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    winner_user_id INT DEFAULT NULL,
    winner_team_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rounds_winner_user FOREIGN KEY (winner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rounds_winner_team FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_rounds_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS round_completions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    round_id INT NOT NULL,
    user_id INT NOT NULL,
    team_id INT DEFAULT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_winner TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_round_completions_round FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
    CONSTRAINT fk_round_completions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_round_completions_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_round_user (round_id, user_id)
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    ip_address VARCHAR(64) NOT NULL,
    user_agent VARCHAR(255) NOT NULL,
    os_name VARCHAR(120) NOT NULL,
    browser_name VARCHAR(120) NOT NULL,
    device_type VARCHAR(30) NOT NULL,
    request_path VARCHAR(180) NOT NULL,
    request_method VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activity_logs_created_at (created_at)
);
