-- ============================================================
-- Migration v2: Scale to 100 Users
-- Run ONCE on existing prod_admin databases.
-- Safe to re-run (all steps are idempotent).
-- ============================================================

-- Step 1: Clean up duplicate sessions for the same machine
-- (keeps the most recently created session per machine)
DELETE FROM sessions s1
USING sessions s2
WHERE s1.machine_id = s2.machine_id
  AND s1.created_at < s2.created_at;

-- Step 2: Add UNIQUE constraint on sessions.machine_id
-- Ensures only one active session per machine at DB level.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sessions_machine_id_key'
          AND conrelid = 'sessions'::regclass
    ) THEN
        ALTER TABLE sessions ADD CONSTRAINT sessions_machine_id_key UNIQUE (machine_id);
        RAISE NOTICE 'Added UNIQUE constraint on sessions.machine_id';
    ELSE
        RAISE NOTICE 'UNIQUE constraint on sessions.machine_id already exists, skipping';
    END IF;
END $$;

-- Step 3: Add UNIQUE constraint on takeover_requests (username, machine_id, requester_token)
-- Required for ON CONFLICT to work correctly in timeout logging.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'takeover_requests_username_machine_id_requester_token_key'
          AND conrelid = 'takeover_requests'::regclass
    ) THEN
        ALTER TABLE takeover_requests
            ADD CONSTRAINT takeover_requests_username_machine_id_requester_token_key
            UNIQUE (username, machine_id, requester_token);
        RAISE NOTICE 'Added UNIQUE constraint on takeover_requests';
    ELSE
        RAISE NOTICE 'UNIQUE constraint on takeover_requests already exists, skipping';
    END IF;
END $$;

-- Step 4: Add index on audit_logs(actor) for admin filter queries
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor  ON audit_logs(actor);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);

-- Step 5: Clean up old takeover_requests (older than 30 days)
DELETE FROM takeover_requests
WHERE created_at < NOW() - INTERVAL '30 days';

-- Step 6: Clean up expired sessions
DELETE FROM sessions WHERE expires_at < NOW();

-- Verification
DO $$
DECLARE
    v_sessions_unique  BOOLEAN;
    v_takeover_unique  BOOLEAN;
BEGIN
    SELECT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'sessions_machine_id_key'
          AND conrelid = 'sessions'::regclass
    ) INTO v_sessions_unique;

    SELECT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'takeover_requests_username_machine_id_requester_token_key'
          AND conrelid = 'takeover_requests'::regclass
    ) INTO v_takeover_unique;

    RAISE NOTICE '=== Migration v2 Result ===';
    RAISE NOTICE 'sessions.machine_id UNIQUE : %', CASE WHEN v_sessions_unique  THEN 'OK' ELSE 'MISSING' END;
    RAISE NOTICE 'takeover_requests UNIQUE   : %', CASE WHEN v_takeover_unique  THEN 'OK' ELSE 'MISSING' END;
    RAISE NOTICE '===========================';
END $$;
