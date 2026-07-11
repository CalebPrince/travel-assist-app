#!/usr/bin/env python3
"""Unified entry point: validates the environment, runs DB migrations, and
starts the built-in PHP development server for the Travel Assist platform."""

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
HOST = "127.0.0.1"
PORT = 8010


def check_requirements() -> None:
    if shutil.which("php") is None:
        sys.exit("ERROR: PHP was not found on PATH. Install PHP 8.1+ and try again.")

    result = subprocess.run(
        ["php", "-m"], cwd=ROOT, capture_output=True, text=True, check=False
    )
    if "pdo_sqlite" not in result.stdout:
        sys.exit("ERROR: the pdo_sqlite PHP extension is required but not enabled.")


def run_migrations() -> None:
    result = subprocess.run(["php", "database/migrate.php"], cwd=ROOT, check=False)
    if result.returncode != 0:
        sys.exit(result.returncode)


def start_server() -> None:
    print(f"Serving Travel Assist on http://{HOST}:{PORT}")
    subprocess.run(
        ["php", "-S", f"{HOST}:{PORT}", "-t", "public", "public/router.php"],
        cwd=ROOT,
        check=False,
    )


if __name__ == "__main__":
    check_requirements()
    run_migrations()
    start_server()
