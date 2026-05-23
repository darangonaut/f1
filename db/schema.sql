-- F1 data schema (works on both SQLite + MariaDB)
-- For MariaDB: replace AUTOINCREMENT with AUTO_INCREMENT and adjust types if needed.

CREATE TABLE IF NOT EXISTS meetings (
    meeting_key INTEGER PRIMARY KEY,
    year INTEGER NOT NULL,
    meeting_name VARCHAR(255),
    meeting_official_name VARCHAR(255),
    country_code VARCHAR(255),
    country_name VARCHAR(255),
    location VARCHAR(255),
    circuit_short_name VARCHAR(255),
    date_start VARCHAR(255) NOT NULL,
    fetched_at VARCHAR(255) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_meetings_year ON meetings(year, date_start);

CREATE TABLE IF NOT EXISTS sessions (
    session_key INTEGER PRIMARY KEY,
    meeting_key INTEGER NOT NULL,
    session_name VARCHAR(255),
    session_type VARCHAR(255),
    date_start VARCHAR(255),
    date_end VARCHAR(255),
    fetched_at VARCHAR(255) NOT NULL,
    FOREIGN KEY (meeting_key) REFERENCES meetings(meeting_key)
);

CREATE INDEX IF NOT EXISTS idx_sessions_meeting ON sessions(meeting_key, date_start);

CREATE TABLE IF NOT EXISTS drivers (
    driver_number INTEGER NOT NULL,
    year INTEGER NOT NULL,
    full_name VARCHAR(255),
    broadcast_name VARCHAR(255),
    name_acronym VARCHAR(255),
    team_name VARCHAR(255),
    team_colour VARCHAR(255),
    country_code VARCHAR(255),
    headshot_url VARCHAR(255),
    fetched_at VARCHAR(255) NOT NULL,
    PRIMARY KEY (driver_number, year)
);

CREATE TABLE IF NOT EXISTS session_results (
    session_key INTEGER NOT NULL,
    driver_number INTEGER NOT NULL,
    position INTEGER,
    points DOUBLE,
    number_of_laps INTEGER,
    duration DOUBLE,
    gap_to_leader DOUBLE,
    dnf INTEGER DEFAULT 0,
    dns INTEGER DEFAULT 0,
    dsq INTEGER DEFAULT 0,
    fetched_at VARCHAR(255) NOT NULL,
    PRIMARY KEY (session_key, driver_number),
    FOREIGN KEY (session_key) REFERENCES sessions(session_key)
);

CREATE INDEX IF NOT EXISTS idx_results_session_pos ON session_results(session_key, position);
