-- F1 data schema (works on both SQLite + MariaDB)
-- For MariaDB: replace AUTOINCREMENT with AUTO_INCREMENT and adjust types if needed.

CREATE TABLE IF NOT EXISTS meetings (
    meeting_key INTEGER PRIMARY KEY,
    year INTEGER NOT NULL,
    meeting_name TEXT,
    meeting_official_name TEXT,
    country_code TEXT,
    country_name TEXT,
    location TEXT,
    circuit_short_name TEXT,
    date_start TEXT NOT NULL,
    fetched_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_meetings_year ON meetings(year, date_start);

CREATE TABLE IF NOT EXISTS sessions (
    session_key INTEGER PRIMARY KEY,
    meeting_key INTEGER NOT NULL,
    session_name TEXT,
    session_type TEXT,
    date_start TEXT,
    date_end TEXT,
    fetched_at TEXT NOT NULL,
    FOREIGN KEY (meeting_key) REFERENCES meetings(meeting_key)
);

CREATE INDEX IF NOT EXISTS idx_sessions_meeting ON sessions(meeting_key, date_start);

CREATE TABLE IF NOT EXISTS drivers (
    driver_number INTEGER NOT NULL,
    year INTEGER NOT NULL,
    full_name TEXT,
    broadcast_name TEXT,
    name_acronym TEXT,
    team_name TEXT,
    team_colour TEXT,
    country_code TEXT,
    headshot_url TEXT,
    fetched_at TEXT NOT NULL,
    PRIMARY KEY (driver_number, year)
);

CREATE TABLE IF NOT EXISTS session_results (
    session_key INTEGER NOT NULL,
    driver_number INTEGER NOT NULL,
    position INTEGER,
    points REAL,
    number_of_laps INTEGER,
    duration REAL,
    gap_to_leader REAL,
    dnf INTEGER DEFAULT 0,
    dns INTEGER DEFAULT 0,
    dsq INTEGER DEFAULT 0,
    fetched_at TEXT NOT NULL,
    PRIMARY KEY (session_key, driver_number),
    FOREIGN KEY (session_key) REFERENCES sessions(session_key)
);

CREATE INDEX IF NOT EXISTS idx_results_session_pos ON session_results(session_key, position);
