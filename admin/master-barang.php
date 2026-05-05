<?php
// admin/master-barang.php
require_once __DIR__ . '/header.php';

requireSekretaris();

$error = '';
$success = '';

// Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add') {
            $nama = sanitizeText($_POST['nama_barang'] ?? '');
            $satuan = sanitizeText($_POST['satuan'] ?? 'pcs');
            if (empty($nama)) {
                $error = 'Nama barang tidak boleh kosong.';
            } else {
                try {
                    dbQuery("INSERT INTO barang_master (nama_barang, satuan) VALUES (?, ?)", [$nama, $satuan], "ss");
                    $success = 'Barang berhasil ditambahkan.';
                    auditLog('ADD_BARANG', 'barang_master', null, 'Menambah barang: ' . $nama);
                } catch (Exception $e) {
                    $error = 'Gagal menambah barang: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = sanitizeText($_POST['nama_barang'] ?? '');
            $satuan = sanitizeText($_POST['satuan'] ?? 'pcs');
            if (empty($nama) || $id <= 0) {
                $error = 'Data tidak valid.';
            } else {
                try {
                    dbUpdate("UPDATE barang_master SET nama_barang = ?, satuan = ? WHERE id = ?", [$nama, $satuan, $id], "ssi");
                    $success = 'Barang berhasil diperbarui.';
                    auditLog('EDIT_BARANG', 'barang_master', $id, 'Update barang ke: ' . $nama);
                } catch (Exception $e) {
                    $error = 'Gagal memperbarui barang: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error = 'ID tidak valid.';
            } else {
                try {
                    dbQuery("DELETE FROM barang_master WHERE id = ?", [$id], "i");
                    $success = 'Barang berhasil dihapus.';
                    auditLog('DELETE_BARANG', 'barang_master', $id, 'Menghapus barang ID: ' . $id);
                } catch (Exception $e) {
                    $error = 'Gagal menghapus barang: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch Items
$items = dbFetchAll("SELECT * FROM barang_master ORDER BY nama_barang ASC");
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --input-bg: #0a0c10;
    --border-color: #2a3545;
    --accent-color: #4A90E2;
}

.master-barang-container {
    max-width: 1000px;
    margin: 0 auto;
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--border-color);
}

.card-header h2 {
    margin: 0;
    font-size: 1.4rem;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 12px;
}

.card-header h2 i { color: var(--accent-color); }

.premium-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
}

.premium-table th {
    padding: 12px 16px;
    text-align: left;
    color: #777;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.premium-table td {
    padding: 16px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
}

.premium-table td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
    width: 60px;
    text-align: center;
}

.premium-table td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
    text-align: right;
}

.item-name {
    font-weight: 600;
    color: #eee;
    font-size: 1rem;
}

/* Modal Simple */
.modal-mb {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0; top: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
}

.modal-content-mb {
    background: #0f1217;
    border: 1px solid var(--border-color);
    border-radius: 20px;
    width: 90%;
    max-width: 450px;
    padding: 24px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.5);
    animation: modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes modalPop {
    from { transform: scale(0.9); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

.modal-header-mb {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header-mb h3 { margin: 0; color: var(--accent-color); }

.modal-close {
    background: none;
    border: none;
    color: #777;
    font-size: 1.5rem;
    cursor: pointer;
}

.modal-body-mb input {
    width: 100%;
    background: #050505;
    border: 1px solid var(--border-color);
    padding: 12px 16px;
    border-radius: 10px;
    color: #fff;
    margin-bottom: 20px;
}

.btn-premium {
    background: var(--primary-gradient);
    border: none;
    padding: 10px 20px;
    border-radius: 10px;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
    text-decoration: none;
}

.btn-premium:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(79, 172, 254, 0.4);
}

.btn-icon {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: rgba(255,255,255,0.05);
    color: #aaa;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-icon:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}

.btn-icon.delete:hover {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
    border-color: #e74c3c;
}
</style>

<div class="master-barang-container">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-boxes"></i> Master Inventaris Barang</h2>
            <button class="btn-premium" onclick="openModal('add')">
                <i class="fas fa-plus"></i> Tambah Barang
            </button>
        </div>
        
        <div class="card-body">
            <table class="premium-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Barang</th>
                        <th>Satuan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center; color:#555; padding: 40px;">
                                <i class="fas fa-box-open" style="font-size:2rem; display:block; margin-bottom:10px;"></i>
                                Belum ada data barang.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $item): ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td class="item-name"><?php echo htmlspecialchars($item['nama_barang']); ?></td>
                                <td><?php echo htmlspecialchars($item['satuan'] ?? 'pcs'); ?></td>
                                <td>
                                    <button class="btn-icon" onclick="openModal('edit', <?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['nama_barang'])); ?>', '<?php echo htmlspecialchars(addslashes($item['satuan'] ?? 'pcs')); ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus barang ini?')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn-icon delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Add/Edit -->
<div id="modalMb" class="modal-mb">
    <div class="modal-content-mb">
        <div class="modal-header-mb">
            <h3 id="modalTitle">Tambah Barang</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="id" id="modalId" value="">
            <div class="modal-body-mb">
                <label style="color:#777; font-size:0.8rem; margin-bottom:8px; display:block;">NAMA BARANG</label>
                <input type="text" name="nama_barang" id="modalInput" required placeholder="Contoh: Proyektor Epson" autofocus>
                <label style="color:#777; font-size:0.8rem; margin-bottom:8px; display:block; margin-top:15px;">SATUAN (Contoh: pcs, unit, buah, pax)</label>
                <input type="text" name="satuan" id="modalSatuan" required placeholder="Contoh: pcs" value="pcs">
            </div>
            <div style="text-align:right;">
                <button type="button" class="btn-outline" onclick="closeModal()" style="margin-right:10px; background:none; border:1px solid #444; color:#777; padding:8px 16px; border-radius:8px; cursor:pointer;">Batal</button>
                <button type="submit" class="btn-premium">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(mode, id = null, name = '', satuan = 'pcs') {
    const modal = document.getElementById('modalMb');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('modalAction');
    const input = document.getElementById('modalInput');
    const inputSatuan = document.getElementById('modalSatuan');
    const idField = document.getElementById('modalId');
    
    if (mode === 'add') {
        title.innerText = 'Tambah Barang Baru';
        action.value = 'add';
        input.value = '';
        inputSatuan.value = 'pcs';
        idField.value = '';
    } else {
        title.innerText = 'Edit Barang';
        action.value = 'edit';
        input.value = name;
        inputSatuan.value = satuan;
        idField.value = id;
    }
    
    modal.style.display = 'flex';
    input.focus();
}

function closeModal() {
    document.getElementById('modalMb').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('modalMb')) closeModal();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
