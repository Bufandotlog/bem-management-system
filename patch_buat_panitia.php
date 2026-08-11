<?php
$content = file_get_contents("/var/www/html/bem/admin/buat-panitia.php");

// 1. Independent users
$content = str_replace(
"}
foreach (\$kementerian_members as \$m) {
    \$all_members_temp[\$m['nama']][] = \$m['nama_kementerian'];
}

\$all_members = [];",
"}
foreach (\$kementerian_members as \$m) {
    \$all_members_temp[\$m['nama']][] = \$m['nama_kementerian'];
}

// Tambahkan anggota independen (users yang tidak masuk BPH/Kementerian tapi aktif)
\$independent_users = dbFetchAll(\"SELECT nama FROM users WHERE role IN ('anggota', 'kominfo') AND is_active = 1 AND (periode_id = ? OR periode_id IS NULL)\", [\$periode_id]);
foreach (\$independent_users as \$u) {
    if (!isset(\$all_members_temp[\$u['nama']])) {
        \$all_members_temp[\$u['nama']][] = 'Anggota Independen';
    }
}

\$all_members = [];", $content);

// 2. Ketuplat map
$content = str_replace(
"if (empty(\$default_nama_kegiatan) && !empty(\$kegiatan_persiapan)) {
    \$default_nama_kegiatan = \$kegiatan_persiapan[0]['nama_kegiatan'];
}",
"if (empty(\$default_nama_kegiatan) && !empty(\$kegiatan_persiapan)) {
    \$default_nama_kegiatan = \$kegiatan_persiapan[0]['nama_kegiatan'];
}

// Buat pemetaan Ketuplat berdasarkan nama_kegiatan
\$ketuplat_map = [];
foreach (\$kegiatan_persiapan as \$kg) {
    \$kp = dbFetchOne(\"SELECT users.nama FROM kegiatan_panitia kp JOIN users ON kp.user_id = users.id WHERE kp.kegiatan_id = ? AND kp.event_role = 'ketuplat' LIMIT 1\", [\$kg['id']], \"i\");
    if (\$kp) {
        \$ketuplat_map[\$kg['nama_kegiatan']] = \$kp['nama'];
    }
}
\$default_ketuplat = \$ketuplat_map[\$default_nama_kegiatan] ?? '';", $content);

$content = str_replace(
"<input type=\"text\" name=\"nama_kegiatan\" id=\"nama_kegiatan\" required list=\"kegiatanList\" autocomplete=\"off\" placeholder=\"Pilih atau ketik nama kegiatan...\" value=\"<?php echo htmlspecialchars(\$default_nama_kegiatan); ?>\" oninput=\"updateLivePreview()\">",
"<input type=\"text\" name=\"nama_kegiatan\" id=\"nama_kegiatan\" required list=\"kegiatanList\" autocomplete=\"off\" placeholder=\"Pilih atau ketik nama kegiatan...\" value=\"<?php echo htmlspecialchars(\$default_nama_kegiatan); ?>\" oninput=\"updateKetuplat(); updateLivePreview()\">", $content);

$content = str_replace(
"                                <?php 
                                \$selected = '';
                                if (\$edit_id > 0 && (\$panitia_json['ketua_pelaksana'] ?? '') === \$m['nama']) {
                                    \$selected = 'selected';
                                }
                                ?>",
"                                <?php 
                                \$selected = '';
                                if (\$edit_id > 0 && (\$panitia_json['ketua_pelaksana'] ?? '') === \$m['nama']) {
                                    \$selected = 'selected';
                                } elseif (\$edit_id == 0 && \$default_ketuplat === \$m['nama']) {
                                    \$selected = 'selected';
                                }
                                ?>", $content);

$content = str_replace(
"const defaultSeksiData = <?php echo \$edit_id > 0 ? json_encode(\$panitia_json['seksi_seksi'] ?? []) : '[]'; ?>;
const kominfoMembers = <?php echo json_encode(\$kominfo_members); ?>;",
"const defaultSeksiData = <?php echo \$edit_id > 0 ? json_encode(\$panitia_json['seksi_seksi'] ?? []) : '[]'; ?>;
const kominfoMembers = <?php echo json_encode(\$kominfo_members); ?>;
const ketuplatMap = <?php echo json_encode(\$ketuplat_map); ?>;

function updateKetuplat() {
    const inputKegiatan = document.getElementById('nama_kegiatan').value;
    const ketuplatSelect = document.getElementById('ketua_pelaksana');
    if (ketuplatMap[inputKegiatan]) {
        ketuplatSelect.value = ketuplatMap[inputKegiatan];
    }
}", $content);

// 3. Select for Seksi Nama (Dropdown) & Remove Required

$old_add_seksi = <<<EOT
    block.innerHTML = \`
        <div class="form-group">
            <label>Nama Seksi / Divisi</label>
            <input type="text" name="seksi_nama[\${seksiCounter}]" class="seksi-name-input" required placeholder="Contoh: Seksi Acara" value="\${seksiName}" oninput="updateLivePreview()">
        </div>
EOT;

$new_add_seksi = <<<EOT
    const predefinedSections = [
        'Sie Acara',
        'Sie Logistik',
        'Sie Kominfo',
        'Sie PDD',
        'Sie Konsumsi',
        'Sie Keamanan',
        'Sie Sponsorship',
        'Sie Kesekretariatan'
    ];
    
    let isPredefined = predefinedSections.includes(seksiName);
    let selectVal = isPredefined ? seksiName : (seksiName ? 'Lainnya' : '');
    
    let optionsHtml = '<option value="">-- Pilih Nama Divisi --</option>';
    predefinedSections.forEach(sec => {
        const sel = (sec === selectVal) ? 'selected' : '';
        optionsHtml += \`<option value="\${sec}" \${sel}>\${sec}</option>\`;
    });
    optionsHtml += \`<option value="Lainnya" \${selectVal === 'Lainnya' ? 'selected' : ''}>Lainnya...</option>\`;

    const customDisplay = (selectVal === 'Lainnya') ? 'block' : 'none';
    const customValue = (selectVal === 'Lainnya') ? seksiName : '';

    block.innerHTML = \`
        <div class="form-group">
            <label>Nama Seksi / Divisi</label>
            <div style="display:flex; gap:10px; flex-direction:column;">
                <select class="seksi-name-select" onchange="handleSeksiSelectChange(this, \${seksiCounter}); updateLivePreview();" style="width:100%; border:1px solid var(--border-color); background:#080808; padding:14px; border-radius:12px; color:#fff;">
                    \${optionsHtml}
                </select>
                <input type="text" class="seksi-custom-input" placeholder="Ketik nama seksi kustom..." value="\${customValue}" oninput="handleSeksiCustomChange(this, \${seksiCounter}); updateLivePreview();" style="display:\${customDisplay}; width:100%; background:#080808; border:1px solid var(--border-color); padding:14px; border-radius:12px; color:#fff;" id="seksi-custom-\${seksiCounter}">
                <input type="hidden" name="seksi_nama[\${seksiCounter}]" class="seksi-name-input" value="\${seksiName}" id="seksi-hidden-\${seksiCounter}">
            </div>
        </div>
EOT;

$content = str_replace($old_add_seksi, $new_add_seksi, $content);

// Update addAnggotaToSeksi to remove 'required'
$content = str_replace(
"<select name=\"seksi_anggota[\${seksiIndex}][]\" class=\"seksi-member-select\" required onchange=\"updateLivePreview()\" style=\"flex: 1;\">",
"<select name=\"seksi_anggota[\${seksiIndex}][]\" class=\"seksi-member-select\" onchange=\"updateLivePreview()\" style=\"flex: 1;\">", $content);

// Add the handler functions
$handler_funcs = <<<EOT
function removeSeksiBlock(index) {
    const block = document.getElementById('seksi-block-' + index);
    if (block) {
        block.remove();
        updateLivePreview();
    }
}

function handleSeksiSelectChange(selectElem, index) {
    const val = selectElem.value;
    const customInput = document.getElementById('seksi-custom-' + index);
    const hiddenInput = document.getElementById('seksi-hidden-' + index);
    
    if (val === 'Lainnya') {
        customInput.style.display = 'block';
        hiddenInput.value = customInput.value;
    } else {
        customInput.style.display = 'none';
        hiddenInput.value = val;
    }
}

function handleSeksiCustomChange(inputElem, index) {
    const hiddenInput = document.getElementById('seksi-hidden-' + index);
    hiddenInput.value = inputElem.value;
}
EOT;

$content = str_replace(
"function removeSeksiBlock(index) {
    const block = document.getElementById('seksi-block-' + index);
    if (block) {
        block.remove();
        updateLivePreview();
    }
}", $handler_funcs, $content);

file_put_contents("/var/www/html/bem/admin/buat-panitia.php", $content);
?>
