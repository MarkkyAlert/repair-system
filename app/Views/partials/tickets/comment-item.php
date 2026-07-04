<?php
// Single comment article — ใช้ร่วมทั้งหน้า ticket detail (show.php) และ live-append feed
// (GET /tickets/{id}/comments/feed) เพื่อไม่ให้ markup ซ้ำ/หลุดกัน.
$ticketId = (int) ($ticketId ?? 0);
$isEditing = $isEditing ?? false;
$editBody = $editBody ?? (string) ($comment['body'] ?? '');
$editInternalChecked = $editInternalChecked ?? (bool) ($comment['is_internal'] ?? false);
$canUseInternalComment = $canUseInternalComment ?? false;
?>
<article class="comment-item<?= !empty($comment['is_internal']) ? ' comment-item-internal' : '' ?>" id="comment-<?= e((string) $comment['id']) ?>" data-comment-item>
    <div class="comment-meta">
        <div>
            <strong class="comment-author"><?= e($comment['author_name']) ?></strong>
            <span class="helper-text"><?= e($comment['author_role']) ?> · <?= e(human_date($comment['created_at'])) ?></span>
        </div>
        <span data-comment-badge><?= render_partial('partials/components/badge', ['label' => $comment['visibility_label'], 'tone' => $comment['visibility_tone']]) ?></span>
    </div>
    <div data-comment-view<?= $isEditing && !empty($comment['can_manage']) ? ' hidden' : '' ?>>
        <p class="comment-copy" data-comment-body><?= e($comment['body']) ?></p>
        <?php if (!empty($comment['attachments'])): ?>
            <div class="attachment-grid attachment-grid-compact">
                <?php foreach ($comment['attachments'] as $attachment): ?>
                    <?php $isCommentImg = str_starts_with((string) ($attachment['mime_type'] ?? ''), 'image/'); ?>
                    <a class="attachment-card<?= $isCommentImg ? '' : ' attachment-card-doc' ?>" href="<?= e(url($attachment['url'])) ?>" target="_blank" rel="noopener">
                        <?php if ($isCommentImg): ?>
                            <img src="<?= e(url($attachment['url'])) ?>" alt="<?= e($attachment['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="attachment-doc-icon"><?= lucide('file-text', 'h-6 w-6') ?></span>
                        <?php endif; ?>
                        <span><?= e($attachment['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($comment['can_manage'])): ?>
            <div class="button-row comment-actions">
                <a href="<?= e(url('/tickets/' . $ticketId . '?edit_comment=' . $comment['id'] . '#comment-' . $comment['id'])) ?>" class="btn btn-ghost btn-sm" aria-label="แก้ไขความเห็นของ <?= e($comment['author_name']) ?>" data-comment-edit-toggle onclick="return window.__toggleCommentEdit(this)">
                    <?= lucide('pencil', 'button-icon') ?>
                    <span>แก้ไข</span>
                </a>
                <form method="post" action="<?= e(url('/tickets/' . $ticketId . '/comments/' . $comment['id'] . '/delete')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="button" class="btn btn-ghost btn-sm btn-ghost-danger" data-comment-delete-trigger aria-label="ลบความเห็นของ <?= e($comment['author_name']) ?>"><?= lucide('trash', 'button-icon') ?><span>ลบ</span></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($comment['can_manage'])): ?>
        <div data-comment-edit-panel<?= $isEditing ? '' : ' hidden' ?>>
            <form method="post" action="<?= e(url('/tickets/' . $ticketId . '/comments/' . $comment['id'] . '/update')) ?>" class="stack-md" data-comment-edit-form onsubmit="return window.__handleInlineCommentSave ? window.__handleInlineCommentSave(this, event) : true;">
                <?= csrf_field() ?>
                <input type="hidden" name="original_updated_at" value="<?= e((string) ($comment['updated_at'] ?? '')) ?>">
                <div class="field-group">
                    <label for="comment_edit_<?= e((string) $comment['id']) ?>" class="field-label">แก้ไขความเห็น</label>
                    <textarea id="comment_edit_<?= e((string) $comment['id']) ?>" name="body" class="input" rows="3" data-comment-edit-textarea><?= e($editBody) ?></textarea>
                </div>
                <p class="field-error" data-comment-edit-error hidden></p>
                <?php if ($canUseInternalComment): ?>
                    <label class="checkbox-row checkbox-row-sm">
                        <input type="checkbox" name="is_internal" value="1"<?= $editInternalChecked ? ' checked' : '' ?>>
                        <span>บันทึกภายใน</span>
                    </label>
                <?php endif; ?>
                <div class="button-row">
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก', 'variant' => 'primary', 'size' => 'sm']) ?>
                    <a href="<?= e(url('/tickets/' . $ticketId . '#comment-' . $comment['id'])) ?>" class="btn btn-ghost btn-sm" data-comment-edit-cancel onclick="return window.__cancelCommentEdit(this)">
                        <span>ยกเลิก</span>
                    </a>
                </div>
            </form>
        </div>
    <?php endif; ?>
</article>
