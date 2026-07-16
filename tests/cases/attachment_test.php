<?php
declare(strict_types=1);

// The is_uploaded_file() shadow (so validateUploads' origin guard passes in CLI and the real MIME/size checks
// run) now lives once in tests/shadow_functions.php, loaded before every case. See the note there.

namespace {

    use App\Services\AttachmentService;

    // Security tests for AttachmentService (file upload). Group 1 drives validateUploads() directly
    // against real temp files (deleted in finally). Group 2 seeds a ticket + attachment in the test DB
    // and checks getVisibleAttachment() access control (ticket cascade-cleans children). See the note at
    // the bottom re: storeValidated(), which move_uploaded_file() makes untestable under CLI (needs E2E).

    function att_service(): AttachmentService
    {
        return tvm_container()->get(AttachmentService::class);
    }

    function att_pdo(): PDO
    {
        return tvm_container()->get(PDO::class);
    }

    /** Real temp file with the given bytes. */
    function att_tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att_');
        file_put_contents($path, $bytes);
        return $path;
    }

    /** Build a $_FILES-style array from entries: ['name'=>, 'tmp'=>, 'size'=>, 'error'?=>]. */
    function att_files(array $entries): array
    {
        return [
            'name' => array_map(static fn (array $e): string => $e['name'], $entries),
            'tmp_name' => array_map(static fn (array $e): string => $e['tmp'], $entries),
            'size' => array_map(static fn (array $e): int => $e['size'], $entries),
            'error' => array_map(static fn (array $e): int => $e['error'] ?? UPLOAD_ERR_OK, $entries),
        ];
    }

    // Byte fixtures — MIME types verified with finfo(FILEINFO_MIME_TYPE):
    //   JPEG → image/jpeg (whitelisted) · GIF → image/gif (NOT whitelisted) · "<?php" → text/x-php (NOT whitelisted)
    function att_jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    }

    // ── Group 1: validateUploads (content validation) ──

    test('attachment: rejects more than MAX_FILES (3)', function (): void {
        $tmps = [];
        try {
            $entries = [];
            for ($i = 0; $i < 4; $i++) {
                $tmps[] = $tmp = att_tmp(att_jpeg());
                $entries[] = ['name' => "f$i.jpg", 'tmp' => $tmp, 'size' => 100];
            }
            $threw = false;
            try {
                att_service()->validateUploads(att_files($entries));
            } catch (DomainException $e) {
                $threw = true;
                assert_same('แนบรูปได้สูงสุด 3 รูปต่อครั้ง', $e->getMessage());
            }
            assert_true($threw, '4 files must be rejected (MAX_FILES = 3)');
        } finally {
            foreach ($tmps as $t) {
                @unlink($t);
            }
        }
    });

    test('attachment: rejects a file larger than MAX_SIZE (5MB)', function (): void {
        $tmp = att_tmp(att_jpeg()); // valid content, tiny on disk; the reported size is what is checked
        try {
            $threw = false;
            try {
                att_service()->validateUploads(att_files([['name' => 'big.jpg', 'tmp' => $tmp, 'size' => 5242881]]));
            } catch (DomainException $e) {
                $threw = true;
                assert_same('รูปแนบแต่ละไฟล์ต้องมีขนาดไม่เกิน 5MB', $e->getMessage());
            }
            assert_true($threw, 'a file over 5MB must be rejected');
        } finally {
            @unlink($tmp);
        }
    });

    test('attachment: rejects a content type outside the whitelist (image/gif)', function (): void {
        $tmp = att_tmp("GIF89a\x01\x00\x01\x00\x00\x00\x00;"); // image/gif — not in MIME_EXTENSIONS
        try {
            $threw = false;
            try {
                att_service()->validateUploads(att_files([['name' => 'ok.png', 'tmp' => $tmp, 'size' => 20]]));
            } catch (DomainException $e) {
                $threw = true;
                assert_contains_str('รองรับไฟล์แนบ', $e->getMessage());
            }
            assert_true($threw, 'a non-whitelisted content type must be rejected');
        } finally {
            @unlink($tmp);
        }
    });

    test('attachment(security): a PHP script named "evil.jpg" is rejected — content is sniffed, not the name', function (): void {
        // The crown-jewel test: the bytes are a PHP webshell (finfo → text/x-php), but the client name lies.
        $tmp = att_tmp("<?php echo shell_exec(\$_GET[0]); ?>");
        try {
            $threw = false;
            try {
                att_service()->validateUploads(att_files([['name' => 'evil.jpg', 'tmp' => $tmp, 'size' => 42]]));
            } catch (DomainException $e) {
                $threw = true;
                assert_contains_str('รองรับไฟล์แนบ', $e->getMessage());
            }
            assert_true($threw, 'MIME spoofing must be rejected: the server must trust sniffed content over the .jpg extension');
        } finally {
            @unlink($tmp);
        }
    });

    test('attachment: a valid upload takes its extension + mime from the server sniff, not the client name', function (): void {
        // Real JPEG bytes, but the client names it ".png" — the stored extension must be jpg (from image/jpeg).
        $tmp = att_tmp(att_jpeg());
        try {
            $out = att_service()->validateUploads(att_files([['name' => 'totally-a.png', 'tmp' => $tmp, 'size' => 20]]));
            assert_same(1, count($out), 'one validated file');
            assert_same('jpg', $out[0]['extension'], 'extension derived from image/jpeg (server), not ".png" (client)');
            assert_same('image/jpeg', $out[0]['mime_type'], 'mime_type set from the sniff');
            assert_same('totally-a.png', $out[0]['name'], 'client name kept separately (display only)');
        } finally {
            @unlink($tmp);
        }
    });

    test('attachment: a plain-text upload validates as txt (whitelisted, server-derived ext)', function (): void {
        $tmp = att_tmp('just some plain text content here');
        try {
            $out = att_service()->validateUploads(att_files([['name' => 'note.dat', 'tmp' => $tmp, 'size' => 33]]));
            assert_same('txt', $out[0]['extension'], 'text/plain → txt regardless of the ".dat" client name');
            assert_same('text/plain', $out[0]['mime_type']);
        } finally {
            @unlink($tmp);
        }
    });

    // ── Group 2: getVisibleAttachment (access control) ──

    function att_seed_ticket(int $requesterId): int
    {
        $pdo = att_pdo();
        $loc = (int) $pdo->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
        $cat = (int) $pdo->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
        $pri = (int) $pdo->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, "ATT", "x", ?, ?, ?, ?, "in_progress", NOW())'
        )->execute(['ATT-' . bin2hex(random_bytes(4)), $requesterId, $loc, $cat, $pri]);
        return (int) $pdo->lastInsertId();
    }

    function att_seed_attachment(int $ticketId, ?int $commentId): int
    {
        // disk_path intentionally points at a non-existent file — Group 2 asserts the access guards,
        // which run before the physical-file check.
        att_pdo()->prepare(
            'INSERT INTO ticket_attachments (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES (?, ?, 4, "orig.jpg", "stored.jpg", ?, "image/jpeg", 100, NOW())'
        )->execute([$ticketId, $commentId, 'storage/uploads/tickets/' . $ticketId . '/nofile.jpg']);
        return (int) att_pdo()->lastInsertId();
    }

    test('attachment(access): a viewer unrelated to the ticket is blocked; an unknown id is not found', function (): void {
        $ticketId = att_seed_ticket(1); // ticket owned by requester #1
        try {
            $attId = att_seed_attachment($ticketId, null);

            // a different requester cannot even see the ticket → generic "not found" (anti-enumeration)
            $threw = false;
            try {
                att_service()->getVisibleAttachment($attId, ['id' => 999999, 'role' => 'requester']);
            } catch (DomainException $e) {
                $threw = true;
                assert_same('ไม่พบไฟล์แนบ', $e->getMessage());
            }
            assert_true($threw, 'a user unrelated to the ticket cannot open its attachment');

            // unknown attachment id → not found
            $threw2 = false;
            try {
                att_service()->getVisibleAttachment(999999999, ['id' => 4, 'role' => 'admin']);
            } catch (DomainException $e) {
                $threw2 = true;
                assert_same('ไม่พบไฟล์แนบ', $e->getMessage());
            }
            assert_true($threw2, 'a non-existent attachment id → not found');
        } finally {
            att_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades the attachment
        }
    });

    test('attachment(O5): an unreadable file surfaces an error, not a 200 empty download', function (): void {
        // the row + file exist (passes is_file), but the file can't be READ (chmod 000). This must throw, not
        // cast file_get_contents(false) to '' and ship an empty 200. (error-review-3 O5)
        $ticketId = att_seed_ticket(1);
        $relDir = 'storage/uploads/tickets/' . $ticketId;
        $absDir = BASE_PATH . '/' . $relDir;
        @mkdir($absDir, 0775, true);
        $relPath = $relDir . '/o5-unreadable.bin';
        $absPath = BASE_PATH . '/' . $relPath;
        file_put_contents($absPath, 'secret bytes');
        chmod($absPath, 0000);

        att_pdo()->prepare(
            'INSERT INTO ticket_attachments (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES (?, NULL, 4, "orig.bin", "stored.bin", ?, "application/octet-stream", 12, NOW())'
        )->execute([$ticketId, $relPath]);

        try {
            $threw = false;
            try {
                att_service()->getVisibleAttachment((int) att_pdo()->lastInsertId(), ['id' => 4, 'role' => 'admin']);
            } catch (\RuntimeException $e) {
                $threw = true;
                assert_contains_str('ไม่สามารถอ่านไฟล์แนบ', $e->getMessage(), 'the read failure surfaces as an operational error');
            }
            assert_true($threw, 'an unreadable file throws — never returns empty content for a 200 download');
        } finally {
            chmod($absPath, 0644);
            @unlink($absPath);
            @rmdir($absDir);
            att_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades the attachment
        }
    });

    test('attachment(access): an internal attachment is hidden from the requester but reachable by staff', function (): void {
        $ticketId = att_seed_ticket(1); // owned by requester #1
        try {
            att_pdo()->prepare(
                'INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at, updated_at) VALUES (?, 4, "internal note", 1, NOW(), NOW())'
            )->execute([$ticketId]);
            $commentId = (int) att_pdo()->lastInsertId();
            $attId = att_seed_attachment($ticketId, $commentId); // attached to the internal comment

            // requester #1 OWNS the ticket (passes the visibility check) but is a requester → blocked from internal
            $threw = false;
            try {
                att_service()->getVisibleAttachment($attId, ['id' => 1, 'role' => 'requester']);
            } catch (DomainException $e) {
                $threw = true;
                assert_same('ไม่มีสิทธิ์เปิดไฟล์แนบนี้', $e->getMessage());
            }
            assert_true($threw, 'a requester must not open an internal attachment');

            // staff (admin) is NOT blocked by the internal rule → passes it, then fails only on the absent physical file
            $threw2 = false;
            try {
                att_service()->getVisibleAttachment($attId, ['id' => 4, 'role' => 'admin']);
            } catch (RuntimeException $e) {
                $threw2 = true;
                assert_same('ไม่พบไฟล์แนบในพื้นที่จัดเก็บ', $e->getMessage());
            }
            assert_true($threw2, 'admin clears the internal-visibility gate (fails later only because no physical file was seeded)');
        } finally {
            att_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades comment + attachment
        }
    });
}
