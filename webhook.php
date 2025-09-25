<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

require __DIR__ . '/db.php'; // <-- ini yang bikin $pdo dari config.php

// helper waktu
function iso_to_mysql(?string $s): string {
    if (!$s) return date('Y-m-d H:i:s');
    try { $dt = new DateTime($s); } catch (Throwable $e) { return date('Y-m-d H:i:s'); }
    return $dt->format('Y-m-d H:i:s');
}

try {
    $token = trim($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if ($token === '') { http_response_code(401); echo "missing token\n"; exit; }

    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    if (!is_array($j)) { http_response_code(400); echo "invalid json\n"; exit; }

    $hostname   = trim((string)($j['host'] ?? ''));
    $ip         = trim((string)($j['ip'] ?? ''));
    $time_event = iso_to_mysql($j['time_event'] ?? null);

    $comm    = isset($j['comm']) ? (string)$j['comm'] : null;
    $syscall = isset($j['syscall']) ? (string)$j['syscall'] : null;

    // buang noise internal (auditctl/augenrules/sendto) tapi tetap update last_seen
    if ($syscall === 'sendto' || $comm === 'auditctl' || $comm === 'augenrules') {
        $pdo->prepare("UPDATE hosts SET last_seen = NOW() WHERE token = ?")->execute([$token]);
        echo "ok\n"; exit;
    }

    $pdo->beginTransaction();

    // resolve host via token
    $stmt = $pdo->prepare("SELECT id, hostname FROM hosts WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if ($row) {
        $host_id = (int)$row['id'];
        // update ip & hostname kalau payload mengirim nilai (tidak kosong)
        $pdo->prepare("UPDATE hosts
                       SET hostname = COALESCE(NULLIF(?, ''), hostname),
                           ip       = COALESCE(NULLIF(?, ''), ip),
                           last_seen = NOW()
                       WHERE id = ?")
            ->execute([$hostname, $ip, $host_id]);

    } else {
        // token belum dikenal -> wajib ada hostname untuk registrasi
        if ($hostname === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo "hostname required for new token\n";
            exit;
        }
        // coba bind ke hostname yang sudah ada, kalau tidak ada -> insert
        $q = $pdo->prepare("SELECT id FROM hosts WHERE hostname = ?");
        $q->execute([$hostname]);
        $h = $q->fetch();
        if ($h) {
            $host_id = (int)$h['id'];
            $pdo->prepare("UPDATE hosts SET token = ?, ip = COALESCE(NULLIF(?, ''), ip), last_seen = NOW()
                           WHERE id = ?")
                ->execute([$token, $ip, $host_id]);
        } else {
            $pdo->prepare("INSERT INTO hosts (hostname, ip, token, last_seen) VALUES (?,?,?, NOW())")
                ->execute([$hostname, $ip, $token]);
            $host_id = (int)$pdo->lastInsertId();
        }
    }

    // siapin kolom events
    $serial     = isset($j['serial']) ? (string)$j['serial'] : null;
    $key        = isset($j['key']) ? (string)$j['key'] : null;
    $user_auid  = isset($j['user_auid']) ? (string)$j['user_auid'] : null;
    $user_uid   = isset($j['user_uid']) ? (string)$j['user_uid'] : null;
    $exe        = isset($j['exe']) ? (string)$j['exe'] : null;
    $action     = isset($j['action']) ? (string)$j['action'] : null;
    $file_path  = isset($j['file']) ? (string)$j['file'] : null;
    $cwd        = isset($j['cwd']) ? (string)$j['cwd'] : null;

    $raw_json = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = "INSERT INTO events
            (host_id, time_event, serial, `key`, user_auid, user_uid, comm, exe, syscall, action, file_path, cwd, raw)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
        $host_id, $time_event, $serial, $key, $user_auid, $user_uid,
        $comm, $exe, $syscall, $action, $file_path, $cwd, $raw_json
    ]);

    $pdo->commit();
    echo "ok\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("webhook.php error: " . $e->getMessage());
    http_response_code(500);
    echo "error\n";
}
