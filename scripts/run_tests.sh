#!/usr/bin/env bash
# ==============================================================================
# Secure Neo-Brutalist LMS — Automated Test Suite Runner Harness
# Standard: OWASP ASVS v4.0.3 L2 & OWASP Top 10 (2021)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}"

echo "===================================================================="
echo "    Secure Neo-Brutalist LMS — Automated Test Suite Runner         "
echo "===================================================================="

# Check if php is available
if command -v php >/dev/null 2>&1; then
    PHP_BIN="php"
else
    echo "ERROR: 'php' command not found in PATH." >&2
    echo "If running outside container, please ensure PHP 8.4+ CLI is installed." >&2
    echo "Or run via Docker: docker compose exec web ./scripts/run_tests.sh" >&2
    exit 1
fi

# Run the test runner with all passed arguments
exec "${PHP_BIN}" "${PROJECT_ROOT}/tests/run.php" "$@"
