const { JSDOM } = require("jsdom");

// Data nyata kegiatan 20 (PKKMB)
const acaraRecPemateri = [
  { label: 'Penyampaian Materi Bapak Dr. H. Sudibyo BO', val: 'Penyampaian Materi " "', ket: 'Yang disampaikan oleh Bapak Dr. H. Sudibyo BO' },
  { label: 'Penyampaian Materi Bapak Anto Herianto', val: 'Penyampaian Materi " "', ket: 'Yang disampaikan oleh Bapak Anto Herianto' },
];
const acaraRecSambutan = [
  { label: 'Penyampaian sambutan Bapak Ii Muhamad Misbah', val: 'Penyampaian sambutan', ket: 'Sambutan oleh Bapak Ii Muhamad Misbah' },
];

const dom = new JSDOM(`<!DOCTYPE html><body>
<table><tbody class="rundownBody"></tbody></table>
</body>`, { runScripts: "outside-only" });
const { window } = dom;
const document = window.document;
global.window = window; global.document = document;
window.document.body.insertAdjacentHTML('afterbegin', '<div id="daysContainer"><div class="day-card" id="day-1"><tbody class="rundownBody"></tbody></div></div>');

// Replika addRow (hanya bagian ACARA + KET + klik target)
function buildRow() {
  const tbody = document.querySelector('.rundownBody');
  const tr = document.createElement('tr');
  tr.className = 'main-row';
  tr.innerHTML = `
    <td data-label="ACARA">
      <div class="tpl-picker" style="position:relative;">
        <input type="text" name="acara[1][]" class="tpl-search-input" autocomplete="off">
        <div class="tpl-results">
          <div class="tpl-group-label">Pemateri</div>
          ${acaraRecPemateri.map(o => `<div class="tpl-item tpl-pemateri" data-val='${o.val}' data-ket='${o.ket}'><span class="tpl-badge tpl-badge-pemateri">Pemateri</span>${o.label}</div>`).join('')}
          <div class="tpl-group-label">Sambutan</div>
          ${acaraRecSambutan.map(o => `<div class="tpl-item tpl-sambutan" data-val='${o.val}' data-ket='${o.ket}'><span class="tpl-badge tpl-badge-sambutan">Sambutan</span>${o.label}</div>`).join('')}
        </div>
      </div>
    </td>
    <td data-label="KET/TEMPAT">
      <select class="ket-tempat-select"><option value="ket">Ket.</option></select>
      <div class="tpl-picker">
        <input type="text" name="keterangan[1][]" class="tpl-search-input" autocomplete="off">
        <div class="tpl-results"></div>
      </div>
    </td>`;
  tbody.appendChild(tr);
  return tr;
}

// Replika handler klik (persis dari file 1527-1548)
document.addEventListener('click', function(e) {
  if (e.target.closest('.tpl-item')) {
    const item = e.target.closest('.tpl-item');
    const value = item.dataset.val || item.innerText;
    const picker = item.closest('.tpl-picker');
    const input = picker.querySelector('.tpl-search-input');
    input.value = value;
    if (item.dataset.ket) {
      const row = item.closest('tr');
      const ketInput = row ? row.querySelector('input[name^="keterangan"]') : null;
      if (ketInput && ketInput.value.trim() === '') {
        ketInput.value = item.dataset.ket;
      }
    }
    picker.querySelector('.tpl-results').style.display = 'none';
  }
});

const row = buildRow();
// Cari item sambutan & klik
const sambutanItem = row.querySelector('.tpl-item.tpl-sambutan');
console.log("Sambutan item ditemukan:", !!sambutanItem);
console.log("  data-val:", sambutanItem && sambutanItem.dataset.val);
console.log("  data-ket:", sambutanItem && sambutanItem.dataset.ket);

// Simulasi klik pada item (event delegation lewat document)
const clickEvt = new window.MouseEvent('click', { bubbles: true });
sambutanItem.dispatchEvent(clickEvt);

const acaraInput = row.querySelector('input[name^="acara"]');
const ketInput = row.querySelector('input[name^="keterangan"]');
console.log("=== HASIL KLIK SAMBUTAN ===");
console.log("ACARA value :", JSON.stringify(acaraInput.value));
console.log("KET value   :", JSON.stringify(ketInput.value));
console.log("ACARA terisi?", acaraInput.value === 'Penyampaian sambutan' ? "YA" : "TIDAK");
