<?php
/**
 * api.php — JSON API for widget operations
 *
 * All requests must be POST with Content-Type: application/json
 * and include X-CSRF-Token header.
 *
 * Actions:
 *   save_widget    — insert or update a single widget
 *   delete_widget  — delete widget by id
 *   update_content — update content JSON of a widget
 *   save_all       — replace all widgets for the current user
 */

require_once __DIR__ . '/core/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Must be logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF check (token in header or body)
$csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$csrf_body   = $body['csrf'] ?? '';

if (!verify_csrf($csrf_header) && !verify_csrf($csrf_body)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action  = $body['action']  ?? '';
$user_id = current_user()['id'];
$db      = get_db();

// ── Helper: sanitise and encode content ──────────────────

function clean_content(mixed $raw): string {
    if (is_array($raw))  return json_encode($raw, JSON_UNESCAPED_UNICODE);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return $decoded !== null ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '{}';
    }
    return '{}';
}

function owns_widget(PDO $db, int $widget_id, int $user_id): bool {
    $s = $db->prepare('SELECT id FROM widgets WHERE id = ? AND user_id = ?');
    $s->execute([$widget_id, $user_id]);
    return (bool) $s->fetch();
}

// ── Routing ───────────────────────────────────────────────

try {
    switch ($action) {

        // ── Save single widget (insert or update) ──────────
        case 'save_widget': {
            $type      = substr(trim($body['type'] ?? 'note'), 0, 32);
            $title     = substr(trim($body['title'] ?? ''), 0, 120);
            $content   = clean_content($body['content'] ?? '{}');
            $pos_w     = max(1, min(4, (int)($body['position_w'] ?? 1)));
            $pos_h     = max(1, min(3, (int)($body['position_h'] ?? 1)));
            $sort      = (int)($body['sort_order'] ?? 0);
            $widget_id = (int)($body['id'] ?? 0);

            if ($widget_id > 0 && owns_widget($db, $widget_id, $user_id)) {
                // Update
                $stmt = $db->prepare('
                    UPDATE widgets
                    SET type=?, title=?, content=?, position_w=?, position_h=?,
                        sort_order=?, updated_at=datetime("now")
                    WHERE id=? AND user_id=?
                ');
                $stmt->execute([$type, $title, $content, $pos_w, $pos_h, $sort, $widget_id, $user_id]);
                echo json_encode(['ok' => true, 'id' => $widget_id]);
            } else {
                // Insert
                $stmt = $db->prepare('
                    INSERT INTO widgets (user_id, type, title, content, position_w, position_h, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$user_id, $type, $title, $content, $pos_w, $pos_h, $sort]);
                echo json_encode(['ok' => true, 'id' => (int) $db->lastInsertId()]);
            }
            break;
        }

        // ── Delete widget ───────────────────────────────────
        case 'delete_widget': {
            $widget_id = (int)($body['id'] ?? 0);
            if ($widget_id < 1 || !owns_widget($db, $widget_id, $user_id)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not found']);
                break;
            }
            $db->prepare('DELETE FROM widgets WHERE id=? AND user_id=?')
               ->execute([$widget_id, $user_id]);
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Update only content (auto-save) ─────────────────
        case 'update_content': {
            $widget_id = (int)($body['id'] ?? 0);
            $content   = clean_content($body['content'] ?? '{}');
            $title     = isset($body['title']) ? substr(trim($body['title']), 0, 120) : null;

            if ($widget_id < 1 || !owns_widget($db, $widget_id, $user_id)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not found']);
                break;
            }

            if ($title !== null) {
                $db->prepare('UPDATE widgets SET content=?, title=?, updated_at=datetime("now") WHERE id=? AND user_id=?')
                   ->execute([$content, $title, $widget_id, $user_id]);
            } else {
                $db->prepare('UPDATE widgets SET content=?, updated_at=datetime("now") WHERE id=? AND user_id=?')
                   ->execute([$content, $widget_id, $user_id]);
            }
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Save all (replace entire widget set) ─────────────
        case 'save_all': {
            $incoming = $body['widgets'] ?? [];
            if (!is_array($incoming)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'widgets must be array']);
                break;
            }

            $db->beginTransaction();

            // Keep existing widget IDs to detect orphans
            $stmt    = $db->prepare('SELECT id FROM widgets WHERE user_id=?');
            $stmt->execute([$user_id]);
            $existing_ids = array_column($stmt->fetchAll(), 'id');
            $seen_ids     = [];

            foreach ($incoming as $idx => $w) {
                $wid     = (int)($w['id'] ?? 0);
                $type    = substr(trim($w['type']  ?? 'note'), 0, 32);
                $title   = substr(trim($w['title'] ?? ''),     0, 120);
                $content = clean_content($w['content'] ?? '{}');
                $pos_w   = max(1, min(4, (int)($w['position_w'] ?? 1)));
                $pos_h   = max(1, min(3, (int)($w['position_h'] ?? 1)));
                $sort    = $idx;

                if ($wid > 0 && in_array($wid, $existing_ids, true)) {
                    $db->prepare('
                        UPDATE widgets SET type=?,title=?,content=?,position_w=?,position_h=?,
                            sort_order=?,updated_at=datetime("now")
                        WHERE id=? AND user_id=?
                    ')->execute([$type, $title, $content, $pos_w, $pos_h, $sort, $wid, $user_id]);
                    $seen_ids[] = $wid;
                } else {
                    $db->prepare('
                        INSERT INTO widgets (user_id,type,title,content,position_w,position_h,sort_order)
                        VALUES (?,?,?,?,?,?,?)
                    ')->execute([$user_id, $type, $title, $content, $pos_w, $pos_h, $sort]);
                    $new_id = (int) $db->lastInsertId();
                    // Tell the client the real DB id
                    $seen_ids[] = $new_id;
                    $w['new_id'] = $new_id; // captured below
                }
            }

            // Delete orphaned widgets (removed on client but still in DB)
            $orphans = array_diff($existing_ids, $seen_ids);
            if ($orphans) {
                $placeholders = implode(',', array_fill(0, count($orphans), '?'));
                $db->prepare("DELETE FROM widgets WHERE id IN ($placeholders) AND user_id=?")
                   ->execute([...$orphans, $user_id]);
            }

            $db->commit();
            echo json_encode(['ok' => true, 'deleted' => count($orphans)]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Unknown action: $action"]);
    }

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
