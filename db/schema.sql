-- F1 data schema (works on both SQLite + MariaDB).
-- Drivers and results are keyed by Ergast driver_id / constructor_id (stable across
-- all eras) rather than car number, which was reused by different drivers before
-- permanent numbers (2014). Championship standings come from the official Ergast
-- endpoints so dropped-scores seasons (pre-1991) rank correctly.

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
    driver_id VARCHAR(64) NOT NULL,
    year INTEGER NOT NULL,
    driver_number INTEGER,
    full_name VARCHAR(120),
    name_acronym VARCHAR(10),
    country_code VARCHAR(8),
    constructor_id VARCHAR(64),
    team_name VARCHAR(80),
    team_colour VARCHAR(10),
    fetched_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (driver_id, year)
);

CREATE TABLE IF NOT EXISTS session_results (
    session_key INTEGER NOT NULL,
    driver_id VARCHAR(64) NOT NULL,
    driver_number INTEGER,
    constructor_id VARCHAR(64),
    position INTEGER,
    points DOUBLE,
    number_of_laps INTEGER,
    duration DOUBLE,
    gap_to_leader DOUBLE,
    dnf INTEGER DEFAULT 0,
    dns INTEGER DEFAULT 0,
    dsq INTEGER DEFAULT 0,
    fetched_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (session_key, driver_id)
);

CREATE INDEX IF NOT EXISTS idx_results_session_pos ON session_results(session_key, position);
CREATE INDEX IF NOT EXISTS idx_results_constructor ON session_results(constructor_id);

CREATE TABLE IF NOT EXISTS driver_standings (
    year INTEGER NOT NULL,
    driver_id VARCHAR(64) NOT NULL,
    position INTEGER,
    points DOUBLE,
    wins INTEGER,
    fetched_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (year, driver_id)
);

CREATE TABLE IF NOT EXISTS constructor_standings (
    year INTEGER NOT NULL,
    constructor_id VARCHAR(64) NOT NULL,
    constructor_name VARCHAR(80),
    position INTEGER,
    points DOUBLE,
    wins INTEGER,
    fetched_at VARCHAR(32) NOT NULL,
    PRIMARY KEY (year, constructor_id)
);
