#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Full local SonarQube scan of the current checkout (HEAD + uncommitted changes).
#
#   sonar-local.sh up     start a throwaway sonarqube:community server (idempotent)
#   sonar-local.sh scan   copy the repo, scan everything, print open issues + hotspots
#   sonar-local.sh down   remove the server and all cached state
#   sonar-local.sh cloud [branch]  print SonarCloud's quality gate + open issues
#                         for a branch, default main (needs SONAR_TOKEN in .env)
#   sonar-local.sh pr <number>     wait for SonarCloud to analyse the PR's head
#                         commit, then print its gate, issues and hotspots
#
# The repo is copied under ~/.cache (Docker on WSL can't see /tmp), because the
# JS analyzer dies on root-owned storage/framework/testing dirs in the real tree.
set -euo pipefail

NAME=fdc-release-sonar
PORT=9011
URL="http://localhost:${PORT}"
PROJECT=fdc-release
CLOUD_API="https://sonarcloud.io/api"
CLOUD_ORG=ckoval7
CLOUD_PROJECT=ckoval7_fd-commander
STATE_DIR="${HOME}/.cache/fdc-sonar"
STATE_FILE="${STATE_DIR}/state.env"
SRC_DIR="${STATE_DIR}/src"

api() { curl -fsS -u "admin:${SONAR_ADMIN_PASSWORD}" "$@"; }

wait_for_up() {
    printf 'Waiting for SonarQube'
    for _ in $(seq 1 120); do
        if [[ "$(curl -fsS "${URL}/api/system/status" 2>/dev/null | jq -r .status 2>/dev/null)" = "UP" ]]; then
            echo ' up'
            return 0
        fi
        printf '.'
        sleep 3
    done
    echo ' timed out' >&2
    exit 1
}

cloud_token() {
    local repo token
    repo="$(git rev-parse --show-toplevel)"
    token="$(grep -E '^SONAR_TOKEN=' "${repo}/.env" | cut -d= -f2- | tr -d '"')"
    [[ -n "${token}" ]] || { echo "SONAR_TOKEN missing from ${repo}/.env" >&2; exit 1; }
    echo "${token}"
}

# The community image's default "Sonar way" profiles are far looser than the
# "Sonar way comprehensive" profiles SonarCloud applies to this project, so copy
# SonarCloud's profiles in and make them the local defaults.
import_cloud_profiles() {
    local token local_langs lang name result
    token="$(cloud_token)"
    local_langs="$(api "${URL}/api/languages/list?ps=0" | jq -r '.languages[].key')"
    mkdir -p "${STATE_DIR}/profiles"
    echo "Importing SonarCloud quality profiles:"
    while IFS=$'\t' read -r lang name; do
        grep -qx "${lang}" <<<"${local_langs}" || continue
        [[ "${name}" = "Sonar way comprehensive" ]] \
            || echo "  WARNING: SonarCloud uses '${name}' for ${lang}, not 'Sonar way comprehensive'" >&2
        curl -fsS -u "${token}:" -G "${CLOUD_API}/qualityprofiles/backup" \
            --data-urlencode "language=${lang}" --data-urlencode "qualityProfile=${name}" \
            --data-urlencode "organization=${CLOUD_ORG}" -o "${STATE_DIR}/profiles/${lang}.xml"
        sed -i "s#<name>${name}</name>#<name>SonarCloud ${lang}</name>#" "${STATE_DIR}/profiles/${lang}.xml"
        result="$(api -X POST -F "backup=@${STATE_DIR}/profiles/${lang}.xml" "${URL}/api/qualityprofiles/restore")"
        api -X POST "${URL}/api/qualityprofiles/set_default" \
            --data-urlencode "language=${lang}" --data-urlencode "qualityProfile=SonarCloud ${lang}"
        printf '  %-16s %s rules (%s not available locally)\n' "${lang}" \
            "$(jq .ruleSuccesses <<<"${result}")" "$(jq .ruleFailures <<<"${result}")"
    done < <(curl -fsS -u "${token}:" "${CLOUD_API}/qualityprofiles/search?project=${CLOUD_PROJECT}&organization=${CLOUD_ORG}" \
        | jq -r '.profiles[] | [.language, .name] | @tsv')
}

# Print issue or hotspot arrays from the issues/hotspots search APIs as a table.
print_issues() { local json="$1"; jq -r '.[] | [(.impacts[0].severity // .severity), .type, .rule, ((.component | sub("^[^:]*:"; "")) + ":" + ((.line // 0) | tostring)), .message] | @tsv' <<<"${json}" | sort | column -t -s $'\t' || true; }
print_hotspots() { local json="$1"; jq -r '.[] | [.vulnerabilityProbability, .ruleKey, ((.component | sub("^[^:]*:"; "")) + ":" + ((.line // 0) | tostring)), .message] | @tsv' <<<"${json}" | column -t -s $'\t' || true; }

cmd_up() {
    mkdir -p "${STATE_DIR}"
    if [[ -f "${STATE_FILE}" ]] && docker ps --format '{{.Names}}' | grep -qx "${NAME}"; then
        echo "Already running at ${URL}"
        return 0
    fi
    docker rm -f "${NAME}" >/dev/null 2>&1 || true
    rm -f "${STATE_FILE}"
    docker run -d --name "${NAME}" -p "${PORT}:9000" sonarqube:community >/dev/null
    wait_for_up

    SONAR_ADMIN_PASSWORD="Fdc-$(openssl rand -hex 10)-Aa1!"
    curl -fsS -u admin:admin -X POST "${URL}/api/users/change_password" \
        --data-urlencode login=admin --data-urlencode previousPassword=admin \
        --data-urlencode "password=${SONAR_ADMIN_PASSWORD}"
    api -X POST "${URL}/api/projects/create" -d "project=${PROJECT}" -d "name=${PROJECT}" >/dev/null
    SONAR_SCAN_TOKEN="$(api -X POST "${URL}/api/user_tokens/generate" -d "name=scan-$(date +%s)" | jq -r .token)"

    import_cloud_profiles

    printf 'SONAR_ADMIN_PASSWORD=%q\nSONAR_SCAN_TOKEN=%q\n' "${SONAR_ADMIN_PASSWORD}" "${SONAR_SCAN_TOKEN}" > "${STATE_FILE}"
    chmod 600 "${STATE_FILE}"
    echo "SonarQube ready at ${URL} (admin password in ${STATE_FILE})"
}

copy_source() {
    local repo
    repo="$(git rev-parse --show-toplevel)"
    rm -rf "${SRC_DIR}"
    mkdir -p "${SRC_DIR}"
    git -C "${repo}" archive HEAD | tar -x -C "${SRC_DIR}"
    # Overlay uncommitted and untracked (non-ignored) files so the scan sees them.
    while IFS= read -r -d '' file; do
        if [[ -f "${repo}/${file}" ]]; then
            mkdir -p "${SRC_DIR}/$(dirname "${file}")"
            cp "${repo}/${file}" "${SRC_DIR}/${file}"
        else
            rm -f "${SRC_DIR}/${file}"
        fi
    done < <(git -C "${repo}" ls-files -z --modified --others --exclude-standard)
}

report() {
    local page=1 total=1 issues='[]' batch
    while [[ $(( (page - 1) * 500 )) -lt "${total}" ]]; do
        batch="$(api "${URL}/api/issues/search?componentKeys=${PROJECT}&issueStatuses=OPEN,CONFIRMED&ps=500&p=${page}")"
        total="$(jq .total <<<"${batch}")"
        issues="$(jq -s '.[0] + .[1].issues' <(echo "${issues}") <(echo "${batch}"))"
        page=$((page + 1))
    done
    local hotspots
    hotspots="$(api "${URL}/api/hotspots/search?project=${PROJECT}&status=TO_REVIEW&ps=500" | jq .hotspots)"
    jq -n --argjson i "${issues}" --argjson h "${hotspots}" '{issues: $i, hotspots: $h}' > "${STATE_DIR}/report.json"

    echo
    echo "== Issues: $(jq length <<<"${issues}") open =="
    print_issues "${issues}"
    echo
    echo "== Security hotspots: $(jq length <<<"${hotspots}") to review =="
    print_hotspots "${hotspots}"
    echo
    echo "== Quality gate: $(api "${URL}/api/qualitygates/project_status?projectKey=${PROJECT}" | jq -r .projectStatus.status) =="
}

sonarcloud_gap_checks() {
    # Rules SonarCloud has flagged here that the local analyzers don't implement.
    echo
    echo "== SonarCloud-only rule checks (not implemented by the local analyzers) =="
    echo "-- css:S9423 !important:"
    grep -rnE --include='*.css' '!important[[:space:]]*(;|\})' "${SRC_DIR}/resources" | sed "s#${SRC_DIR}/##" | cut -c1-200 || echo "   none"
    echo "-- shell:S6506 curl -L piped to a shell without --proto '=https' --tlsv1.2:"
    grep -rnE "curl[^|]*-[a-zA-Z]*L.*\|[[:space:]]*(sudo[[:space:]]+)?(-E[[:space:]]+)?(ba)?sh" --include='*.sh' "${SRC_DIR}" | grep -v -- "--proto" | sed "s#${SRC_DIR}/##" | cut -c1-200 || echo "   none"
    echo "-- Web:S6819 role=\"dialog\" (use native <dialog>):"
    grep -rn 'role="dialog"' "${SRC_DIR}/resources/views" | sed "s#${SRC_DIR}/##" | cut -c1-200 || echo "   none"
    echo "-- php:S1172 unused closure params: not greppable, eyeball closures in changed files."
}

cmd_scan() {
    [[ -f "${STATE_FILE}" ]] || { echo "Run '$0 up' first" >&2; exit 1; }
    # shellcheck disable=SC1090
    source "${STATE_FILE}"
    copy_source
    # Mirror .sonarcloud.properties so local scope matches SonarCloud's.
    docker run --rm --network host -u "$(id -u):$(id -g)" \
        -v "${SRC_DIR}:/usr/src" \
        -e SONAR_HOST_URL="${URL}" -e SONAR_TOKEN="${SONAR_SCAN_TOKEN}" \
        sonarsource/sonar-scanner-cli \
        -Dsonar.projectKey="${PROJECT}" \
        -Dsonar.organization= \
        -Dsonar.sources=. \
        -Dsonar.tests=tests \
        -Dsonar.test.inclusions='tests/**/*.php' \
        -Dsonar.exclusions='node_modules/**,vendor/**,storage/**,bootstrap/cache/**,tests/**,public/build/**,.env,.env.*' \
        -Dsonar.cpd.exclusions='config/**/*.php,database/factories/**/*.php,database/seeders/**/*.php,database/migrations/**/*.php,app/Http/Requests/**/*.php,app/Scoring/Rules/**/*.php' \
        -Dsonar.coverage.exclusions='**/*' \
        -Dsonar.scm.disabled=true \
        -Dsonar.qualitygate.wait=true \
        -Dsonar.qualitygate.timeout=600 \
        > "${STATE_DIR}/scanner.log" 2>&1 || {
            if ! grep -q 'QUALITY GATE STATUS' "${STATE_DIR}/scanner.log"; then
                tail -30 "${STATE_DIR}/scanner.log" >&2
                echo "Scanner failed; full log in ${STATE_DIR}/scanner.log" >&2
                exit 1
            fi
        }
    report
    sonarcloud_gap_checks
    echo
    echo "Full JSON: ${STATE_DIR}/report.json  Scanner log: ${STATE_DIR}/scanner.log  UI: ${URL}"
}

cmd_cloud() {
    local token branch="${1:-main}"
    token="$(cloud_token)"
    local base="${CLOUD_API}" cloud_project="${CLOUD_PROJECT}"
    echo "== SonarCloud quality gate (${branch}): $(curl -fsS -u "${token}:" "${base}/qualitygates/project_status?projectKey=${cloud_project}&branch=${branch}" | jq -r .projectStatus.status) =="
    curl -fsS -u "${token}:" "${base}/project_analyses/search?project=${cloud_project}&branch=${branch}&ps=1" \
        | jq -r '.analyses[0] | "Last analysis: \(.date) (\(.revision // "unknown" | .[0:7]))"'
    git fetch -q origin "${branch}" 2>/dev/null || true
    echo "HEAD of origin/${branch}: $(git rev-parse --short "origin/${branch}" 2>/dev/null || echo unknown)"
    local issues
    issues="$(curl -fsS -u "${token}:" "${base}/issues/search?componentKeys=${cloud_project}&branch=${branch}&issueStatuses=OPEN,CONFIRMED&ps=500")"
    echo
    echo "== SonarCloud issues: $(jq .total <<<"${issues}") open =="
    print_issues "$(jq .issues <<<"${issues}")"
    local hotspots
    hotspots="$(curl -fsS -u "${token}:" "${base}/hotspots/search?projectKey=${cloud_project}&branch=${branch}&status=TO_REVIEW&ps=500")"
    echo
    echo "== SonarCloud hotspots: $(jq '.paging.total' <<<"${hotspots}") to review =="
    print_hotspots "$(jq .hotspots <<<"${hotspots}")"
}

cmd_pr() {
    local pr="${1:?Usage: $0 pr <number>}" token head analysed status issues hotspots
    token="$(cloud_token)"
    head="$(gh pr view "${pr}" --json headRefOid -q .headRefOid)"
    printf 'Waiting for SonarCloud to analyse PR #%s at %s' "${pr}" "${head:0:7}"
    for _ in $(seq 1 120); do
        analysed="$(gh api "repos/{owner}/{repo}/commits/${head}/check-runs?check_name=SonarCloud%20Code%20Analysis" \
            -q '.check_runs[0].status // empty' 2>/dev/null || true)"
        [[ "${analysed}" = "completed" ]] && break
        printf '.'
        sleep 15
    done
    echo
    [[ "${analysed}" = "completed" ]] || { echo "Timed out waiting for the SonarCloud check" >&2; exit 1; }
    status="$(curl -fsS -u "${token}:" "${CLOUD_API}/qualitygates/project_status?projectKey=${CLOUD_PROJECT}&pullRequest=${pr}" | jq -r .projectStatus.status)"
    echo "== SonarCloud quality gate (PR #${pr}): ${status} =="
    issues="$(curl -fsS -u "${token}:" "${CLOUD_API}/issues/search?componentKeys=${CLOUD_PROJECT}&pullRequest=${pr}&issueStatuses=OPEN,CONFIRMED&ps=500")"
    echo
    echo "== SonarCloud issues: $(jq .total <<<"${issues}") open =="
    print_issues "$(jq .issues <<<"${issues}")"
    hotspots="$(curl -fsS -u "${token}:" "${CLOUD_API}/hotspots/search?projectKey=${CLOUD_PROJECT}&pullRequest=${pr}&status=TO_REVIEW&ps=500")"
    echo
    echo "== SonarCloud hotspots: $(jq '.paging.total' <<<"${hotspots}") to review =="
    print_hotspots "$(jq .hotspots <<<"${hotspots}")"
    [[ "${status}" = "OK" ]] && [[ "$(jq .total <<<"${issues}")" = "0" ]] && [[ "$(jq '.paging.total' <<<"${hotspots}")" = "0" ]]
}

cmd_down() {
    docker rm -f "${NAME}" >/dev/null 2>&1 || true
    rm -rf "${STATE_DIR}"
    echo "Removed ${NAME} and ${STATE_DIR}"
}

case "${1:-}" in
    up) cmd_up ;;
    scan) cmd_scan ;;
    down) cmd_down ;;
    cloud) cmd_cloud "${2:-main}" ;;
    pr) cmd_pr "${2:-}" ;;
    *) echo "Usage: $0 {up|scan|down|cloud [branch]|pr <number>}" >&2; exit 2 ;;
esac
