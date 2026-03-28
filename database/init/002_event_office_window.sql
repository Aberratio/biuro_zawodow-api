ALTER TABLE events
    ADD COLUMN office_open_at DATETIME NOT NULL DEFAULT '2026-03-28 07:00:00' AFTER organization_id,
    ADD COLUMN office_close_at DATETIME NOT NULL DEFAULT '2026-03-28 18:00:00' AFTER office_open_at;

UPDATE events
SET
    office_open_at = CASE id
        WHEN 'evt-1' THEN '2026-03-28 07:00:00'
        WHEN 'evt-2' THEN '2026-05-18 06:30:00'
        WHEN 'evt-3' THEN '2026-06-07 05:30:00'
        ELSE CONCAT(event_date, ' 07:00:00')
    END,
    office_close_at = CASE id
        WHEN 'evt-1' THEN '2026-03-28 18:00:00'
        WHEN 'evt-2' THEN '2026-05-18 14:30:00'
        WHEN 'evt-3' THEN '2026-06-07 16:00:00'
        ELSE CONCAT(event_date, ' 17:00:00')
    END;
