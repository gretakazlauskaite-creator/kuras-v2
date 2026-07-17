<?php include __DIR__ . '/layout_top.php'; ?>

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<style>
  .ad-editor-wrap { border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
  .ad-editor-tabs { display: flex; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
  .ad-editor-tab  { padding: .4rem .9rem; font-size: .8rem; font-weight: 600; cursor: pointer;
                    color: #64748b; border: none; background: none; border-right: 1px solid #e2e8f0; }
  .ad-editor-tab.active { background: #fff; color: #0ea5e9; }
  #quill-ad { height: 200px; background: #fff; border: none; }
  .ql-toolbar.ql-snow { border: none; border-bottom: 1px solid #e2e8f0; }
  .ql-container.ql-snow { border: none; font-size: .9rem; }
  #html-ad { display: none; width: 100%; min-height: 200px; font-family: monospace; font-size: .82rem;
             padding: .75rem; border: none; resize: vertical; outline: none; line-height: 1.5; }
</style>

<h1><?= __('admin.ads.title') ?></h1>

<?php if (!empty($_GET['saved'])): ?><div class="alert alert--success"><?= __('admin.ads.saved') ?></div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="alert alert--success"><?= __('admin.ads.deleted') ?></div><?php endif; ?>

<h2 style="margin-top:2rem"><?= __('admin.ads.add_heading') ?></h2>
<form method="POST" action="/admin/ads" id="adForm" style="max-width:640px; margin-bottom:3rem;">
    <div class="form-group">
        <label><?= __('admin.ads.slot_label') ?></label>
        <select name="slot" class="input" required>
            <option value="header">header — virš turinio</option>
            <option value="sidebar">sidebar — šoninis</option>
            <option value="realestate">realestate — nekilnojamasis turtas</option>
        </select>
    </div>

    <div class="form-group">
        <label><?= __('admin.ads.html_label') ?></label>
        <div class="ad-editor-wrap">
            <div class="ad-editor-tabs">
                <button type="button" class="ad-editor-tab active" id="tabVisual">✏️ Visual</button>
                <button type="button" class="ad-editor-tab"        id="tabHtml"  >&lt;/&gt; HTML</button>
            </div>
            <div id="quill-ad"></div>
            <textarea id="html-ad" spellcheck="false"></textarea>
        </div>
        <!-- actual field submitted -->
        <textarea name="html" id="html-hidden" style="display:none" required></textarea>
    </div>

    <div style="display:flex; gap:1rem;">
        <div class="form-group" style="flex:1">
            <label><?= __('admin.ads.from_label') ?></label>
            <input type="date" name="starts_at" class="input">
        </div>
        <div class="form-group" style="flex:1">
            <label><?= __('admin.ads.to_label') ?></label>
            <input type="date" name="ends_at" class="input">
        </div>
    </div>
    <button type="submit" class="btn btn--primary"><?= __('admin.ads.add_btn') ?></button>
</form>

<h2><?= __('admin.ads.active_heading') ?></h2>
<table>
    <thead><tr>
        <th>ID</th>
        <th><?= __('admin.ads.col_slot') ?></th>
        <th><?= __('admin.ads.col_from') ?></th>
        <th><?= __('admin.ads.col_to') ?></th>
        <th><?= __('admin.ads.col_active') ?></th>
        <th></th>
    </tr></thead>
    <tbody>
        <?php foreach ($ads as $ad): ?>
        <tr>
            <td><?= $ad['id'] ?></td>
            <td><?= htmlspecialchars($ad['slot']) ?></td>
            <td><?= $ad['starts_at'] ?? '—' ?></td>
            <td><?= $ad['ends_at'] ?? '—' ?></td>
            <td><?= $ad['is_active'] ? '✅' : '❌' ?></td>
            <td>
                <form method="POST" action="/admin/ads/<?= $ad['id'] ?>/delete"
                      onsubmit="return confirm(<?= json_encode(__('admin.ads.confirm_del')) ?>)">
                    <button type="submit" class="btn btn--danger"><?= __('admin.ads.delete_btn') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($ads)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center"><?= __('admin.ads.empty') ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>

<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
const quill = new Quill('#quill-ad', {
    theme: 'snow',
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline'],
            ['link', 'image'],
            [{ align: [] }],
            ['clean']
        ]
    }
});

let mode = 'visual'; // 'visual' | 'html'

function getHtml() {
    return mode === 'visual'
        ? quill.root.innerHTML.replace(/^<p><br><\/p>$/, '')
        : document.getElementById('html-ad').value.trim();
}

document.getElementById('tabVisual').addEventListener('click', function () {
    if (mode === 'visual') return;
    // sync raw HTML → Quill
    const raw = document.getElementById('html-ad').value;
    quill.clipboard.dangerouslyPasteHTML(raw);
    document.getElementById('html-ad').style.display  = 'none';
    document.getElementById('quill-ad').style.display = 'block';
    document.querySelector('.ql-toolbar').style.display = '';
    this.classList.add('active');
    document.getElementById('tabHtml').classList.remove('active');
    mode = 'visual';
});

document.getElementById('tabHtml').addEventListener('click', function () {
    if (mode === 'html') return;
    // sync Quill → raw textarea
    document.getElementById('html-ad').value = quill.root.innerHTML.replace(/^<p><br><\/p>$/, '');
    document.getElementById('quill-ad').style.display = 'none';
    document.querySelector('.ql-toolbar').style.display = 'none';
    document.getElementById('html-ad').style.display = 'block';
    this.classList.add('active');
    document.getElementById('tabVisual').classList.remove('active');
    mode = 'html';
});

document.getElementById('adForm').addEventListener('submit', function () {
    document.getElementById('html-hidden').value = getHtml();
});
</script>

<?php include __DIR__ . '/layout_bottom.php'; ?>
