#!/usr/bin/env bash
set -euo pipefail

base_ref="${PINT_BASE_REF:-}"
files=()

append_files_from() {
    while IFS= read -r file; do
        [[ -n "$file" ]] && files+=("$file")
    done
}

if [[ -n "$base_ref" ]] && git rev-parse --verify "$base_ref" >/dev/null 2>&1; then
    append_files_from < <(git diff --name-only --diff-filter=ACMR "$base_ref"...HEAD -- '*.php')
elif git rev-parse --verify origin/main >/dev/null 2>&1; then
    append_files_from < <(git diff --name-only --diff-filter=ACMR origin/main...HEAD -- '*.php')
elif git rev-parse --verify HEAD~1 >/dev/null 2>&1; then
    append_files_from < <(git diff --name-only --diff-filter=ACMR HEAD~1...HEAD -- '*.php')
else
    append_files_from < <(git ls-files '*.php')
fi

if [[ "${PINT_INCLUDE_WORKTREE:-1}" == "1" ]]; then
    append_files_from < <(git diff --name-only --diff-filter=ACMR HEAD -- '*.php')
    append_files_from < <(git ls-files --others --exclude-standard '*.php')
fi

if [[ ${#files[@]} -gt 0 ]]; then
    unique_files=()
    while IFS= read -r file; do
        [[ -n "$file" ]] && unique_files+=("$file")
    done < <(printf '%s\n' "${files[@]}" | sort -u)
    files=("${unique_files[@]}")
fi

if [[ ${#files[@]} -eq 0 ]]; then
    echo "No changed PHP files to check with Pint."
    exit 0
fi

vendor/bin/pint --test "${files[@]}"
