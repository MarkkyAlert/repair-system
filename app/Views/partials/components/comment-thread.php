<section class="comment-thread">
    <h3 class="panel-title"><?= e($title ?? 'ความเห็นและบทสนทนา') ?></h3>
    <div class="comment-list">
        <article class="comment-item">
            <div class="comment-meta">
                <p class="comment-author">Demo User</p>
                <span class="badge badge-default">Public</span>
            </div>
            <p class="comment-copy">ติดตามการสนทนาและรายละเอียดเพิ่มเติมของงานได้จากพื้นที่นี้</p>
        </article>
        <article class="comment-item comment-item-internal">
            <div class="comment-meta">
                <p class="comment-author">Technician</p>
                <span class="badge badge-warning">Internal note</span>
            </div>
            <p class="comment-copy">Requester จะไม่เห็นข้อความประเภท internal note</p>
        </article>
    </div>
</section>
