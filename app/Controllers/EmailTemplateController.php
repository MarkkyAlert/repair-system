<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\EmailTemplateRepository;
use App\Services\EmailTemplateService;
use DomainException;
use RuntimeException;

class EmailTemplateController
{
    public function __construct(
        private EmailTemplateRepository $templates,
        private EmailTemplateService $templateService,
    ) {
    }

    public function index(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        $overrides = $this->templates->getAllOverrides();
        $registry = [];
        foreach (EmailTemplateService::TEMPLATE_REGISTRY as $key => $meta) {
            $registry[$key] = $meta + ['is_customized' => isset($overrides[$key]) && $overrides[$key] !== []];
        }

        Response::view('admin/email-templates', [
            'title' => 'เทมเพลตอีเมล',
            'pageHeading' => 'ตั้งค่าข้อความอีเมล',
            'currentUser' => $viewer,
            'registry' => $registry,
        ]);
    }

    public function edit(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        require_role($viewer, ['admin'], 'หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น');

        $meta = EmailTemplateService::TEMPLATE_REGISTRY[$templateKey] ?? null;
        if ($meta === null) {
            Response::abort(404, 'ไม่พบ template ที่ต้องการแก้ไข');
        }

        $values = $this->templates->getByKey($templateKey);
        $defaults = [
            'heading' => '— ใช้ heading ที่ระบบสร้างให้ตาม event —',
            'intro' => $this->defaultIntroFor($templateKey),
            'footer_note' => 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติจากระบบแจ้งซ่อม',
        ];

        Response::view('admin/email-templates-edit', [
            'title' => 'แก้ไขเทมเพลตอีเมล',
            'pageHeading' => 'แก้ไข template: ' . (string) $meta['label'],
            'currentUser' => $viewer,
            'templateKey' => $templateKey,
            'meta' => $meta,
            'values' => $values,
            'defaults' => $defaults,
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ]);
    }

    public function update(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $userId = (int) ($viewer['id'] ?? 0);

        try {
            csrf_validate();
            assert_admin($viewer);
            $this->templateService->saveOverrides($templateKey, $_POST, $userId);
            flash('success', 'บันทึกการตั้งค่า template เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin/email-templates/' . rawurlencode($templateKey));
    }

    public function reset(string $templateKey): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            assert_admin($viewer);
            $this->templateService->resetOverrides($templateKey);
            flash('success', 'คืนค่า template เป็นค่าเริ่มต้นเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/admin/email-templates/' . rawurlencode($templateKey));
    }

    private function defaultIntroFor(string $templateKey): string
    {
        return match ($templateKey) {
            'ticket_created' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_approved' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_rejected' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_assigned' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'ticket_status_changed' => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
            'comment_event' => 'มีความเคลื่อนไหวใหม่ใน comment ของ ticket',
            'sla_breached' => 'ระบบตรวจพบ ticket ที่เกินกำหนด SLA',
            default => 'มีการอัปเดตสถานะ ticket ที่เกี่ยวข้องกับคุณ',
        };
    }
}
