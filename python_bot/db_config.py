from __future__ import annotations

import os


def load_db_config() -> dict[str, object]:
    return {
        "host": os.getenv("PRODADMIN_DB_HOST", "localhost"),
        "port": int(os.getenv("PRODADMIN_DB_PORT", "5432")),
        "dbname": os.getenv("PRODADMIN_DB_NAME", "prod_admin"),
        "user": os.getenv("PRODADMIN_DB_USER", "postgres"),
        "password": os.getenv("PRODADMIN_DB_PASS", "SASMU123"),
    }
