#!/usr/bin/env bash
# Reads auditd plugin input (format=string, type=always), groups by serial until EOE,
# filters key=uadwatch, then POST JSON ke dashboard. Tahan error & tidak nge-block auditd.

set -uo pipefail
umask 022

# --- Konfigurasi ---
DASH_URL="${DASH_URL:-http://ipdashbord_atau_domain/webhook.php}"
TOKEN="${TOKEN:-token_yang_digenerate_dari_dahbord_di_menu_host}"      # <<< GANTI token di sini
KEY_FILTER="${KEY_FILTER:-hostwatch}"

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

  # Harus mengandung key=uadwatch di blok ini
  if ! grep -q 'key="'$KEY_FILTER'"' "$file"; then
    rm -f "$file"; unset 'TMP[$serial]'; return 0
  fi

  # Ambil timestamp (epoch → ISO8601)
  local epoch iso
  epoch=$(sed -n 's/.*audit(\([0-9]\+\).*/\1/p' "$file" | head -n1)
  [ -n "$epoch" ] && iso=$(date -Is -d "@$epoch") || iso="$(date -Is)"

  # Ambil field penting
  local syscall action file_path cwd comm exe auid uid
  syscall=$(grep -m1 -o 'SYSCALL=[^ ]*' "$file" | cut -d= -f2)

  if grep -q 'nametype=DELETE' "$file" || [[ "${syscall:-}" =~ ^unlink ]]; then
    action=delete
  elif grep -q 'nametype=CREATE' "$file"; then
    action=create
  else
    action=write
  fi

  # file path prioritas yg CREATE/DELETE, kalau nggak ada ambil PATH terakhir
  file_path=$(awk '/type=PATH/ && /nametype=(CREATE|DELETE|WRITE|OPEN|NORMAL)/ {
                    match($0,/name="([^"]+)"/,a); if (a[1]!=""){print a[1]; exit}}' "$file")
  [ -z "$file_path" ] && file_path=$(awk '/type=PATH/ {match($0,/name="([^"]+)"/,a); last=a[1]}
                                       END{if(last!="") print last}' "$file")

  cwd=$(sed -n 's/.*type=CWD .* cwd="\([^"]*\)".*/\1/p' "$file" | head -n1)
  comm=$(sed -n 's/.*type=SYSCALL .* comm="\([^"]*\)".*/\1/p' "$file" | head -n1)
  exe=$(sed -n 's/.*type=SYSCALL .* exe="\([^"]*\)".*/\1/p' "$file" | head -n1)

  # Prefer ENRICHED (UID/AUID berupa nama), fallback ke angka mentah
  auid=$(sed -n 's/.*AUID="\([^"]*\)".*/\1/p' "$file" | head -n1)
  uid=$(sed -n 's/.*UID="\([^"]*\)".*/\1/p' "$file" | head -n1)
  [ -z "$auid" ] && auid=$(sed -n 's/.* auid=\([^ ]*\).*/\1/p' "$file" | head -n1)
  [ -z "$uid"  ] && uid=$(sed -n 's/.*  uid=\([^ ]*\).*/\1/p' "$file" | head -n1)

  # Build JSON (nilai kita tidak mengandung kutip ganda; aman untuk printf sederhana)
  local json
  json=$(printf '{"host":"%s","ip":"%s","time_event":"%s","serial":"%s","key":"%s","user_auid":"%s","user_uid":"%s","comm":"%s","exe":"%s","syscall":"%s","action":"%s","file":"%s","cwd":"%s"}' \
        "$HOST" "${IP:-}" "$iso" "$serial" "$KEY_FILTER" "${auid:-}" "${uid:-}" \
        "${comm:-}" "${exe:-}" "${syscall:-}" "${action:-}" "${file_path:-}" "${cwd:-}")

  # Kirim non-blocking (timeout) + log hasil; jangan spam stderr
  ( printf '%s' "$json" | timeout 5 curl -sS -m 5 --retry 0 \
        -H 'Content-Type: application/json' \
        -H "X-Auth-Token: $TOKEN" \
        -X POST --data-binary @- "$DASH_URL" >/dev/null \
      && log "POST serial=$serial OK" ) \
    || log "POST serial=$serial FAIL"

  rm -f "$file"; unset 'TMP[$serial]'
}

# Main loop: kumpulkan per-serial sampai EOE, lalu proses
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

# EOF dari auditd: biarkan proses exit normal; auditd akan restart plugin jika perlu.
exit 0







