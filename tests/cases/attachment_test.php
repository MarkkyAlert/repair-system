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

    // The webshell case above proves the sniff beats a lying NAME. These two pin the other half of the same
    // idea, which the sniff alone does not answer:
    //
    //   SVG and HTML are the two formats a browser will happily execute script from while looking like harmless
    //   uploads. They are absent from the whitelist, and this says so out loud — adding "image/svg+xml" to that
    //   list would look like a reasonable convenience and would quietly turn every attachment into a place to
    //   park script that runs on this origin.
    test('attachment(security): SVG and HTML are refused — the two formats a browser will run script from', function (): void {
        $payloads = [
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'html' => '<html><body><script>alert(document.cookie)</script></body></html>',
        ];

        foreach ($payloads as $label => $bytes) {
            $tmp = att_tmp($bytes);
            try {
                $threw = false;
                try {
                    att_service()->validateUploads(att_files([['name' => 'harmless.png', 'tmp' => $tmp, 'size' => strlen($bytes)]]));
                } catch (\DomainException $e) {
                    $threw = true;
                }
                assert_true($threw, "a {$label} payload must be refused, whatever the file is called");
            } finally {
                @unlink($tmp);
            }
        }

        // and the whitelist itself must not grow to include them
        $reflection = new \ReflectionClass(AttachmentService::class);
        $whitelist = array_keys((array) $reflection->getConstant('MIME_EXTENSIONS'));
        foreach (['image/svg+xml', 'text/html', 'application/xhtml+xml'] as $dangerous) {
            assert_true(!in_array($dangerous, $whitelist, true), "{$dangerous} must stay off the accepted list");
        }
    });

    //   And the name the uploader chose must never become the name on disk. The existing traversal tests cover a
    //   disk_path tampered with in the DATABASE; this covers the way in that a normal user actually has.
    test('attachment(security): the uploader\'s filename never becomes the filename on disk', function (): void {
        $service = att_service();
        $reflection = new \ReflectionClass(AttachmentService::class);
        $source = (string) file_get_contents($reflection->getFileName());

        // the stored name is generated, not derived from anything the uploader sent
        assert_true(
            (bool) preg_match('/\$storedName = bin2hex\(random_bytes\(\d+\)\) \. \\\'\.\\\' \. \(string\) \$file\[\\\'extension\\\'\]/', $source),
            'the name written to disk is random bytes plus the server-derived extension'
        );
        assert_true(
            !preg_match('/\$storedName\s*=\s*[^;]*\$file\[\\\'name\\\'\]/', $source),
            'nothing from the uploaded name is spliced into the stored name'
        );

        // a name that would escape the folder, and one that would take over the web server's own config,
        // both validate purely on their content — which is safe only because of the rule above
        foreach ([['../../../evil.png', att_jpeg()], ['.htaccess', "AddType application/x-httpd-php .png\n"]] as [$name, $bytes]) {
            $tmp = att_tmp($bytes);
            try {
                $valid = $service->validateUploads(att_files([['name' => $name, 'tmp' => $tmp, 'size' => strlen($bytes)]]));
                assert_true(
                    in_array((string) ($valid[0]['extension'] ?? ''), ['jpg', 'txt'], true),
                    "\"{$name}\" keeps only a server-derived extension, never its own"
                );
                assert_true(
                    !str_contains((string) ($valid[0]['extension'] ?? ''), '/'),
                    'the extension can never carry a path separator out of the folder'
                );
            } finally {
                @unlink($tmp);
            }
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

    test('attachment(F3): deleteStoredFiles reports the files it could NOT unlink (orphans), not silently void', function (): void {
        // a file inside a READ-ONLY directory can't be unlinked (needs write on the dir); a normal file can.
        // deleteStoredFiles must report the failed one so the caller can log the orphan. (error-review-4 F3)
        $svc = att_service();
        $roDir = BASE_PATH . '/storage/uploads/tickets/f3ro_' . bin2hex(random_bytes(3));
        @mkdir($roDir, 0775, true);
        $lockedRel = str_replace(BASE_PATH . '/', '', $roDir) . '/locked.bin';
        file_put_contents(BASE_PATH . '/' . $lockedRel, 'x');
        $okRel = 'storage/uploads/tickets/f3ok_' . bin2hex(random_bytes(3)) . '.bin';
        file_put_contents(BASE_PATH . '/' . $okRel, 'y');
        chmod($roDir, 0555); // read-only dir → its file can't be unlinked

        try {
            $failed = $svc->deleteStoredFiles([$lockedRel, $okRel]);

            assert_same([$lockedRel], $failed, 'the un-unlinkable file is reported as failed; the deletable one is not');
            assert_true(!is_file(BASE_PATH . '/' . $okRel), 'the deletable file was actually removed');
            assert_true(is_file(BASE_PATH . '/' . $lockedRel), 'the locked file remains — an orphan the caller can now log');
        } finally {
            chmod($roDir, 0775);
            @unlink(BASE_PATH . '/' . $lockedRel);
            @rmdir($roDir);
            @unlink(BASE_PATH . '/' . $okRel);
        }
    });

    test('attachment(F3-purge): purgeStoredFiles logs the orphans it could not remove, with the caller marker', function (): void {
        // Every rollback/cleanup path routes through purgeStoredFiles so a failed unlink is logged, not dropped.
        // A file in a read-only directory can't be unlinked → it must be logged under the caller's marker. (error-review-5 F3)
        $svc = att_service();
        $roDir = BASE_PATH . '/storage/uploads/tickets/f3purge_' . bin2hex(random_bytes(3));
        @mkdir($roDir, 0775, true);
        $lockedRel = str_replace(BASE_PATH . '/', '', $roDir) . '/locked.bin';
        file_put_contents(BASE_PATH . '/' . $lockedRel, 'x');
        chmod($roDir, 0555);

        $logFile = tempnam(sys_get_temp_dir(), 'purge_') . '.log';
        $originalLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);
        try {
            $svc->purgeStoredFiles([$lockedRel], 'ticket.create.cleanup', ['ticket' => 4242]);
            $logged = (string) @file_get_contents($logFile);

            assert_contains_str('[ticket.create.cleanup]', $logged, 'the orphan is logged under the caller-supplied marker');
            assert_contains_str('orphans=1', $logged, 'the count of files left on disk is recorded');
            assert_contains_str('ticket=4242', $logged, 'the caller context (entity id) is recorded');
            assert_true(is_file(BASE_PATH . '/' . $lockedRel), 'the orphan file itself still exists (it could not be unlinked)');
        } finally {
            ini_set('error_log', $originalLog);
            @unlink($logFile);
            chmod($roDir, 0775);
            @unlink(BASE_PATH . '/' . $lockedRel);
            @rmdir($roDir);
        }
    });

    test('attachment(F3-purge): a clean delete logs nothing, and every cleanup caller routes through purgeStoredFiles', function (): void {
        // no orphan → no noise
        $svc = att_service();
        $okRel = 'storage/uploads/tickets/f3clean_' . bin2hex(random_bytes(3)) . '.bin';
        file_put_contents(BASE_PATH . '/' . $okRel, 'y');
        $logFile = tempnam(sys_get_temp_dir(), 'purge_') . '.log';
        $originalLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);
        try {
            $svc->purgeStoredFiles([$okRel], 'ticket.create.cleanup', ['ticket' => 1]);
            assert_same('', trim((string) @file_get_contents($logFile)), 'a successful cleanup logs nothing');
            assert_true(!is_file(BASE_PATH . '/' . $okRel), 'the deletable file was removed');
        } finally {
            ini_set('error_log', $originalLog);
            @unlink($logFile);
            @unlink(BASE_PATH . '/' . $okRel);
        }

        // source-lock: the rollback/cleanup sites hand their orphans to purgeStoredFiles (with a marker), rather
        // than calling deleteStoredFiles() and discarding its return. (error-review-5 F3)
        $root = dirname(__DIR__, 2);
        $markers = [
            'app/Services/AttachmentService.php' => ['attachment.store.cleanup', 'attachment.comment.cleanup'],
            'app/Services/CommentService.php' => ['comment.create.cleanup', 'comment.delete.cleanup'],
            'app/Services/TicketService.php' => ['ticket.create.cleanup'],
            'app/Services/SystemSettingsService.php' => ['settings.logo.cleanup'],
        ];
        foreach ($markers as $file => $expected) {
            $src = (string) file_get_contents($root . '/' . $file);
            foreach ($expected as $marker) {
                assert_contains_str($marker, $src, "$file logs cleanup failures under '$marker'");
            }
        }
        // no service outside AttachmentService may call the raw primitive as a fire-and-forget statement
        foreach (['app/Services/CommentService.php', 'app/Services/TicketService.php'] as $file) {
            $src = (string) file_get_contents($root . '/' . $file);
            assert_true(!str_contains($src, '->deleteStoredFiles('), "$file routes cleanup through purgeStoredFiles, not the raw deleteStoredFiles");
        }
    });

    // bug-hunt R4-E: the attachment size label divided by 1024 and rounded to whole KB, so a real (whitelisted)
    // sub-512-byte file showed "0 KB" — indistinguishable from an empty/broken file — and multi-MB files stayed
    // as "x,xxx KB". The label now scales the unit (B/KB/MB/GB) and never collapses a non-empty file to "0".
    test('attachment: the size label scales units and never shows a non-empty file as 0 (R4-E)', function (): void {
        $map = new ReflectionMethod(AttachmentService::class, 'mapAttachment');
        $map->setAccessible(true);
        $label = static function (int $bytes) use ($map): string {
            return (string) $map->invoke(att_service(), [
                'id' => 1, 'comment_id' => 0, 'original_name' => 'n', 'mime_type' => 'text/plain', 'file_size' => $bytes,
            ])['size_label'];
        };

        assert_same('300 B', $label(300), 'a 300-byte file shows bytes, not "0 KB"');
        assert_same('1.0 KB', $label(1024), 'exactly 1 KB');
        assert_same('1.5 KB', $label(1536), 'fractional KB is kept');
        assert_same('5.0 MB', $label(5 * 1024 * 1024), 'multi-MB scales to MB, not thousands of KB');
        assert_true(str_ends_with($label(1), 'B') && $label(1) !== '0 B', 'a 1-byte file is not rounded to zero');
    });

    // static-review #3: getVisibleAttachment / deleteStoredFiles joined BASE_PATH . disk_path with no realpath
    // containment check. disk_path is app-generated today, but a tampered/miswritten/imported value ("../../..")
    // would let a download read — or a cleanup delete — a file OUTSIDE storage/uploads/tickets. resolveStoredPath()
    // now confines every read/delete to that folder. These prove the guard blocks escapes without over-rejecting
    // legitimate in-tree files.
    test('attachment(#3): a tampered disk_path pointing outside the uploads tree cannot be read', function (): void {
        $ticketId = att_seed_ticket(1);
        // a canary OUTSIDE storage/uploads/tickets — the exact thing an arbitrary-read would leak
        $canaryAbs = BASE_PATH . '/storage/logs/sec3_canary_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($canaryAbs, 'TOP-SECRET-CANARY');
        $canaryRel = str_replace(BASE_PATH . '/', '', $canaryAbs);
        // disk_path climbs out of the tickets folder back down to the canary
        $evilPath = 'storage/uploads/tickets/../../' . substr($canaryRel, strlen('storage/'));

        att_pdo()->prepare(
            'INSERT INTO ticket_attachments (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES (?, NULL, 4, "leak.txt", "leak.txt", ?, "text/plain", 17, NOW())'
        )->execute([$ticketId, $evilPath]);
        $attId = (int) att_pdo()->lastInsertId();

        try {
            // sanity: the crafted path really does resolve to the canary on disk (so the guard, not a typo, is what blocks it)
            assert_true(is_file(BASE_PATH . '/' . $evilPath), 'the traversal path really points at the canary file');

            $threw = false;
            try {
                att_service()->getVisibleAttachment($attId, ['id' => 4, 'role' => 'admin']); // fully-authorized viewer
            } catch (RuntimeException $e) {
                $threw = true;
                assert_same('ไม่พบไฟล์แนบในพื้นที่จัดเก็บ', $e->getMessage(), 'an out-of-tree path is refused as not-found, never served');
            }
            assert_true($threw, 'even an admin cannot read a file outside storage/uploads/tickets via a tampered disk_path');
            assert_true(is_file($canaryAbs), 'the canary is untouched (it was never opened)');
        } finally {
            @unlink($canaryAbs);
            att_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades the attachment
        }
    });

    test('attachment(#3): a legitimate in-tree attachment still reads normally (no over-rejection)', function (): void {
        $ticketId = att_seed_ticket(1);
        $relDir = 'storage/uploads/tickets/' . $ticketId;
        @mkdir(BASE_PATH . '/' . $relDir, 0775, true);
        $relPath = $relDir . '/sec3_ok_' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents(BASE_PATH . '/' . $relPath, 'legit contents');

        att_pdo()->prepare(
            'INSERT INTO ticket_attachments (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES (?, NULL, 4, "ok.txt", "ok.txt", ?, "text/plain", 14, NOW())'
        )->execute([$ticketId, $relPath]);

        try {
            $out = att_service()->getVisibleAttachment((int) att_pdo()->lastInsertId(), ['id' => 4, 'role' => 'admin']);
            assert_same('legit contents', $out['content'], 'a normal in-tree file is still served — the containment guard does not over-reject');
        } finally {
            @unlink(BASE_PATH . '/' . $relPath);
            @rmdir(BASE_PATH . '/' . $relDir);
            att_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    });

    test('attachment(#3): deleteStoredFiles refuses to unlink outside the uploads tree', function (): void {
        $svc = att_service();
        // canary outside the tree — an out-of-tree delete would remove it
        $canaryAbs = BASE_PATH . '/storage/logs/sec3_del_canary_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($canaryAbs, 'do-not-delete');
        $canaryRel = str_replace(BASE_PATH . '/', '', $canaryAbs);
        $evilRel = 'storage/uploads/tickets/../../' . substr($canaryRel, strlen('storage/'));
        // a legitimate in-tree file that SHOULD be deleted
        $okRel = 'storage/uploads/tickets/sec3del_ok_' . bin2hex(random_bytes(3)) . '.bin';
        file_put_contents(BASE_PATH . '/' . $okRel, 'y');

        try {
            $failed = $svc->deleteStoredFiles([$okRel, $evilRel, $canaryAbs]);

            assert_true(!is_file(BASE_PATH . '/' . $okRel), 'the legitimate in-tree file was deleted');
            assert_true(is_file($canaryAbs), 'the out-of-tree canary was NOT deleted (both the traversal and absolute forms are refused)');
            assert_true(in_array($evilRel, $failed, true), 'the traversal path is reported as failed so purgeStoredFiles can log it');
            assert_true(in_array($canaryAbs, $failed, true), 'the absolute out-of-tree path is reported as failed too');
        } finally {
            @unlink($canaryAbs);
            @unlink(BASE_PATH . '/' . $okRel);
        }
    });
}
