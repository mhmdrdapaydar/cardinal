#!/usr/bin/env bash
# =============================================================================
# Cardinal — realtime service test suite (development only).
#
# Exercises realtime.php against a real PHP runtime and a throwaway SQLite
# file. Nothing here touches the game database; the service has no access to
# it by construction.
#
#   bash tools/devtest/test_realtime.sh
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP="${PHP_WASM_CLI:-/home/user/.cache/phpchk/node_modules/.bin/php-wasm-cli}"
WORK="$(mktemp -d)"
DB="$WORK/rt.sqlite"
SESS="$WORK/sess"
mkdir -p "$SESS"

pass=0; fail=0

req() { # sid pid method query body
  RT_SESSDIR="$SESS" RT_DB="$DB" RT_SID="$1" RT_PID="$2" RT_METHOD="$3" RT_QUERY="$4" RT_BODY="${5:-}" \
    timeout 120 "$PHP" "$ROOT/tools/devtest/rt_request.php" 2>/dev/null | tail -1
}

check() { # label expected-substring actual
  if [[ "$3" == *"$2"* ]]; then
    printf '  \033[32mok\033[0m   %s\n' "$1"; pass=$((pass+1))
  else
    printf '  \033[31mFAIL\033[0m %s\n       expected to contain: %s\n       got: %s\n' "$1" "$2" "$3"; fail=$((fail+1))
  fi
}

reject() { # label forbidden-substring actual
  if [[ "$3" != *"$2"* ]]; then
    printf '  \033[32mok\033[0m   %s\n' "$1"; pass=$((pass+1))
  else
    printf '  \033[31mFAIL\033[0m %s\n       must NOT contain: %s\n       got: %s\n' "$1" "$2" "$3"; fail=$((fail+1))
  fi
}

age_presence() { # seconds
  python3 - "$DB" "$1" <<'PY'
import sqlite3, sys, time
c = sqlite3.connect(sys.argv[1])
c.execute("UPDATE presence SET seen_at = seen_at - ?", (int(sys.argv[2]),))
c.commit()
PY
}

echo "realtime.php  —  presence and chat"
echo

echo "auth and service"
check "health responds without a session" '"service":"cardinal-realtime"' "$(req anon "" GET 'route=health')"
check "sqlite driver present"            '"sqlite":true'                  "$(req anon "" GET 'route=health')"
check "sync refuses an anonymous caller" '"ok":false'                     "$(req anon "" POST 'route=sync' '{"x":1}')"
check "chat refuses an anonymous caller" '"ok":false'                     "$(req anon "" GET  'route=chat')"
check "unknown route is a 404 envelope"  'وجود ندارد'                     "$(req sA 1001 GET 'route=nope')"
echo

echo "presence: same room"
req sA 1001 POST 'route=sync' '{"name":"Kirito","classId":1,"pkStatus":"white","floor":3,"location":"city","x":2.5,"z":-1.25,"yaw":0.4,"moving":true}' >/dev/null
OUT_B=$(req sB 1002 POST 'route=sync' '{"name":"Asuna","classId":2,"pkStatus":"orange","floor":3,"location":"city","x":-4,"z":6,"yaw":1.1}')
check "B sees A in the same city floor"  '"name":"Kirito"'  "$OUT_B"
check "A's position is relayed"          '"x":2.5'          "$OUT_B"
check "A's cursor colour is relayed"     '"pkStatus":"white"' "$OUT_B"
check "B is not listed to itself"        '"inRoom":2'       "$OUT_B"
reject "B does not receive its own row"  '"name":"Asuna"'   "$OUT_B"
echo

echo "presence: rooms are separated"
OUT_C=$(req sC 1003 POST 'route=sync' '{"name":"Klein","floor":4,"location":"city","x":0,"z":0}')
reject "different floor cannot see A"    '"name":"Kirito"'  "$OUT_C"
OUT_D=$(req sD 1004 POST 'route=sync' '{"name":"Agil","floor":3,"location":"wild","x":0,"z":0}')
reject "different location cannot see A" '"name":"Kirito"'  "$OUT_D"
check  "wild room counts only itself"    '"inRoom":1'       "$OUT_D"
echo

echo "presence: expiry"
age_presence 90
OUT_E=$(req sB 1002 POST 'route=sync' '{"name":"Asuna","floor":3,"location":"city","x":-4,"z":6}')
reject "a player idle past the TTL disappears" '"name":"Kirito"' "$OUT_E"
check  "the room shrinks to the live player"   '"inRoom":1'      "$OUT_E"
echo

echo "input is clamped, not trusted"
OUT_F=$(req sA 1001 POST 'route=sync' '{"name":"Kirito","floor":3,"location":"city","x":9999,"z":-9999,"yaw":99}')
OUT_G=$(req sB 1002 POST 'route=sync' '{"name":"Asuna","floor":3,"location":"city","x":0,"z":0}')
check "x is clamped to the city bound"  '"x":40'   "$OUT_G"
check "z is clamped to the city bound"  '"z":-40'  "$OUT_G"
reject "yaw cannot exceed its range"    '"yaw":99' "$OUT_G"
OUT_H=$(req sA 1001 POST 'route=sync' '{"name":"<script>alert(1)</script>","floor":3,"location":"city","x":0,"z":0}')
OUT_I=$(req sB 1002 POST 'route=sync' '{"name":"Asuna","floor":3,"location":"city","x":0,"z":0}')
check "control characters are stripped from names" '"name":"' "$OUT_I"
OUT_J=$(req sA 1001 POST 'route=sync' '{"name":"Kirito","floor":99999,"location":"nowhere","x":0,"z":0}')
check "an unknown location falls back to city" '"location":"city"' "$OUT_J"
check "floor is clamped to the schema range"   '"floor":200'       "$OUT_J"
echo

echo "chat"
check "a message is accepted"     '"id":1'   "$(req sA 1001 POST 'route=chat/send' '{"name":"Kirito","classId":1,"body":"سلام قلمرو"}')"
check "the cooldown rejects a burst" '"ok":false' "$(req sA 1001 POST 'route=chat/send' '{"name":"Kirito","body":"دوباره"}')"
check "another player can post"   '"ok":true' "$(req sB 1002 POST 'route=chat/send' '{"name":"Asuna","body":"سلام"}')"
OUT_K=$(req sB 1002 GET 'route=chat')
check "history contains the first message" 'سلام قلمرو' "$OUT_K"
check "own messages are flagged"           '"self":true' "$OUT_K"
check "an empty body is refused"  '"ok":false' "$(req sC 1003 POST 'route=chat/send' '{"body":"   "}')"
LONG=$(python3 -c 'print("ط"*600)')
req sC 1003 POST 'route=chat/send' "{\"body\":\"$LONG\"}" >/dev/null
LEN=$(python3 - "$DB" <<'PY'
import sqlite3, sys
c = sqlite3.connect(sys.argv[1])
print(len(c.execute("SELECT body FROM chat ORDER BY id DESC LIMIT 1").fetchone()[0]))
PY
)
check "an over-long message is truncated to 240" "240" "$LEN"
echo

echo "chat window and retention"
python3 - "$DB" <<'PY'
import sqlite3, sys, time
c = sqlite3.connect(sys.argv[1]); now = int(time.time())
c.executemany("INSERT INTO chat (player_id,name,class_id,pk_status,body,created_at) VALUES (?,?,?,?,?,?)",
              [(1001, "Kirito", 1, "white", f"line {i}", now) for i in range(150)])
c.commit()
PY
COUNT=$(req sB 1002 GET 'route=chat' | python3 -c 'import sys,json; print(len(json.load(sys.stdin)["data"]["messages"]))')
check "at most 100 messages are returned" "100" "$COUNT"
LASTID=$(python3 - "$DB" <<'PY'
import sqlite3, sys
print(sqlite3.connect(sys.argv[1]).execute("SELECT MAX(id) FROM chat").fetchone()[0])
PY
)
AFTER=$(req sB 1002 GET "route=chat&after=$((LASTID-3))" | python3 -c 'import sys,json; print(len(json.load(sys.stdin)["data"]["messages"]))')
check "incremental fetch returns only newer rows" "3" "$AFTER"
echo

echo "lobbies"
python3 - "$DB" <<'PY'
import sqlite3, sys, time
c = sqlite3.connect("/dev/stdin") if False else sqlite3.connect(sys.argv[1])
now = int(time.time())
c.executemany("INSERT OR REPLACE INTO presence (player_id,name,class_id,pk_status,floor,location,x,z,yaw,moving,seen_at)"
              " VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [(5000+i, f"P{i}", 1, "white", 3, "city", 0, 0, 0, 0, now) for i in range(120)])
c.commit()
PY
OUT_L=$(req sB 1002 POST 'route=sync' '{"name":"Asuna","floor":3,"location":"city","x":0,"z":0}')
check "the room reports every live player" '"inRoom":12' "$OUT_L"
LOBBIES=$(echo "$OUT_L" | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["lobbies"])')
check "the room splits into lobbies of 50" "3" "$LOBBIES"
SEEN=$(echo "$OUT_L" | python3 -c 'import sys,json; print(len(json.load(sys.stdin)["data"]["players"]))')
if [[ "$SEEN" -le 50 ]]; then
  printf '  \033[32mok\033[0m   a lobby never exceeds 50 players (saw %s)\n' "$SEEN"; pass=$((pass+1))
else
  printf '  \033[31mFAIL\033[0m a lobby exceeded 50 players (saw %s)\n' "$SEEN"; fail=$((fail+1))
fi
echo

echo "isolation from the game"
if grep -qE "cardinal_game|CardinalGame|lib/bootstrap|mysql:|players\b.*SET|UPDATE players" "$ROOT/realtime.php"; then
  printf '  \033[31mFAIL\033[0m realtime.php reaches into the game layer\n'; fail=$((fail+1))
else
  printf '  \033[32mok\033[0m   realtime.php never references the game database or its classes\n'; pass=$((pass+1))
fi

echo
printf 'passed %d, failed %d\n' "$pass" "$fail"
rm -rf "$WORK"
[[ "$fail" -eq 0 ]]
