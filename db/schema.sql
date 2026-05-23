-- F1 data schema (works on both SQLite + MariaDB).

CREATE TABLE IF NOT EXISTS meetings (
    meeting_key INTEGER PRIMARY KEY,
    year INTEGER NOT NULL,
    meeting_name VARCHAR(120),
    meeting_official_name VARCHAR(200),
    country_code VARCHAR(8),
    country_name VARCHAR(80),
    location VARCHAR(80),
    circuit_short_name VARCHAR(80),
    date_start VARCHAR(32) NOT NULL,
    fetched_at VARCHAR(32) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_meetings_year ON meetings(year, date_start);

CREATE TABLE IF NOT EXISTS sessions (
    session_key INTEGER PRIMARY KEY,
    meeting_key INTEGER NOT NULL,
    session_name VARCHAR(80),
    session_type VARCHAR(40),
    date_start VARCHAR(32),
    date_end VARCHAR(32),
    fetched_at VARCHAR(32) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_meeting ON sessions(meeting_key, date_start);

CREATE TABLE IF NOT EXISTS drivers (
    driver_number INTEGER NOT NULL,
    year INTEGER NOT NULL,
    full_name VARCHAR(120),
    broadcast_name VARCHAR(120),
    name_acronym VARCHAR(10),
    team_name VARCHAR(80),
    team_colour VARCHAR(10),
    country_code VARCHAR(8),
    headshot_url VARCHAR(255),
    fetched_at VARCHAR(32) NOT NULL,
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
    fetched_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (session_key, driver_number)
);

CREATE INDEX IF NOT EXISTS idx_results_session_pos ON session_results(session_key, position);
