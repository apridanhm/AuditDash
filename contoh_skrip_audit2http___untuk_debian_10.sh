#!/usr/bin/env bash
# audit2http.sh (robust PATH extractor + debug dump)
set -uo pipefail
umask 022

# --- Konfigurasi dasar ---
DASH_URL="${DASH_URL:-http://ip_dashboard/webhook.php}"
TOKEN="${TOKEN:-token_dari_dash}"     # ganti via env kalau perlu
KEY_FILTER="${KEY_FILTER:-key_ganti_ya}"

# (Opsional) filter ekstensi: EXT_ALLOW="php,js,css" atau EXT_DENY="tmp,swp"
EXT_ALLOW="${EXT_ALLOW:-}"
EXT_DENY="${EXT_DENY:-}"

# (Opsional) simpan blok serial yg gagal extract path
DEBUG_DUMP="${DEBUG_DUMP:-1}"

LOG="${LOG:-/var/log/audit2http.log}"
RAW="${RAW:-/var/log/audit2http.raw}"

HOST="${HOST:-$(hostname -s 2>/dev/null || hostname)}"
IP="${IP:-$(ip -4 addr show scope global | awk '/inet /{print $2}' | cut -d/ -f1 \
      | grep -E "^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)" | head -n1)}"
[ -z "${IP:-}" ] && IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

mkdir -p "$(dirname "$LOG")"
touch "$LOG" "$RAW"
chmod 0644 "$LOG" "$RAW"

log(){ printf '%s [%s] %s\n' "$(date -Is)" "$HOST" "$*" >>"$LOG"; }
log "plugin-start host=$HOST ip=${IP:-?} url=$DASH_URL key=$KEY_FILTER"

# Map serial -> file buffer
declare -A TMP

process_block() {
  local serial="$1" file="${TMP[$serial]}"
  [ -z "$file" ] && return 0

  # wajib ada key
  if ! grep -q "key=\"$KEY_FILTER\"" "$file"; then
    rm -f "$file"; unset 'TMP[$serial]'; return 0
  fi

  # waktu
  local epoch iso
  epoch=$(sed -n 's/.*audit(\([0-9]\+\).*/\1/p' "$file" | head -n1)
  [ -n "$epoch" ] && iso=$(date -Is -d "@$epoch") || iso="$(date -Is)"

  # syscall -> action
  local syscall action
  syscall=$(grep -m1 -o 'SYSCALL=[^ ]*' "$file" | cut -d= -f2)
  if grep -q 'nametype=DELETE' "$file" || [[ "${syscall:-}" =~ ^unlink ]]; then
    action=delete
  elif grep -q 'nametype=CREATE' "$file" || [[ "${syscall:-}" =~ ^creat ]]; then
    action=create
  else
    action=write
  fi

  # ===== Ambil file_path (prioritas NORMAL -> non-PARENT -> last any) =====
  local file_path
  file_path="$(grep -F 'type=PATH' "$file" 2>/dev/null \
              | sed -n 's/.*name="\([^"]\+\)".*nametype=NORMAL.*/\1/p' \
              | head -n1)"
  if [ -z "${file_path:-}" ]; then
    file_path="$(grep -F 'type=PATH' "$file" 2>/dev/null \
                | grep -Fv 'nametype=PARENT' \
                | sed -n 's/.*name="\([^"]\+\)".*/\1/p' \
                | tail -n1)"
  fi
  if [ -z "${file_path:-}" ]; then
    file_path="$(grep -F 'type=PATH' "$file" 2>/dev/null \
                | sed -n 's/.*name="\([^"]\+\)".*/\1/p' \
                | tail -n1)"
  fi

  # field lain
  local cwd comm exe auid uid
  cwd=$(sed -n 's/.*type=CWD .* cwd="\([^"]*\)".*/\1/p' "$file" | head -n1)
  comm=$(sed -n 's/.*type=SYSCALL .* comm="\([^"]*\)".*/\1/p' "$file" | head -n1)
  exe=$(sed -n 's/.*type=SYSCALL .* exe="\([^"]*\)".*/\1/p' "$file" | head -n1)
  auid=$(sed -n 's/.*AUID="\([^"]*\)".*/\1/p' "$file" | head -n1)
  uid=$(sed -n 's/.*UID="\([^"]*\)".*/\1/p' "$file" | head -n1)
  [ -z "$auid" ] && auid=$(sed -n 's/.* auid=\([^ ]*\).*/\1/p' "$file" | head -n1)
  [ -z "$uid"  ] && uid=$(sed -n 's/.*  uid=\([^ ]*\).*/\1/p' "$file" | head -n1)

  # Filter ekstensi (opsional)
  if [ -n "${EXT_ALLOW}${EXT_DENY}" ] && [ -n "${file_path:-}" ]; then
    bname="${file_path##*/}"
    ext=""
    case "$bname" in *.*) ext="${bname##*.}";; esac
    ext="${ext,,}"
    if [ -n "$EXT_ALLOW" ]; then
      alist=",$(echo "$EXT_ALLOW" | tr '[:upper:]' '[:lower:]' | tr -d ' '),"
      if [ -n "$ext" ] && [[ "$alist" != *",$ext,"* ]]; then
        log "SKIP serial=$serial: ext=$ext not-in-allow"
        rm -f "$file"; unset 'TMP[$serial]'; return 0
      fi
    fi
    if [ -n "$EXT_DENY" ]; then
      dlist=",$(echo "$EXT_DENY" | tr '[:upper:]' '[:lower:]' | tr -d ' '),"
      if [ -n "$ext" ] && [[ "$dlist" == *",$ext,"* ]]; then
        log "SKIP serial=$serial: ext=$ext in-deny"
        rm -f "$file"; unset 'TMP[$serial]'; return 0
      fi
    fi
  fi

  if [ -z "${file_path:-}" ]; then
    log "WARN serial=$serial: no file_path extracted"
    if [ "${DEBUG_DUMP:-0}" = "1" ]; then
      cp -f "$file" "/var/log/audit2http.block.$serial"
    fi
  fi

  # Build JSON & POST
  local json
  json=$(printf '{"host":"%s","ip":"%s","time_event":"%s","serial":"%s","key":"%s","user_auid":"%s","user_uid":"%s","comm":"%s","exe":"%s","syscall":"%s","action":"%s","file":"%s","cwd":"%s"}' \
        "$HOST" "${IP:-}" "$iso" "$serial" "$KEY_FILTER" "${auid:-}" "${uid:-}" \
        "${comm:-}" "${exe:-}" "${syscall:-}" "${action:-}" "${file_path:-}" "${cwd:-}")

  ( printf '%s' "$json" | timeout 5 curl -sS -m 5 --retry 0 \
        -H 'Content-Type: application/json' \
        -H "X-Auth-Token: $TOKEN" \
        -X POST --data-binary @- "$DASH_URL" >/dev/null \
      && log "POST serial=$serial OK" ) \
    || log "POST serial=$serial FAIL"

  rm -f "$file"; unset 'TMP[$serial]'
}

# Main loop: kumpulkan per-serial lalu proses di EOE
while IFS= read -r line; do
  printf '%s\n' "$line" >>"$RAW"
  serial=$(sed -n 's/.*audit([^:]*:\([0-9]\+\)).*/\1/p' <<<"$line")
  [ -z "$serial" ] && continue
  if [ -z "${TMP[$serial]+x}" ]; then
    TMP[$serial]=$(mktemp /tmp/a2h."$serial".XXXX)
  fi
  printf '%s\n' "$line" >> "${TMP[$serial]}"
  [[ "$line" == type=EOE* ]] && process_block "$serial"
done

exit 0
