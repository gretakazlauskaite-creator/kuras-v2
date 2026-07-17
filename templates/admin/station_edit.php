<?php include __DIR__ . '/layout_top.php'; ?>

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<style>
  #quill-editor { height: 260px; background: #fff; border-radius: 0 0 6px 6px; }
  .ql-toolbar.ql-snow { border-radius: 6px 6px 0 0; border-color: #e2e8f0; }
  .ql-container.ql-snow { border-color: #e2e8f0; font-size: .95rem; }
</style>

<h1><?= __('admin.edit.title', ['id' => $station['id']]) ?></h1>
<h2 style="font-weight:400; color:#64748b"><?= htmlspecialchars($station['name']) ?></h2>

<form method="POST" enctype="multipart/form-data" style="max-width:640px;" id="editForm">
    <div class="form-group">
        <label><?= __('admin.edit.services') ?></label>
        <div style="display:flex; gap:1rem; flex-wrap:wrap;">
            <label><input type="checkbox" name="has_coffee"  <?= $station['has_coffee']  ? 'checked' : '' ?>> ☕ <?= __('station.svc_coffee') ?></label>
            <label><input type="checkbox" name="has_carwash" <?= $station['has_carwash'] ? 'checked' : '' ?>> 🚗 <?= __('station.svc_carwash') ?></label>
            <label><input type="checkbox" name="has_shop"    <?= $station['has_shop']    ? 'checked' : '' ?>> 🛒 <?= __('station.svc_shop') ?></label>
            <label><input type="checkbox" name="has_loyalty" <?= $station['has_loyalty'] ? 'checked' : '' ?>> 💳 <?= __('station.svc_loyalty') ?></label>
        </div>
    </div>

    <div class="form-group">
        <label><?= __('admin.edit.description') ?></label>
        <!-- Visual editor -->
        <div id="quill-editor"></div>
        <!-- Hidden textarea — value synced on submit -->
        <textarea name="profile_text" id="profile_text_hidden" style="display:none"><?= htmlspecialchars($station['profile_text'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label>
            <input type="checkbox" name="is_sponsored" <?= $station['is_sponsored'] ? 'checked' : '' ?>>
            <?= __('admin.edit.sponsored') ?>
        </label>
    </div>

    <div class="form-group">
        <label><?= __('admin.edit.promo') ?></label>
        <?php if ($station['promo_banner']): ?>
            <div style="margin-bottom:.5rem"><img src="<?= htmlspecialchars($station['promo_banner']) ?>" style="max-width:300px; border-radius:6px;"></div>
        <?php endif; ?>
        <input type="file" name="promo_banner" accept="image/*" class="input">
    </div>

    <button type="submit" class="btn btn--primary"><?= __('admin.edit.save') ?></button>
    <a href="/admin/stations" class="btn btn--secondary"><?= __('admin.edit.back') ?></a>
</form>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const quill = new Quill('#quill-editor', {
    theme: 'snow',
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link'],
            ['clean']
        ]
    }
});

// Load existing content
const existing = document.getElementById('profile_text_hidden').value.trim();
if (existing) {
    quill.clipboard.dangerouslyPasteHTML(existing);
}

// Sync to hidden textarea before submit
document.getElementById('editForm').addEventListener('submit', function () {
    document.getElementById('profile_text_hidden').value = quill.root.innerHTML;
});
</script>

<?php include __DIR__ . '/layout_bottom.php'; ?>
