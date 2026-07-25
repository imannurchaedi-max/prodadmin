(function () {
  const App = window.ProdApp;
  const state = App.state;

  /**
   * Parse a number string that may be in Indonesian (id-ID) OR US (en-US) locale format.
   *
   * Detection rules (auto-senses which locale the keyboard used):
   *   Both '.' and ',':  last one wins as decimal separator
   *     "1.234,56" → 1234.56  (ID: dot=thousands, comma=decimal)
   *     "1,234.56" → 1234.56  (US: comma=thousands, dot=decimal)
   *   Comma only:  comma is decimal UNLESS exactly 3 digits follow it (then thousands)
   *     "11,1"  → 11.1   |  "11,12"  → 11.12  |  "11,000" → 11000
   *   Dot only:   dot is decimal UNLESS exactly 3 digits follow it (then thousands)
   *     "11.1"  → 11.1   |  "11.12"  → 11.12  |  "11.000" → 11000
   *   Also strips: trailing %, invisible unicode (NBSP, zero-width, BOM, etc.)
   */
  function parseLocalizedFloat(str) {
    if (str === null || str === undefined) return 0;
    // Strips invisible unicode that can appear via paste or Android IMEs:
    //   \u00A0 NBSP  \u00AD soft-hyphen  \u200B-D zero-width  \u202F narrow-NBSP
    //   \u2018-9 curly-quotes  \uFEFF BOM  \u2028-9 line/para-sep
    let s = String(str)
      .replace(/[\u00A0\u00AD\u200B-\u200D\u202F\u2018\u2019\uFEFF\u2028\u2029]/g, "")
      .trim()
      .replace(/%$/, "");
    if (!s) return 0;

    const hasDot   = s.includes(".");
    const hasComma = s.includes(",");
    let normalized;

    if (hasDot && hasComma) {
      // Both present: whichever appears LAST is the decimal separator
      if (s.lastIndexOf(".") > s.lastIndexOf(",")) {
        normalized = s.replace(/,/g, "");                     // US: "1,234.56"
      } else {
        normalized = s.replace(/\./g, "").replace(",", "."); // ID: "1.234,56"
      }
    } else if (hasComma) {
      // Comma only: 3 digits at end = thousands ("11,000"->11000) else decimal ("11,1"->11.1)
      normalized = /,\d{3}$/.test(s) ? s.replace(/,/g, "") : s.replace(",", ".");
    } else if (hasDot) {
      // Dot only: 3 digits at end = thousands ("11.000"->11000) else decimal ("11.1"->11.1)
      normalized = /\.\d{3}$/.test(s) ? s.replace(/\./g, "") : s;
    } else {
      normalized = s;
    }

    // Safety: strip remaining non-numeric chars except decimal point
    normalized = normalized.replace(/[^\d.]/g, "");
    const v = parseFloat(normalized);
    return isNaN(v) ? 0 : v;
  }

  function fmtInt(n) {
    return Number(n || 0).toLocaleString("id-ID", { maximumFractionDigits: 0 });
  }
  function fmtMat(n) {
    const v = Number(n || 0);
    return v.toLocaleString("id-ID", { maximumFractionDigits: 4, minimumFractionDigits: 0 });
  }
  function parseIntField(s) {
    return parseInt(String(s || "").replace(/\D/g, ""), 10) || 0;
  }

  function ensureOutputRows() {
    if (!state.outputs.length && state.conversions.length) {
      state.outputs.push({ mid: "", qtyBox: 0, counterPcs: 0, totalKg: 0, lossKg: 0 });
    }
  }

  function defaultMaterialRow(name, supplier = "") {
    return {
      material: name,
      supplier,
      stockAwal: 0,
      masuk: 0,
      retur: 0,
      reject: 0,
      hours: new Array(8).fill(0),
      photos: [],
    };
  }

  function getActiveMaterials() {
    const rows = [];
    document.querySelectorAll("#tableBody tr[data-material-row]").forEach((tr) => {
      const material = tr.getAttribute("data-material");
      const supplier = tr.querySelector('[data-field="supplier"]')?.value || "";
      const hours = Array.from(tr.querySelectorAll('[data-hour]')).map((input) => parseLocalizedFloat(input.value));
      rows.push({
        material,
        supplier,
        stockAwal: parseLocalizedFloat(tr.querySelector('[data-field="stockAwal"]')?.value),
        masuk: parseLocalizedFloat(tr.querySelector('[data-field="masuk"]')?.value),
        retur: parseLocalizedFloat(tr.querySelector('[data-field="retur"]')?.value),
        reject: parseLocalizedFloat(tr.querySelector('[data-field="reject"]')?.value),
        hours,
        photos: (state.materialPhotos && state.materialPhotos[material]) || [],
      });
    });
    return rows;
  }

  function draftKey() {
    return `prodadmin_draft_${state.username || 'user'}_${state.machine || 'mesin'}`;
  }

  let _draftTimer = null;
  window.saveDraft = function saveDraft() {
    if (state.editUuid) return; // jangan overwrite draft saat mode revisi
    snapshotRows();
    collectOutputs();
    try {
      const draft = {
        v: 1,
        savedAt: Date.now(),
        tanggal: document.getElementById("inputTanggal")?.value || "",
        shift: document.getElementById("inputShift")?.value || "1",
        mesin: document.getElementById("inputMesin")?.value || "",
        size: document.getElementById("inputSize")?.value || "",
        formRows: JSON.parse(JSON.stringify(state.formRows)),
        outputs: JSON.parse(JSON.stringify(state.outputs)),
        rpt: {
          downtimeMin: document.getElementById("rptDowntimeMin")?.value || 0,
          speed: document.getElementById("rptSpeed")?.value || 0,
          trouble: document.getElementById("rptTrouble")?.value || "",
          nearMiss: document.getElementById("rptNearMiss")?.value || "",
          notes: document.getElementById("rptNotes")?.value || "",
          rejectPrinting: document.getElementById("rptRejectPrinting")?.value || 0,
        },
      };
      localStorage.setItem(draftKey(), JSON.stringify(draft));
      const indicator = document.getElementById("draftSavedAt");
      if (indicator) {
        const t = new Date(draft.savedAt);
        indicator.textContent = `Draft disimpan ${t.getHours().toString().padStart(2,'0')}:${t.getMinutes().toString().padStart(2,'0')}`;
        indicator.classList.remove("d-none");
      }
    } catch (e) {}
  };

  function autoSaveDraft() {
    if (state.editUuid) return;
    clearTimeout(_draftTimer);
    _draftTimer = setTimeout(window.saveDraft, 3000);
  }

  function restoreDraft(draft) {
    if (draft.tanggal) document.getElementById("inputTanggal").value = draft.tanggal;
    if (draft.shift) document.getElementById("inputShift").value = draft.shift;
    if (draft.mesin) document.getElementById("inputMesin").value = draft.mesin;
    if (draft.size) document.getElementById("inputSize").value = draft.size;
    state.formRows = draft.formRows || {};
    state.outputs = draft.outputs || [];
    const rpt = draft.rpt || {};
    if (document.getElementById("rptDowntimeMin")) document.getElementById("rptDowntimeMin").value = rpt.downtimeMin || 0;
    if (document.getElementById("rptSpeed")) document.getElementById("rptSpeed").value = rpt.speed || 0;
    if (document.getElementById("rptTrouble")) document.getElementById("rptTrouble").value = rpt.trouble || "";
    if (document.getElementById("rptNearMiss")) document.getElementById("rptNearMiss").value = rpt.nearMiss || "";
    if (document.getElementById("rptNotes")) document.getElementById("rptNotes").value = rpt.notes || "";
    if (document.getElementById("rptRejectPrinting")) document.getElementById("rptRejectPrinting").value = rpt.rejectPrinting || 0;
    renderMaterialTable();
    renderOutputs();
    refreshAnalysis();
  }

  window.discardDraft = function discardDraft() {
    localStorage.removeItem(draftKey());
    clearTimeout(_draftTimer);
    _draftTimer = null;
    const wasBannerVisible = !!window._pendingDraft;
    window._pendingDraft = null;
    const banner = document.getElementById("draftBanner");
    if (banner) banner.classList.add("d-none");
    const indicator = document.getElementById("draftSavedAt");
    if (indicator) indicator.classList.add("d-none");
    // Reset form hanya jika user klik "Abaikan" (banner masih showing saat dipanggil)
    if (wasBannerVisible && !state.editUuid) {
      state.formRows = {};
      state.outputs  = [];
      state.materialPhotos = {};
      ["rptDowntimeMin","rptDowntimePct","rptSpeed","rptTrouble","rptNearMiss","rptNotes","rptRejectPrinting"].forEach((id) => {
        const el = document.getElementById(id); if (el) el.value = "";
      });
      renderMaterialTable();
      renderOutputs();
      refreshAnalysis();
    }
  };

  // The draft's values are already loaded into the form by the time this banner is
  // visible (see loadInitialData's pre-load block) -- this only hides the notice.
  // It intentionally leaves window._pendingDraft set so discardDraft() still knows
  // to reset the form if the user later clicks "Mulai Baru".
  window.dismissDraftBanner = function dismissDraftBanner() {
    const banner = document.getElementById("draftBanner");
    if (banner) banner.classList.add("d-none");
  };

  function setDefaults() {
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const tanggal = document.getElementById("inputTanggal");
    if (tanggal && !tanggal.value) tanggal.value = today;
    const filterStart = document.getElementById("filterStartDate");
    const filterEnd = document.getElementById("filterEndDate");
    const filterStartAdmin = document.getElementById("filterStartDateAdmin");
    const filterEndAdmin = document.getElementById("filterEndDateAdmin");
    if (filterStart && !filterStart.value) filterStart.value = monthAgo;
    if (filterEnd && !filterEnd.value) filterEnd.value = today;
    if (filterStartAdmin && !filterStartAdmin.value) filterStartAdmin.value = monthAgo;
    if (filterEndAdmin && !filterEndAdmin.value) filterEndAdmin.value = today;
    if (state.machine && document.getElementById("inputMesin")) {
      document.getElementById("inputMesin").value = state.machine;
    }
  }

  function getDateFilterValue(id, fallback) {
    const el = document.getElementById(id);
    const value = el?.value || "";
    return value || fallback;
  }

  function populateSelect(id, options, selected = "") {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = options.map((item) => `<option value="${App.esc(item)}" ${item === selected ? "selected" : ""}>${App.esc(item)}</option>`).join("");
  }

  function renderMaterialTable(filterText = "") {
    const hours = App.currentShiftHours();
    const tbody = document.getElementById("tableBody");
    const head = document.querySelector("#mainTable thead");
    if (!tbody || !head) return;
    const materials = state.materials.filter((item) => item.toLowerCase().includes(filterText.toLowerCase()));
    const stickyTh = 'position:sticky;top:0;z-index:2;background:var(--bs-body-bg,#fff);box-shadow:inset 0 -1px 0 #dee2e6;';
    head.innerHTML = `
      <tr class="text-secondary small text-uppercase">
        <th style="${stickyTh}width:16%;padding-left:20px;">Material Name</th>
        <th style="${stickyTh}width:10%;">Supplier</th>
        <th class="text-center" style="${stickyTh}width:4.5%;">Stk Awal</th>
        <th class="text-center" style="${stickyTh}width:4.5%;">Masuk</th>
        <th class="text-center text-danger" style="${stickyTh}width:4.5%;">Retur</th>
        <th class="text-center text-danger" style="${stickyTh}width:4.5%;">Reject</th>
        ${hours.map((hour) => `<th class="text-center text-primary" style="${stickyTh}width:4.5%;font-size:0.7rem;padding:0;">${hour}</th>`).join("")}
        <th class="text-center bg-prod-subtle" style="${stickyTh}width:5.5%;">Tot. Prod</th>
        <th class="text-center bg-prod-subtle" style="${stickyTh}width:5.5%;">Grand Total</th>
        <th class="text-center bg-sisa-subtle" style="${stickyTh}width:5.5%;">Sisa</th>
      </tr>
    `;
    tbody.innerHTML = materials.map((material) => {
      const existing = (state.formRows && state.formRows[material]) || defaultMaterialRow(material);
      const totProd = existing.hours.reduce((a, b) => a + Number(b || 0), 0);
      const total = totProd + Number(existing.reject || 0);
      const sisa = Number(existing.stockAwal || 0) + Number(existing.masuk || 0) - Number(existing.retur || 0) - total;
      const photoCount = state.materialPhotos?.[material]?.length || 0;
      return `
        <tr data-material-row="1" data-material="${App.esc(material)}">
          <td class="fw-bold ps-4 text-muted" style="cursor:pointer;user-select:none;" data-photo-cell="${App.esc(material)}" title="Klik untuk upload foto label">
            ${App.esc(material)}
            <span data-photo-badge="${App.esc(material)}" class="ms-1">
              ${photoCount > 0
                ? `<span class="badge bg-primary rounded-pill" style="font-size:0.6rem">${photoCount}</span>`
                : `<i class="fa-regular fa-image" style="font-size:0.75rem;color:#ced4da;"></i>`}
            </span>
          </td>
          <td>
            <select class="form-select form-select-sm table-input text-start" data-field="supplier" style="font-size:0.85rem; min-width:130px;">
              <option value="">- Pilih -</option>
              ${(state.suppliers.map[material] || []).map((supplier) => `<option value="${App.esc(supplier)}" ${supplier === existing.supplier ? "selected" : ""}>${App.esc(supplier)}</option>`).join("")}
            </select>
          </td>
          <td><input class="table-input" data-field="stockAwal" type="text" inputmode="decimal" value="${fmtMat(existing.stockAwal)}"></td>
          <td><input class="table-input" data-field="masuk" type="text" inputmode="decimal" value="${fmtMat(existing.masuk)}"></td>
          <td><input class="table-input" data-field="retur" type="text" inputmode="decimal" value="${fmtMat(existing.retur)}"></td>
          <td><input class="table-input text-danger fw-bold" data-field="reject" type="text" inputmode="decimal" value="${fmtMat(existing.reject)}"></td>
          ${existing.hours.map((value, index) => `<td><input class="table-input" data-hour="${index}" type="text" inputmode="decimal" value="${fmtMat(value)}"></td>`).join("")}
          <td class="text-center bg-prod-subtle"><span class="calc-cell fw-bold text-primary">${App.fmt(totProd)}</span></td>
          <td class="text-center bg-prod-subtle"><span class="calc-cell fw-bold text-secondary">${App.fmt(total)}</span></td>
          <td class="text-center bg-sisa-subtle"><span class="calc-cell fw-bold ${sisa < 0 ? "text-danger" : ""}">${App.fmt(sisa)}</span></td>
        </tr>
      `;
    }).join("");
    document.getElementById("shiftBadge").textContent = `SHIFT ${document.getElementById("inputShift")?.value || "1"}`;
  }

  function refreshMaterialRowCalculations() {
    document.querySelectorAll("#tableBody tr[data-material-row]").forEach((tr) => {
      const stockAwal = parseLocalizedFloat(tr.querySelector('[data-field="stockAwal"]')?.value);
      const masuk = parseLocalizedFloat(tr.querySelector('[data-field="masuk"]')?.value);
      const retur = parseLocalizedFloat(tr.querySelector('[data-field="retur"]')?.value);
      const reject = parseLocalizedFloat(tr.querySelector('[data-field="reject"]')?.value);
      const hours = Array.from(tr.querySelectorAll('[data-hour]')).map((input) => parseLocalizedFloat(input.value));
      const totProd = hours.reduce((sum, value) => sum + value, 0);
      const total = totProd + reject;
      const sisa = stockAwal + masuk - retur - total;
      const cells = tr.querySelectorAll(".calc-cell");
      if (cells[0]) cells[0].textContent = App.fmt(totProd);
      if (cells[1]) cells[1].textContent = App.fmt(total);
      if (cells[2]) {
        cells[2].textContent = App.fmt(sisa);
        cells[2].classList.toggle("text-danger", sisa < 0);
      }
    });
  }

  function snapshotRows() {
    state.formRows = {};
    getActiveMaterials().forEach((row) => { state.formRows[row.material] = row; });
  }

  async function openPhotoModal(material) {
    if (!state.materialPhotos) state.materialPhotos = {};
    const MAX = 20;
    let photoIds = [...(state.materialPhotos[material] || [])];
    const allBlobUrls = [];

    const fetchThumb = async (photoId) => {
      try {
        const r = await fetch('api/photos.php?file=' + encodeURIComponent(photoId), {
          headers: { 'Authorization': 'Bearer ' + (App.state.token || '') }
        });
        if (!r.ok) return null;
        const blob = await r.blob();
        const url = URL.createObjectURL(blob);
        allBlobUrls.push(url);
        return url;
      } catch (_) { return null; }
    };

    const thumbUrls = await Promise.all(photoIds.map(fetchThumb));

    const buildGrid = () => {
      if (!photoIds.length) {
        return '<div class="text-center text-muted small py-4">Belum ada foto. Klik "Tambah Foto" untuk upload.</div>';
      }
      return '<div class="d-flex flex-wrap gap-2">' + photoIds.map((id, i) => {
        const url = thumbUrls[i];
        if (!url) return '';
        return `<div style="position:relative;width:90px;height:90px;flex-shrink:0;">
          <img src="${url}" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6;">
          <button type="button" class="pm-del" data-idx="${i}" style="position:absolute;top:-7px;right:-7px;width:22px;height:22px;background:#dc3545;color:white;border:none;border-radius:50%;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
        </div>`;
      }).join('') + '</div>';
    };

    const updateBadge = () => {
      const badge = document.querySelector(`[data-photo-badge="${CSS.escape(material)}"]`);
      if (!badge) return;
      badge.innerHTML = photoIds.length > 0
        ? `<span class="badge bg-primary rounded-pill" style="font-size:0.6rem">${photoIds.length}</span>`
        : '<i class="fa-regular fa-image" style="font-size:0.75rem;color:#ced4da;"></i>';
    };

    await Swal.fire({
      title: `<span style="font-size:1rem"><i class="fa-solid fa-camera me-2 text-primary"></i>Foto Label &mdash; ${App.esc(material)}</span>`,
      html: `<div class="text-start">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span id="pmCount" class="small text-muted">${photoIds.length} / ${MAX} foto</span>
          <label class="btn btn-sm btn-primary" for="pmFileInput" id="pmAddLabel">
            <i class="fa-solid fa-plus me-1"></i>Tambah Foto
          </label>
          <input type="file" id="pmFileInput" accept="image/*" multiple style="display:none">
        </div>
        <div id="pmGrid">${buildGrid()}</div>
        <div id="pmStatus" class="mt-2 small text-primary" style="min-height:1.2em"></div>
      </div>`,
      width: 'min(520px, 96vw)',
      showCancelButton: false,
      confirmButtonText: 'Selesai',
      showCloseButton: true,
      didOpen: () => {
        const grid    = document.getElementById('pmGrid');
        const status  = document.getElementById('pmStatus');
        const count   = document.getElementById('pmCount');
        const addLabel = document.getElementById('pmAddLabel');

        grid.addEventListener('click', (e) => {
          const btn = e.target.closest('.pm-del');
          if (!btn) return;
          const idx = parseInt(btn.getAttribute('data-idx'));
          photoIds.splice(idx, 1);
          thumbUrls.splice(idx, 1);
          grid.innerHTML = buildGrid();
          count.textContent = `${photoIds.length} / ${MAX} foto`;
        });

        document.getElementById('pmFileInput').addEventListener('change', async (e) => {
          const files = Array.from(e.target.files);
          e.target.value = '';
          if (!files.length) return;
          const remaining = MAX - photoIds.length;
          if (remaining <= 0) { App.toast('warning', `Maksimal ${MAX} foto per material.`); return; }
          const toUpload = files.slice(0, remaining);
          if (files.length > remaining) App.toast('warning', `Hanya ${remaining} slot tersisa, ${files.length - remaining} foto dilewati.`);

          status.textContent = `Mengupload ${toUpload.length} foto…`;
          addLabel.classList.add('disabled', 'pe-none');
          try {
            const fileData = await Promise.all(toUpload.map(f => new Promise((res, rej) => {
              const reader = new FileReader();
              reader.onload = ev => res({ base64: ev.target.result, name: f.name });
              reader.onerror = rej;
              reader.readAsDataURL(f);
            })));

            const newIds = [];
            for (let i = 0; i < fileData.length; i += 10) {
              const res = await App.api('api/photos.php', {
                method: 'POST',
                body: JSON.stringify({ files: fileData.slice(i, i + 10) })
              });
              if (res.success) newIds.push(...res.data.fileIds);
            }

            const newUrls = await Promise.all(newIds.map(fetchThumb));
            photoIds.push(...newIds);
            thumbUrls.push(...newUrls);
            grid.innerHTML = buildGrid();
            count.textContent = `${photoIds.length} / ${MAX} foto`;
            status.textContent = `${newIds.length} foto berhasil diupload.`;
            setTimeout(() => { if (status) status.textContent = ''; }, 3000);
          } catch (err) {
            status.textContent = 'Gagal upload: ' + (err.message || 'error');
          } finally {
            addLabel.classList.remove('disabled', 'pe-none');
          }
        });
      },
      willClose: () => {
        state.materialPhotos[material] = [...photoIds];
        updateBadge();
        allBlobUrls.forEach(u => URL.revokeObjectURL(u));
      }
    });
  }

  function renderOutputs() {
    ensureOutputRows();
    const container = document.getElementById("outputRowsContainer");
    const productionContainer = document.getElementById("dynamicProductionContainer");
    if (!container || !productionContainer) return;
    container.innerHTML = state.outputs.map((output, index) => {
      const product = state.conversions.find((item) => item.mid === output.mid) || {};
      return `
        <div class="row g-2 align-items-end mb-3 output-row" data-output-row="${index}">
          <div class="col-12 col-md-3">
            <label class="small fw-bold text-muted">Produk</label>
            <select class="form-select output-mid">
              <option value="">Pilih produk...</option>
              ${state.conversions.map((item) => `<option value="${App.esc(item.mid)}" ${item.mid === output.mid ? "selected" : ""}>${App.esc(item.mid)} - ${App.esc(item.name)}</option>`).join("")}
            </select>
          </div>
          <div class="col-6 col-md-2"><label class="small fw-bold text-muted">Qty Box</label><input class="form-control output-qty" type="number" value="${output.qtyBox || 0}"></div>
          <div class="col-6 col-md-2"><label class="small fw-bold text-muted">Counter PCS</label><input class="form-control output-counter" type="number" value="${output.counterPcs || 0}"></div>
          <div class="col-6 col-md-2"><label class="small fw-bold text-muted">Total Kg${product.weight ? ' <span class="badge text-bg-secondary ms-1" style="font-size:0.6rem;vertical-align:middle;">auto</span>' : ""}</label><input class="form-control output-kg" type="number" step="0.01" value="${output.totalKg || 0}"></div>
          <div class="col-6 col-md-2"><label class="small fw-bold text-muted">Loss Kg</label><input class="form-control output-loss" type="number" step="0.01" value="${output.lossKg || 0}"></div>
          <div class="col-12 col-md-1"><button class="btn btn-outline-danger w-100 btn-remove-output"><i class="fa-solid fa-trash"></i></button></div>
          <div class="col-12 small text-muted">${App.esc(product.catBag || "-")} / ${App.esc(product.catBox || "-")} ${product.weight ? `| ${App.fmt(product.weight)} g` : ""}</div>
        </div>
      `;
    }).join("");

    productionContainer.innerHTML = state.outputs.length ? state.outputs.map((output) => {
      const product = state.conversions.find((item) => item.mid === output.mid) || { name: "-" };
      return `
        <div class="row border rounded p-3 mb-2">
          <div class="col-md-4 fw-bold">${App.esc(product.name || "-")}</div>
          <div class="col-md-2">Box: <strong>${App.fmt(output.qtyBox || 0, 0)}</strong></div>
          <div class="col-md-2">PCS: <strong>${App.fmt(output.counterPcs || 0, 0)}</strong></div>
          <div class="col-md-2">Kg: <strong>${App.fmt(output.totalKg || 0)}</strong></div>
          <div class="col-md-2">Loss: <strong>${App.fmt(output.lossKg || 0)}</strong></div>
        </div>
      `;
    }).join("") : '<div class="text-center text-muted py-5 border rounded">Belum ada output.</div>';
  }

  // Shared by refreshAnalysis() (live form) and the history "detail"/"whatsapp" export,
  // so the Boros/Hemat/Pas verdict is computed identically everywhere it's shown.
  // outputs: [{mid, qtyBox|qty}]  materials: [{material, reject, prod?|hours?}]
  function computeMaterialAnalysis(outputs, materials) {
    // Build theoretical & linked count keyed by UPPERCASE material name
    const theoretical = {};
    const linkedCount = {};
    (outputs || []).forEach((output) => {
      const product = state.conversions.find((p) => p.mid === output.mid);
      if (!product) return;
      const qty = Number(output.qtyBox ?? output.qty ?? 0);
      if (product.catBag) {
        const k = product.catBag.toUpperCase();
        theoretical[k] = (theoretical[k] || 0) + qty * Number(product.ratio || 0);
        linkedCount[k] = (linkedCount[k] || 0) + 1;
      }
      if (product.catBox) {
        const k = product.catBox.toUpperCase();
        theoretical[k] = (theoretical[k] || 0) + qty;
        linkedCount[k] = (linkedCount[k] || 0) + 1;
      }
    });

    // Build actual from material rows (live table gives `hours`, history gives `prod` directly)
    const actualProd = {};
    const actualReject = {};
    const displayName = {};
    (materials || []).forEach((row) => {
      const k = (row.material || "").toUpperCase();
      if (!k) return;
      actualProd[k] = row.prod != null
        ? Number(row.prod)
        : Array.isArray(row.hours) ? row.hours.reduce((a, b) => a + Number(b || 0), 0) : 0;
      actualReject[k] = Number(row.reject || 0);
      displayName[k]  = row.material;
    });

    return Object.keys(theoretical).filter(k => (theoretical[k] || 0) > 0).map((k) => {
      const name      = displayName[k] || k;
      const stdTarget = Number(theoretical[k] || 0);
      const totProd   = Number(actualProd[k]  || 0);
      const reject    = Number(actualReject[k] || 0);
      const grand     = totProd + reject;
      // Target = STD + Reject (pemakaian yang dibenarkan)
      const targetAdj = stdTarget + reject;
      const linked    = linkedCount[k] || 0;
      const hasTheo   = stdTarget > 0;
      const hasAct    = grand > 0 || reject > 0;
      // Selisih = Grand Total vs (STD + Reject)
      const diff      = grand - targetAdj;
      const isOk      = Math.abs(diff) < 0.0001;
      let status;
      if (!hasTheo)      status = 'no_output';
      else if (!hasAct)  status = 'no_laporan';
      else if (isOk)     status = 'pas';
      else if (diff > 0) status = 'boros';
      else               status = 'hemat';
      return { name, stdTarget, totProd, reject, grand, targetAdj, linked, hasTheo, hasAct, diff, isOk, status };
    });
  }

  function refreshAnalysis() {
    const body = document.getElementById("analysisBody");
    if (!body) return;
    const rows = computeMaterialAnalysis(state.outputs, getActiveMaterials());

    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="text-muted fst-italic py-3">Belum ada data output atau laporan pemakaian.</td></tr>';
    } else {
      body.innerHTML = rows.map((r) => {
        const diffText  = r.isOk ? '0' : (r.diff > 0 ? '+' : '') + App.fmt(r.diff, 0);
        const diffClr   = r.isOk ? 'text-success' : r.diff > 0 ? 'text-danger' : 'text-primary';
        const pct       = r.targetAdj > 0 ? (r.grand / r.targetAdj) * 100 : 0;
        const barColor  = r.isOk ? '#198754' : r.diff > 0 ? '#dc3545' : '#0d6efd';
        const barWidth  = Math.min(pct, 100);
        const badgeMap = {
          no_output:  ['text-bg-secondary',         'No Output'],
          no_laporan: ['text-bg-warning text-dark',  'No Laporan'],
          pas:        ['text-bg-success',            '✔ PERFECT'],
          boros:      ['text-bg-danger',              '↑ BOROS'],
          hemat:      ['text-bg-primary',              '↓ HEMAT'],
        };
        const [badge, badgeText] = badgeMap[r.status];
        return `<tr>
          <td class="fw-bold ps-3">
            ${App.esc(r.name)}
            ${r.linked > 0 ? `<div class="text-muted mt-1" style="font-size:0.7rem"><i class="fa-solid fa-link me-1"></i>${r.linked} Linked</div>` : ''}
          </td>
          <td class="text-center">
            ${r.hasTheo
              ? `<div class="fw-bold">${App.fmt(r.targetAdj, 0)}</div>
                 <div class="text-muted" style="font-size:0.7rem">(Std:${App.fmt(r.stdTarget,0)} + Rj:${App.fmt(r.reject,0)})</div>`
              : '<span class="text-muted">-</span>'}
          </td>
          <td class="text-center">
            ${r.hasAct
              ? `<div class="fw-bold">${App.fmt(r.grand, 0)}</div>
                 <div class="text-muted" style="font-size:0.7rem">${r.targetAdj > 0 ? App.fmt(pct) + '%' : ''}</div>
                 <div style="height:4px;background:#e9ecef;border-radius:2px;margin-top:3px;overflow:hidden">
                   <div style="height:100%;width:${barWidth}%;background:${barColor};border-radius:2px;transition:width 0.3s"></div>
                 </div>`
              : '<span class="text-muted">-</span>'}
          </td>
          <td class="text-center fw-bold ${r.hasAct && r.hasTheo ? diffClr : ''}">
            ${r.hasAct && r.hasTheo ? diffText : '<span class="text-muted">-</span>'}
          </td>
          <td class="text-center"><span class="badge ${badge}">${badgeText}</span></td>
        </tr>`;
      }).join("");
    }
    const totalKg = state.outputs.reduce((sum, item) => sum + Number(item.totalKg || 0), 0);
    const totalLoss = state.outputs.reduce((sum, item) => sum + Number(item.lossKg || 0), 0);
    document.getElementById("grandTotalKg").textContent = App.fmt(totalKg);
    document.getElementById("grandTotalLoss").textContent = App.fmt(totalLoss);
    document.getElementById("grandTotalLossPct").textContent = totalKg > 0 ? `${App.fmt((totalLoss / totalKg) * 100)}%` : "0.00%";
    const downMin = Number(document.getElementById("rptDowntimeMin")?.value || 0);
    document.getElementById("rptDowntimePct").value = App.fmt((downMin / 480) * 100);
  }

  function collectOutputs() {
    state.outputs = Array.from(document.querySelectorAll("[data-output-row]")).map((row) => ({
      mid: row.querySelector(".output-mid")?.value || "",
      qtyBox: parseIntField(row.querySelector(".output-qty")?.value),
      counterPcs: parseIntField(row.querySelector(".output-counter")?.value),
      totalKg: Number(row.querySelector(".output-kg")?.value || 0),
      lossKg: Number(row.querySelector(".output-loss")?.value || 0),
    })).filter((item) => item.mid);
    refreshAnalysis();
  }

  function buildSubmitPayload() {
    const materials = getActiveMaterials().map((row) => ({
      name: row.material,
      supplier: row.supplier,
      stockAwal: row.stockAwal,
      masuk: row.masuk,
      retur: row.retur,
      reject: row.reject,
      hours: row.hours,
      photos: row.photos,
    }));
    const outputs = state.outputs.map((output) => {
      const product = state.conversions.find((item) => item.mid === output.mid) || {};
      return {
        mid: output.mid,
        name: product.name || output.mid,
        catBag: product.catBag || "",
        catBox: product.catBox || "",
        qtyBox: output.qtyBox,
        counterPcs: output.counterPcs,
        totalKg: output.totalKg,
        lossKg: output.lossKg,
      };
    });
    const report = {
      counterKg: Number(document.getElementById("grandTotalKg")?.textContent?.replace(/\./g, "").replace(",", ".") || 0),
      lossKg: Number(document.getElementById("grandTotalLoss")?.textContent?.replace(/\./g, "").replace(",", ".") || 0),
      // C4: use parseLocalizedFloat so "2,50%" -> 2.5, not a raw string that PHP truncates at the comma
      lossPct: parseLocalizedFloat(document.getElementById("grandTotalLossPct")?.textContent),
      downtimeMin: Number(document.getElementById("rptDowntimeMin")?.value || 0),
      downtimePct: parseLocalizedFloat(document.getElementById("rptDowntimePct")?.value),
      speed: Number(document.getElementById("rptSpeed")?.value || 0),
      trouble: document.getElementById("rptTrouble")?.value || "",
      nearMiss: document.getElementById("rptNearMiss")?.value || "",
      notes: document.getElementById("rptNotes")?.value || "",
      rejectPrintingKg: Number(document.getElementById("rptRejectPrinting")?.value || 0),
    };
    return {
      uuid: state.editUuid,
      tanggal: document.getElementById("inputTanggal")?.value || "",
      shift: document.getElementById("inputShift")?.value || "1",
      mesin: document.getElementById("inputMesin")?.value || "",
      size: document.getElementById("inputSize")?.value || "",
      materialsJson: JSON.stringify(materials),
      outputsJson: JSON.stringify(outputs),
      reportJson: JSON.stringify(report),
    };
  }

  function populateFormFromHistory(entry) {
    state.editUuid = entry.id;
    state.editRevision = entry.revision || 0;
    document.getElementById("inputTanggal").value = entry.rawDate || "";
    document.getElementById("inputShift").value = entry.shift || "1";
    document.getElementById("inputMesin").value = entry.mesin || "";
    document.getElementById("inputSize").value = entry.size || "";
    state.formRows = {};
    state.materialPhotos = {};
    const start = App.getShiftStartIndex(String(entry.shift || "1"));
    (entry.items || []).forEach((item) => {
      if (item.photos && item.photos.length) state.materialPhotos[item.material] = [...item.photos];
      state.formRows[item.material] = {
        material: item.material,
        supplier: item.supplier || "",
        stockAwal: item.stock || 0,
        masuk: item.in || 0,
        retur: item.retur || 0,
        reject: item.reject || 0,
        hours: (item.hours || []).slice(start, start + 8),
        photos: item.photos || [],
      };
    });
    state.outputs = (entry.outputs || []).map((item) => ({
      mid: item.mid,
      qtyBox: item.qty || 0,
      counterPcs: item.counter || 0,
      totalKg: item.kg || 0,
      lossKg: item.loss || 0,
    }));
    const logs = entry.logs || {};
    document.getElementById("rptDowntimeMin").value = logs.downtimeMin || 0;
    document.getElementById("rptDowntimePct").value = logs.downtimePct || 0;
    document.getElementById("rptSpeed").value = logs.speed || 0;
    document.getElementById("rptTrouble").value = logs.trouble || "";
    document.getElementById("rptNearMiss").value = logs.nearMiss || "";
    document.getElementById("rptNotes").value = logs.notes || "";
    document.getElementById("rptRejectPrinting").value = logs.rejectPrintingKg || 0;
    document.getElementById("editModeInfo").textContent = `Mengedit ${entry.id}`;
    document.getElementById("editModeBanner").classList.remove("d-none");
    renderMaterialTable();
    renderOutputs();
    refreshAnalysis();
    window.switchView("input");
    document.getElementById("viewInput")?.scrollIntoView({ behavior: "smooth", block: "start" });
    App.toast("success", "Mode revisi aktif", `${entry.id} siap diedit.`);
  }

  function renderHistoryCards(targetId, rows, isAdmin) {
    const target = document.getElementById(targetId);
    if (!target) return;
    if (!rows.length) {
      target.innerHTML = '<div class="col-12 text-center py-5 animate__animated animate__fadeIn"><div class="opacity-25 mb-3"><i class="fa-regular fa-folder-open fa-3x"></i></div><h6 class="text-muted fw-bold">Tidak ada data laporan.</h6></div>';
      return;
    }
    
    let html = '';
    rows.forEach((rpt) => {
        let groupTheme = "theme-group-x"; 
        let groupLabel = "UNKNOWN";

        if (rpt.owner) {
            const ownerUpper = rpt.owner.toUpperCase();
            if (ownerUpper.includes("GROUP A")) { groupTheme = "theme-group-a"; groupLabel = "GROUP A"; }
            else if (ownerUpper.includes("GROUP B")) { groupTheme = "theme-group-b"; groupLabel = "GROUP B"; }
            else if (ownerUpper.includes("GROUP C")) { groupTheme = "theme-group-c"; groupLabel = "GROUP C"; }
            else if (ownerUpper.includes("GROUP D")) { groupTheme = "theme-group-d"; groupLabel = "GROUP D"; }
            else { groupLabel = ownerUpper.split(' ').pop(); }
        }

        let totalBox = 0;
        if(rpt.outputs) rpt.outputs.forEach(o => totalBox += parseFloat(o.qty)||0);

        const isFinal = rpt.status === 'FINAL';
        const statusBadge = isFinal 
            ? `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-1">FINAL</span>` 
            : `<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-1">DRAFT</span>`;
        
        const revBadge = (rpt.revision && rpt.revision > 0) ? `<span class="badge bg-warning text-dark ms-1 rounded-1" style="font-size:0.6rem">v${rpt.revision}</span>` : '';

        const lossVal = parseFloat((rpt.logs && rpt.logs.lossPct) || 0);
        const lossColor = lossVal > 2.0 ? 'text-danger' : 'text-success';
        
        const rptReport = rpt.logs || {};
        const counterKg = rptReport.counterKg || 0;
        const lossPct = rptReport.lossPct != null && rptReport.lossPct !== '' ? `${App.fmt(rptReport.lossPct)}%` : '0%';
        const speed = rptReport.speed || '-';
        const downtimeMin = rptReport.downtimeMin || '0';

        const diffBtn = (rpt.revision > 0)
            ? `<button class="btn btn-sm text-info btn-link p-0 me-3" data-action="diff" data-id="${App.esc(rpt.id)}" title="Lihat Perbedaan"><i class="fa-solid fa-clock-rotate-left fs-5"></i></button>`
            : '';

        let mainAction = '';
        if (isAdmin) {
            const editOrDetail = isFinal
                ? `<button class="btn btn-sm btn-light border fw-bold text-muted px-3 rounded-pill" style="font-size:0.75rem" data-action="detail" data-id="${App.esc(rpt.id)}">Detail</button>`
                : `<button class="btn btn-sm btn-warning text-dark fw-bold px-3 rounded-pill" style="font-size:0.75rem" data-action="edit" data-id="${App.esc(rpt.id)}">Revisi</button>`;
            mainAction = `
                <button class="btn btn-sm btn-outline-danger border-0 me-1" data-action="delete" data-id="${App.esc(rpt.id)}" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                ${editOrDetail}
            `;
        } else {
            if (!isFinal) {
                mainAction = `
                    <button class="btn btn-sm btn-success fw-bold px-3 rounded-pill me-1 shadow-sm" style="font-size:0.75rem" data-action="finalize" data-id="${App.esc(rpt.id)}"><i class="fa-solid fa-check me-1"></i>Final</button>
                    <button class="btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill" style="font-size:0.75rem" data-action="edit" data-id="${App.esc(rpt.id)}">Edit</button>
                `;
            } else {
                mainAction = `
                    <button class="btn btn-sm btn-light border fw-bold text-muted px-3 rounded-pill" style="font-size:0.75rem" data-action="detail" data-id="${App.esc(rpt.id)}">Detail</button>
                `;
            }
        }

        html += `
        <div class="col-md-6 col-lg-4 animate__animated animate__fadeIn mb-4">
            <div class="power-card ${groupTheme}">
                <div class="group-strip"></div>
                <div class="pc-header">
                    <div>
                        <div class="group-badge-soft mb-1">${App.esc(groupLabel)}</div>
                        <div class="fw-bold text-dark" style="font-size: 0.95rem;">${App.esc(rpt.date)}</div>
                        <div class="text-muted small">Shift ${App.esc(rpt.shift)} &bull; ${App.esc(rpt.mesin)} ${revBadge}</div>
                    </div>
                    <div class="text-end">${statusBadge}</div>
                </div>
                <div class="pc-body">
                    <div class="pc-hero-metric">
                        <span class="pc-hero-val">${totalBox.toLocaleString('id-ID')}</span>
                        <span class="pc-hero-label">BOX OUTPUT</span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-label">Total Berat</span>
                        <span class="pc-value">${App.fmt(counterKg)} Kg</span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-label">Loss Ratio</span>
                        <span class="pc-value ${lossColor}">${App.esc(lossPct)}</span>
                    </div>
                    <div class="pc-info-row">
                        <span class="pc-label">Speed / Down</span>
                        <span class="pc-value">${App.esc(speed)} / ${App.esc(downtimeMin)}'</span>
                    </div>
                </div>
                <div class="pc-footer">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-circle-user me-2 fs-5 user-icon-colored"></i> 
                        <span class="small fw-bold text-secondary" style="font-size:0.75rem">${App.esc(rpt.owner ? rpt.owner.split(' ').pop() : 'User')}</span>
                    </div>
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm text-primary btn-link p-0 me-3" data-action="gallery" data-id="${App.esc(rpt.id)}" title="Lihat Foto"><i class="fa-solid fa-camera fs-5"></i></button>
                        ${diffBtn}
                        <button class="btn btn-sm text-success btn-link p-0 me-3" data-action="whatsapp" data-id="${App.esc(rpt.id)}" title="Copy Info WA"><i class="fa-brands fa-whatsapp fs-5"></i></button>
                        ${mainAction}
                    </div>
                </div>
            </div>
        </div>`;
    });
    
    target.innerHTML = `<div class="row">${html}</div>`;

    target.querySelectorAll("[data-action]").forEach((button) => {
      button.addEventListener("click", async function () {
        const id = this.getAttribute("data-id");
        const action = this.getAttribute("data-action");
        const item = rows.find((row) => row.id === id);
        if (!item) return;
        try {
          if (action === "edit") {
            populateFormFromHistory(item);
            if (typeof window.switchView === "function") window.switchView("input");
            return;
          }
          if (action === "detail") {
            const shift = String(item.shift);
            const timeHeaders = App.currentShiftHours(shift).map(h => h + ":00");
            
            let matHtml = `<div class="table-responsive mb-3"><h6 class="small fw-bold text-secondary border-bottom pb-1">1. Detail Material</h6><table class="table table-bordered table-sm small">
                <thead class="table-light"><tr><th style="min-width:150px">Material</th><th>Awal</th><th>In</th>`;
            timeHeaders.forEach(t => matHtml += `<th class="text-center text-muted" style="font-size:0.7rem">${t}</th>`);
            matHtml += `<th>Retur</th><th class="text-danger">Reject</th><th class="text-primary">Tot. Prod</th><th class="text-primary fw-bold">Grand Total</th><th>Sisa</th></tr></thead><tbody>`;
            
            (item.items || []).forEach(mat => {
                const mapIdx = { "1": 0, "2": 8, "3": 16 };
                const startIdx = mapIdx[shift] || 0;
                const displayHours = (mat.hours && mat.hours.length >= 24) ? mat.hours.slice(startIdx, startIdx + 8) : Array(8).fill(0);
                
                let hoursCells = "";
                displayHours.forEach(val => {
                    const style = val > 0 ? "fw-bold text-dark" : "text-light-gray";
                    hoursCells += `<td class="text-center ${style}" style="font-size:0.8rem">${val > 0 ? val : '-'}</td>`;
                });

                const matTotProd = mat.prod ?? (mat.total - (mat.reject || 0));
                matHtml += `<tr>
                    <td class="fw-bold text-secondary small text-start text-truncate" style="max-width: 150px;" title="${App.esc(mat.material)}">${App.esc(mat.material)}</td>
                    <td class="text-center small">${mat.stock}</td>
                    <td class="text-center small">${mat.in}</td>
                    ${hoursCells}
                    <td class="text-center small">${mat.retur}</td>
                    <td class="text-center small text-danger fw-bold">${mat.reject || 0}</td>
                    <td class="text-center fw-bold text-primary">${matTotProd}</td>
                    <td class="text-center fw-bold text-primary bg-primary bg-opacity-10">${mat.total}</td>
                    <td class="text-center small fw-bold ${mat.sisa < 0 ? 'text-danger' : 'text-success'}">${mat.sisa}</td>
                </tr>`;
            });
            matHtml += `</tbody></table></div>`;

            let outHtml = `<div class="table-responsive mb-3"><h6 class="small fw-bold text-success border-bottom pb-1">2. Output Produksi</h6><table class="table table-bordered table-sm small">
                <thead class="table-light"><tr><th>Produk</th><th class="text-end">Box</th><th class="text-end">Pcs</th><th class="text-end">Berat (Kg)</th><th class="text-end">Loss (Kg)</th></tr></thead><tbody>`;
            (item.outputs || []).forEach(o => {
                outHtml += `<tr>
                    <td>${App.esc(o.name)}</td>
                    <td class="text-end fw-bold">${o.qty}</td>
                    <td class="text-end">${o.counter}</td>
                    <td class="text-end">${o.kg}</td>
                    <td class="text-end text-danger">${o.loss}</td>
                </tr>`;
            });
            outHtml += `</tbody></table></div>`;

            const analysisBadgeMap = {
                no_output:  ['text-bg-secondary',        'No Output'],
                no_laporan: ['text-bg-warning text-dark', 'No Laporan'],
                pas:        ['text-bg-success',           '✔ PERFECT'],
                boros:      ['text-bg-danger',            '↑ BOROS'],
                hemat:      ['text-bg-primary',           '↓ HEMAT'],
            };
            const analysisRows = computeMaterialAnalysis(item.outputs, item.items);
            let outAnalHtml = '';
            if (analysisRows.length) {
                outAnalHtml = `<div class="table-responsive mb-3"><h6 class="small fw-bold text-primary border-bottom pb-1">3. Analisis Output</h6><table class="table table-bordered table-sm small">
                    <thead class="table-light"><tr><th>Kategori</th><th class="text-end">Target</th><th class="text-end">Aktual</th><th class="text-end">Selisih</th><th class="text-center">Status</th></tr></thead><tbody>`;
                analysisRows.forEach(r => {
                    const [badge, badgeText] = analysisBadgeMap[r.status];
                    const diffText = r.hasAct && r.hasTheo ? (r.isOk ? '0' : (r.diff > 0 ? '+' : '') + App.fmt(r.diff, 0)) : '-';
                    const diffClr  = r.isOk ? 'text-success' : r.diff > 0 ? 'text-danger' : 'text-primary';
                    outAnalHtml += `<tr>
                        <td class="fw-bold small">${App.esc(r.name)}</td>
                        <td class="text-end small">${r.hasTheo ? App.fmt(r.targetAdj, 0) : '-'}</td>
                        <td class="text-end small fw-bold">${r.hasAct ? App.fmt(r.grand, 0) : '-'}</td>
                        <td class="text-end small fw-bold ${r.hasAct && r.hasTheo ? diffClr : ''}">${diffText}</td>
                        <td class="text-center"><span class="badge ${badge}">${badgeText}</span></td>
                    </tr>`;
                });
                outAnalHtml += `</tbody></table></div>`;
            }

            const rep = item.logs || {};
            let analHtml = `<h6 class="small fw-bold text-danger border-bottom pb-1">4. Analisis & Catatan</h6>
            <div class="row g-2 small bg-light p-2 rounded border text-start">
                <div class="col-6">Loss Ratio: <b>${rep.lossPct != null ? App.fmt(rep.lossPct) + '%' : '0%'}</b></div>
                <div class="col-6">Downtime: <b>${App.esc(rep.downtimeMin)} Min</b></div>
                <div class="col-6">Speed: <b>${App.esc(rep.speed)} ppm</b></div>
                <div class="col-6">Reject Print: <b>${App.esc(rep.rejectPrintingKg)} Kg</b></div>
                <div class="col-12 mt-2"><div class="fw-bold text-muted border-bottom mb-1">Trouble / Kendala:</div>${App.esc(rep.trouble || '-')}</div>
                <div class="col-12 mt-2"><div class="fw-bold text-muted border-bottom mb-1">Notes:</div>${App.esc(rep.notes || '-')}</div>
            </div>`;

            Swal.fire({
                title: `Detail Produksi: ${item.date}`,
                html: `<div class="text-start" style="max-height: 75vh; overflow-y: auto; overflow-x: hidden;">
                        <div class="mb-3 d-flex flex-wrap gap-2 text-muted small">
                            <span class="badge bg-secondary">Shift ${item.shift}</span>
                            <span class="badge bg-dark">${App.esc(item.mesin)}</span>
                            <span><i class="fa-solid fa-user me-1"></i>${App.esc(item.owner)}</span>
                        </div>
                        ${matHtml}
                        ${outHtml}
                        ${outAnalHtml}
                        ${analHtml}
                       </div>`,
                width: 'min(900px, 96vw)',
                showConfirmButton: true,
                confirmButtonText: 'Tutup',
                showCloseButton: true
            });
            return;
          }
          if (action === "gallery") {
            const photoItems = [];
            (item.items || []).forEach(mat => {
              if (mat.photos && mat.photos.length > 0) {
                mat.photos.forEach(photoId => {
                  if (!photoId.trim()) return;
                  photoItems.push({ photoId: photoId.trim(), material: mat.material });
                });
              }
            });

            if (!photoItems.length) {
              App.toast("info", "Tidak ada foto untuk record ini.");
              return;
            }

            const objectUrls = [];
            const parts = await Promise.all(photoItems.map(async ({ photoId, material }) => {
              try {
                const resp = await fetch('api/photos.php?file=' + encodeURIComponent(photoId), {
                  headers: { 'Authorization': 'Bearer ' + (App.state.token || '') }
                });
                if (!resp.ok) return '';
                const blob = await resp.blob();
                const objUrl = URL.createObjectURL(blob);
                objectUrls.push(objUrl);
                return `<a href="${objUrl}" target="_blank" class="text-decoration-none" title="Klik untuk perbesar" style="width: 140px; display: block; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: relative;">
                  <img src="${objUrl}" alt="Foto" style="width: 100%; height: 140px; object-fit: cover; display: block;">
                  <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: white; font-size: 0.65rem; padding: 4px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${App.esc(material)}</div>
                </a>`;
              } catch (e) { return ''; }
            }));

            const galleryHTML = '<div class="d-flex flex-wrap gap-2 justify-content-center mt-2">' + parts.join('') + '</div>';
            await Swal.fire({
              title: `Galeri Foto - Shift ${item.shift}`,
              html: galleryHTML,
              width: '600px',
              showCloseButton: true,
              showConfirmButton: false
            });
            objectUrls.forEach(u => URL.revokeObjectURL(u));
            return;
          }
          if (action === "whatsapp") {
            const l = item.logs || {};

            // --- WA text ---
            const totalOutKg  = (item.outputs || []).reduce((s, o) => s + Number(o.kg   || 0), 0);
            const totalLossKg = (item.outputs || []).reduce((s, o) => s + Number(o.loss || 0), 0);
            const totalLossPct = totalOutKg > 0 ? (totalLossKg / totalOutKg) * 100 : 0;
            const prodDetail = (item.outputs || []).map(o => {
              const pct = Number(o.kg) > 0 ? (Number(o.loss) / Number(o.kg)) * 100 : 0;
              return `> *${o.name}*\n   Output : *${o.qty} Box*\n   Prod   : ${App.fmt(o.counter, 0)} pcs (${App.fmt(o.kg)} Kg) | Loss: ${App.fmt(o.loss)} Kg (${App.fmt(pct)}%)`;
            }).join('\n\n');
            const rejectLines = (item.items || [])
              .filter(m => Number(m.reject || 0) > 0)
              .map(m => `  ${m.material} : ${m.reject}`)
              .join('\n');
            const fmtBullets = (text) => {
              if (!text || text === '-') return '-';
              return text.trim().split('\n').map(ln => { const t = ln.trim(); return t ? (t.startsWith('-') ? t : `- ${t}`) : ''; }).join('\n').replace(/\n{3,}/g, '\n\n').trim();
            };
            const waText = [
              '*Info Hasil Produksi*',
              '',
              `~Mesin ${item.mesin} Shift ${item.shift} (${item.date})`,
              `~User: ${item.owner || '-'}`,
              '',
              '*DETAIL PRODUK:*',
              prodDetail || '(tidak ada output)',
              '',
              '------------------',
              `~Total Output : ${App.fmt(totalOutKg)} Kg`,
              `~Total Loss : ${App.fmt(totalLossKg)} Kg (${App.fmt(totalLossPct)}%)`,
              `~Reject Printing : ${l.rejectPrintingKg || 0} Kg`,
              '',
              '~Reject Material :',
              rejectLines || '  (tidak ada)',
              '',
              '',
              `~Speed : ${l.speed || '-'} ppm`,
              `~Downtime : ${l.downtimeMin || 0} Min (${App.fmt(l.downtimePct || 0)}%)`,
              '',
              '~Trouble :',
              fmtBullets(l.trouble),
              '',
              `~Near Miss : ${l.nearMiss && l.nearMiss !== '-' ? l.nearMiss : '-'}`,
              '~Note : ',
              fmtBullets(l.notes),
            ].join('\n');

            // --- Screenshot HTML ---
            const thC = 'padding:6px;font-size:11px;text-align:center;border:1px solid #dee2e6;text-transform:uppercase;color:#6c757d;background:#f8f9fa;font-weight:600';
            const thL = 'padding:6px 8px;font-size:11px;text-align:left;border:1px solid #dee2e6;text-transform:uppercase;color:#6c757d;background:#f8f9fa;font-weight:600';
            const tdC = 'text-align:center;padding:5px 6px;font-size:12px;border:1px solid #dee2e6';
            const tdL = 'padding:5px 8px;font-size:12px;border:1px solid #dee2e6;font-weight:600';
            const matRows = (item.items || []).map(mat => {
              const tp = mat.prod ?? (mat.total - (mat.reject || 0));
              const sc = mat.sisa < 0 ? '#dc3545' : '#198754';
              return `<tr>
                <td style="${tdL}">${App.esc(mat.material)}</td>
                <td style="${tdC}">${mat.stock}</td>
                <td style="${tdC}">${mat.in}</td>
                <td style="${tdC};color:#dc3545;font-weight:600">${mat.reject || 0}</td>
                <td style="${tdC}">${mat.retur}</td>
                <td style="${tdC};color:#0d6efd;font-weight:600">${tp}</td>
                <td style="${tdC};background:#e7f1ff;color:#0d6efd;font-weight:700">${mat.total}</td>
                <td style="${tdC};font-weight:600;color:${sc}">${mat.sisa}</td>
              </tr>`;
            }).join('');
            const outRows = (item.outputs || []).map(o =>
              `<tr>
                <td style="${tdL}">${App.esc(o.name)}</td>
                <td style="${tdC};color:#198754;font-weight:700;font-size:13px">${o.qty}</td>
                <td style="${tdC}">${App.fmt(o.counter, 0)}</td>
                <td style="${tdC}">${App.fmt(o.kg)}</td>
                <td style="${tdC};color:#dc3545">${App.fmt(o.loss)}</td>
              </tr>`
            ).join('');
            const analysisStatusStyle = {
              boros:      { bg: '#f8d7da', fg: '#842029', text: 'BOROS' },
              hemat:      { bg: '#cfe2ff', fg: '#084298', text: 'HEMAT' },
              pas:        { bg: '#d1e7dd', fg: '#0f5132', text: 'PAS' },
              no_output:  { bg: '#e9ecef', fg: '#495057', text: 'No Output' },
              no_laporan: { bg: '#fff3cd', fg: '#664d03', text: 'No Laporan' },
            };
            const analysisRows = computeMaterialAnalysis(item.outputs, item.items).map(r => {
              const st = analysisStatusStyle[r.status];
              const diffText  = r.hasAct && r.hasTheo ? (r.isOk ? '0' : (r.diff > 0 ? '+' : '') + App.fmt(r.diff, 0)) : '-';
              const diffColor = r.isOk ? '#198754' : r.diff > 0 ? '#dc3545' : '#0d6efd';
              return `<tr>
                <td style="${tdL}">${App.esc(r.name)}</td>
                <td style="${tdC}">${r.hasTheo ? App.fmt(r.targetAdj, 0) : '-'}</td>
                <td style="${tdC};color:#0d6efd;font-weight:700">${r.hasAct ? App.fmt(r.grand, 0) : '-'}</td>
                <td style="${tdC};font-weight:700;color:${r.hasAct && r.hasTheo ? diffColor : '#6c757d'}">${diffText}</td>
                <td style="text-align:center;padding:5px 6px;border:1px solid #dee2e6">
                  <span style="display:inline-block;padding:2px 10px;border-radius:10px;font-size:10px;font-weight:700;background:${st.bg};color:${st.fg}">${st.text}</span>
                </td>
              </tr>`;
            }).join('');
            const analysisBlock = analysisRows ? `
              <div style="font-size:12px;font-weight:700;color:#495057;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px">Analisis Output</div>
              <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                <thead><tr>
                  <th style="${thL}">Kategori</th>
                  <th style="${thC}">Target</th>
                  <th style="${thC}">Aktual</th>
                  <th style="${thC}">Selisih</th>
                  <th style="${thC}">Status</th>
                </tr></thead>
                <tbody>${analysisRows}</tbody>
              </table>` : '';
            const lossNum = parseFloat(l.lossPct) || 0;
            const lossColor = lossNum > 2 ? '#dc3545' : '#198754';
            const troubleBlock = (l.trouble && l.trouble !== '-')
              ? `<div style="margin-top:10px;padding:8px 12px;background:#fff3cd;border-radius:6px;border-left:3px solid #ffc107;"><div style="font-size:10px;font-weight:700;color:#856404;text-transform:uppercase;margin-bottom:3px">Trouble / Kendala</div><div style="font-size:12px;color:#212529;white-space:pre-wrap">${App.esc(l.trouble)}</div></div>` : '';
            const notesBlock = (l.notes && l.notes !== '-')
              ? `<div style="margin-top:8px;padding:8px 12px;background:#e7f5ff;border-radius:6px;border-left:3px solid #0d6efd;"><div style="font-size:10px;font-weight:700;color:#0a4e96;text-transform:uppercase;margin-bottom:3px">Catatan</div><div style="font-size:12px;color:#212529;white-space:pre-wrap">${App.esc(l.notes)}</div></div>` : '';
            const el = document.createElement('div');
            el.style.cssText = 'position:absolute;top:-9999px;left:0;width:740px;background:#ffffff;padding:22px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;';
            el.innerHTML = `
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #0d6efd;">
                <div>
                  <div style="font-size:18px;font-weight:800;color:#0d6efd;letter-spacing:0.5px;line-height:1">LAPORAN PRODUKSI</div>
                  <div style="font-size:13px;color:#6c757d;margin-top:4px">Shift ${App.esc(item.shift)} &bull; ${App.esc(item.mesin)} &bull; ${App.esc(item.owner || '-')}</div>
                </div>
                <div style="text-align:right">
                  <div style="font-size:20px;font-weight:800;color:#212529;line-height:1">${App.esc(item.date)}</div>
                  <div style="font-size:11px;color:#adb5bd;text-transform:uppercase;letter-spacing:0.5px;margin-top:3px">${App.esc(item.status)}</div>
                </div>
              </div>
              <div style="font-size:12px;font-weight:700;color:#495057;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px">Pemakaian Material</div>
              <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                <thead><tr>
                  <th style="${thL}">Material</th>
                  <th style="${thC}">Awal</th><th style="${thC}">Masuk</th>
                  <th style="${thC};color:#dc3545">Reject</th><th style="${thC}">Retur</th>
                  <th style="${thC};color:#0d6efd">Tot.Prod</th>
                  <th style="${thC};background:#e7f1ff;color:#0d6efd">G.Total</th>
                  <th style="${thC}">Sisa</th>
                </tr></thead>
                <tbody>${matRows || `<tr><td colspan="8" style="text-align:center;padding:8px;color:#6c757d;font-size:12px;border:1px solid #dee2e6">Tidak ada data material</td></tr>`}</tbody>
              </table>
              <div style="font-size:12px;font-weight:700;color:#495057;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px">Output Produksi</div>
              <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                <thead><tr>
                  <th style="${thL}">Produk</th>
                  <th style="${thC};color:#198754">Box</th>
                  <th style="${thC}">Counter PCS</th>
                  <th style="${thC}">Berat (Kg)</th>
                  <th style="${thC};color:#dc3545">Loss (Kg)</th>
                </tr></thead>
                <tbody>${outRows || `<tr><td colspan="5" style="text-align:center;padding:8px;color:#6c757d;font-size:12px;border:1px solid #dee2e6">Belum ada output</td></tr>`}</tbody>
              </table>
              ${analysisBlock}
              <div style="background:#f8f9fa;border-radius:8px;padding:12px;display:flex;gap:0;">
                <div style="flex:1;text-align:center;border-right:1px solid #dee2e6;padding:0 8px">
                  <div style="font-size:10px;color:#6c757d;text-transform:uppercase;margin-bottom:2px">Loss Ratio</div>
                  <div style="font-size:17px;font-weight:800;color:${lossColor}">${l.lossPct != null && l.lossPct !== '' ? App.fmt(l.lossPct) + '%' : '0%'}</div>
                </div>
                <div style="flex:1;text-align:center;border-right:1px solid #dee2e6;padding:0 8px">
                  <div style="font-size:10px;color:#6c757d;text-transform:uppercase;margin-bottom:2px">Speed</div>
                  <div style="font-size:17px;font-weight:800;color:#212529">${l.speed || '-'} <span style="font-size:11px;font-weight:400">ppm</span></div>
                </div>
                <div style="flex:1;text-align:center;border-right:1px solid #dee2e6;padding:0 8px">
                  <div style="font-size:10px;color:#6c757d;text-transform:uppercase;margin-bottom:2px">Downtime</div>
                  <div style="font-size:17px;font-weight:800;color:#212529">${l.downtimeMin || 0} <span style="font-size:11px;font-weight:400">min</span></div>
                </div>
                <div style="flex:1;text-align:center;padding:0 8px">
                  <div style="font-size:10px;color:#6c757d;text-transform:uppercase;margin-bottom:2px">Reject Print</div>
                  <div style="font-size:17px;font-weight:800;color:#212529">${l.rejectPrintingKg || 0} <span style="font-size:11px;font-weight:400">Kg</span></div>
                </div>
              </div>
              ${troubleBlock}${notesBlock}
              <div style="margin-top:12px;padding-top:8px;border-top:1px solid #f0f0f0;font-size:10px;color:#ced4da;display:flex;justify-content:space-between;align-items:center">
                <span style="color:#0d6efd;font-weight:700;font-size:11px">ProdAdmin</span>
                <span>${new Date().toLocaleString('id-ID', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}</span>
              </div>`;
            document.body.appendChild(el);
            try {
              const canvas = await html2canvas(el, { scale: 2, useCORS: true, backgroundColor: '#ffffff', logging: false });
              document.body.removeChild(el);
              const imgUrl = canvas.toDataURL('image/png');
              const filename = `laporan-${item.date.replace(/\//g, '-')}-shift${item.shift}-${item.mesin}.png`;

              await Swal.fire({
                title: 'Laporan WhatsApp',
                html: `<div class="text-start">
                  <img src="${imgUrl}" style="max-width:100%;border-radius:8px;border:1px solid #dee2e6;box-shadow:0 2px 8px rgba(0,0,0,0.08);display:block;margin-bottom:12px" alt="Screenshot Laporan">
                  <button id="btnKirimWa" class="btn btn-success w-100 py-2 fw-bold fs-6">
                    <i class="fa-brands fa-whatsapp me-2"></i>Salin Teks &amp; Unduh Gambar
                  </button>
                  <div id="waInstruction" class="d-none alert alert-success mt-2 py-2 small mb-0">
                    <i class="fa-solid fa-circle-check me-1"></i><b>Siap!</b> Buka WhatsApp &rarr; tempel teks (Ctrl+V) &rarr; lampirkan gambar dari Downloads
                  </div>
                  <details class="mt-2">
                    <summary class="small text-muted" style="cursor:pointer;user-select:none">Lihat / salin teks laporan</summary>
                    <textarea readonly style="width:100%;height:150px;font-family:monospace;font-size:11px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:10px;resize:none;line-height:1.45;white-space:pre;overflow:auto;margin-top:8px">${App.esc(waText)}</textarea>
                  </details>
                </div>`,
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Tutup',
                showCloseButton: true,
                width: 'min(680px, 96vw)',
                didOpen: (modal) => {
                  modal.querySelector('#btnKirimWa')?.addEventListener('click', function () {
                    try {
                      if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(waText).catch(() => {});
                      } else {
                        const ta = document.createElement('textarea');
                        ta.value = waText;
                        ta.style.cssText = 'position:fixed;opacity:0';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                      }
                    } catch (_) {}
                    // Download image
                    const a = document.createElement('a');
                    a.href = imgUrl;
                    a.download = filename;
                    a.click();
                    // Update UI
                    this.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>Teks tersalin &amp; Gambar diunduh!';
                    this.classList.replace('btn-success', 'btn-outline-success');
                    this.disabled = true;
                    modal.querySelector('#waInstruction')?.classList.remove('d-none');
                  });
                },
              });
            } catch (err) {
              if (document.body.contains(el)) document.body.removeChild(el);
              App.toast('error', 'Gagal membuat screenshot: ' + (err && err.message ? err.message : String(err)));
            }
            return;
          }
          if (action === "finalize") {
            await App.api("api/transactions.php?action=finalize", { method: "POST", body: JSON.stringify({ uuid: id }) });
            App.toast("success", "Finalized");
            await (isAdmin ? window.loadAdminHistory() : window.loadHistory());
            return;
          }
          if (action === "delete") {
            if (window.Swal) {
              const confirm = await Swal.fire({
                title: "Hapus Laporan?",
                text: "Apakah Anda yakin ingin menghapus data ini?",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                confirmButtonText: "Ya, Hapus",
                cancelButtonText: "Batal"
              });
              if (!confirm.isConfirmed) return;
            } else {
              if (!confirm("Apakah Anda yakin ingin menghapus data ini?")) return;
            }

            await App.api("api/transactions.php?action=delete", { method: "POST", body: JSON.stringify({ uuid: id }) });
            App.toast("success", "Deleted");
            await (isAdmin ? window.loadAdminHistory() : window.loadHistory());
            return;
          }
          if (action === "diff") {
            const res = await App.api(`api/transactions.php?action=diff&uuid=${encodeURIComponent(id)}`, { method: "GET" });
            const versions = res.data;
            if (!versions || typeof versions !== 'object' || Object.keys(versions).length === 0) {
                App.toast('info', 'Data revisi tidak ditemukan.');
                return;
            }
            
            const keys = Object.keys(versions).sort((a,b)=>a-b);
            if(keys.length === 0) return;

            const latestKey = keys[keys.length-1];
            const prevKey = keys.length > 1 ? keys[keys.length-2] : keys[0];
            const latest = versions[latestKey]; 
            const prev = versions[prevKey]; 

            let matHtml = `<div class="table-responsive mb-3"><h6 class="small fw-bold text-secondary border-bottom pb-1">1. Material Usage</h6><table class="table table-bordered table-sm small">
                <thead class="table-light"><tr><th>Material</th><th class="text-end">v${prevKey}</th><th class="text-end">v${latestKey}</th><th class="text-end">Diff</th></tr></thead><tbody>`;
            
            (latest.items || []).forEach(newItem => {
                const oldItem = (prev.items || []).find(x => x.mat === newItem.mat) || { total: 0 };
                const diff = newItem.total - oldItem.total;
                let bgClass = (diff !== 0 && latestKey != prevKey) ? "bg-warning bg-opacity-25 fw-bold" : "";
                matHtml += `<tr class="${bgClass}">
                    <td>${App.esc(newItem.mat)}</td>
                    <td class="text-end text-muted">${oldItem.total}</td>
                    <td class="text-end">${newItem.total}</td>
                    <td class="text-end ${diff>0?'text-danger': diff<0?'text-success':''}">${diff > 0 ? '+'+diff : diff}</td>
                </tr>`;
            });
            matHtml += `</tbody></table></div>`;

            let outHtml = `<div class="table-responsive mb-3"><h6 class="small fw-bold text-success border-bottom pb-1">2. Output Produksi</h6><table class="table table-bordered table-sm small">
                <thead class="table-light"><tr><th>Produk</th><th class="text-end">v${prevKey}</th><th class="text-end">v${latestKey}</th><th class="text-end">Diff</th></tr></thead><tbody>`;
            
            const allProds = new Set([...(latest.outputs || []).map(o=>o.name), ...(prev.outputs || []).map(o=>o.name)]);
            allProds.forEach(prodName => {
                const cur = (latest.outputs || []).find(o=>o.name===prodName) || {qty:0};
                const old = (prev.outputs || []).find(o=>o.name===prodName) || {qty:0};
                const diff = cur.qty - old.qty;
                let bgClass = (diff !== 0 && latestKey != prevKey) ? "bg-warning bg-opacity-25 fw-bold" : "";
                outHtml += `<tr class="${bgClass}">
                    <td>${App.esc(prodName)}</td>
                    <td class="text-end text-muted">${old.qty}</td>
                    <td class="text-end">${cur.qty}</td>
                    <td class="text-end ${diff>0?'text-success': diff<0?'text-danger':''}">${diff > 0 ? '+'+diff : diff}</td>
                </tr>`;
            });
          outHtml += `</tbody></table></div>`;

          const comp = (a, b, suffix='') => {
              const v1 = parseFloat(a)||0; const v2 = parseFloat(b)||0;
              if(v1===v2) return `<span class="text-dark">${v1}${suffix}</span>`;
              return `<span class="text-decoration-line-through text-muted small me-1">${v2}</span> <span class="fw-bold text-danger">${v1}${suffix}</span>`;
          };

          const lRep = latest.report || {};
          const pRep = prev.report || {};

          let analHtml = `<h6 class="small fw-bold text-danger border-bottom pb-1">3. Analisis & Performance</h6>
          <div class="row g-2 small bg-light p-2 rounded border">
              <div class="col-6">Loss: ${comp(lRep.lossKg, pRep.lossKg, ' Kg')}</div>
              <div class="col-6">Loss %: ${comp(lRep.lossPct, pRep.lossPct)}</div>
              <div class="col-6">Downtime: ${comp(lRep.downMin, pRep.downMin, ' Min')}</div>
              <div class="col-6">Speed: ${comp(lRep.speed, pRep.speed, ' ppm')}</div>
              <div class="col-12">Reject Print: ${comp(lRep.rejectPrint, pRep.rejectPrint, ' Kg')}</div>
          </div>`;

          Swal.fire({
              title: `Audit Revisi: v${latestKey} vs v${prevKey}`,
              html: `<div class="text-start" style="max-height: 70vh; overflow-y: auto;">
                      <div class="mb-2 text-muted small"><i class="fa-regular fa-clock me-1"></i> Update: ${App.esc(latest.time)}</div>
                      ${matHtml}
                      ${outHtml}
                      ${analHtml}
                     </div>`,
              width: 'min(700px, 96vw)',
              showConfirmButton: false,
              showCloseButton: true
          });
            return;
          }
        } catch (error) {
          App.toast("error", "Aksi riwayat gagal", error.message || "Terjadi kesalahan.");
        }
      });
    });
  }

  window.renderTable = function renderTable() {
    snapshotRows();
    renderMaterialTable(document.getElementById("inputSearchMaterial")?.value || "");
  };

  window.filterMaterialTable = function filterMaterialTable() {
    snapshotRows();
    renderMaterialTable(document.getElementById("inputSearchMaterial")?.value || "");
  };

  window.addCustomMaterial = async function addCustomMaterial() {
    const result = await Swal.fire({ title: "Material baru", input: "text", inputPlaceholder: "Nama material", showCancelButton: true });
    if (!result.isConfirmed || !result.value) return;
    const name = String(result.value).trim().toUpperCase();
    state.materials.push(name);
    state.formRows[name] = defaultMaterialRow(name);
    renderMaterialTable();
  };

  window.addOutputRow = function addOutputRow() {
    collectOutputs();
    state.outputs.push({ mid: "", qtyBox: 0, counterPcs: 0, totalKg: 0, lossKg: 0 });
    renderOutputs();
  };

  window.calcShiftMetrics = refreshAnalysis;
  window.autoResize = function autoResize(el) { if (el) { el.style.height = "auto"; el.style.height = `${el.scrollHeight}px`; } };
  window.addSmartBullet = function addSmartBullet(id) { const el = document.getElementById(id); if (el) el.value += (el.value.trim() ? "\n- " : "- "); };
  window.handleSmartEnter = function handleSmartEnter(event) {
    if (event.key !== 'Enter') return;
    const el = event.target;
    const start = el.selectionStart;
    const currentLine = el.value.substring(0, start).split('\n').pop();
    if (currentLine.match(/^-\s+\S/)) {
      event.preventDefault();
      const ins = '\n- ';
      el.value = el.value.substring(0, start) + ins + el.value.substring(el.selectionEnd);
      el.selectionStart = el.selectionEnd = start + ins.length;
    }
  };

  window.resetData = function resetData() {
    state.editUuid = "";
    state.editRevision = 0;
    state.formRows = {};
    state.materialPhotos = {};
    state.outputs = [];
    document.getElementById("editModeBanner").classList.add("d-none");
    ["rptDowntimeMin", "rptDowntimePct", "rptSpeed", "rptTrouble", "rptNearMiss", "rptNotes", "rptRejectPrinting"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.value = "";
    });
    renderMaterialTable();
    renderOutputs();
    refreshAnalysis();
  };

  window.cancelEditMode = function cancelEditMode() {
    window.resetData();
    if (state.isAdmin) {
      document.getElementById("adminView")?.classList.remove("d-none");
      document.getElementById("userView")?.classList.add("d-none");
      document.querySelector(".sticky-top-custom")?.classList.add("d-none");
    }
  };

  window.submitData = async function submitData() {
    const btn = document.getElementById("btnSubmit");
    if (btn?.disabled) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Menyimpan...'; }
    collectOutputs();
    const payload = buildSubmitPayload();
    const isRevise = !!state.editUuid;
    try {
      const endpoint = isRevise ? "api/transactions.php?action=revise" : "api/transactions.php?action=submit";
      await App.api(endpoint, { method: "POST", body: JSON.stringify(payload) });
      App.toast("success", isRevise ? "Revisi tersimpan" : "Data tersimpan");
      window.discardDraft();
      window.resetData();
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>SUBMIT LAPORAN'; }
      if (state.isAdmin) {
        document.getElementById("adminView")?.classList.remove("d-none");
        document.getElementById("userView")?.classList.add("d-none");
        document.querySelector(".sticky-top-custom")?.classList.add("d-none");
        await window.loadAdminHistory();
      } else {
        await window.loadHistory();
      }
    } catch (error) {
      App.toast("error", "Gagal menyimpan", error.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>SUBMIT LAPORAN'; }
    }
  };

  window.switchView = function switchView(view) {
    const input = document.getElementById("viewInput");
    const data = document.getElementById("viewData");
    document.getElementById("tabInput")?.classList.toggle("active", view === "input");
    document.getElementById("tabData")?.classList.toggle("active", view === "data");
    if (input) input.classList.toggle("d-none", view !== "input");
    if (data) data.classList.toggle("d-none", view !== "data");
    if (state.isAdmin && view === "input") {
      document.getElementById("adminView")?.classList.add("d-none");
      document.getElementById("userView")?.classList.remove("d-none");
      document.querySelector(".sticky-top-custom")?.classList.add("d-none");
    }
    (view === "input" ? input : data)?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  // M1/L1: pagination state for user history view
  const HISTORY_PAGE_SIZE = 50;
  let historyCurrentPage  = 1;
  let historyTotalPages   = 1;

  function setLoadMoreVisible(visible) {
    const btn = document.getElementById("btnLoadMoreHistory");
    if (btn) btn.classList.toggle("d-none", !visible);
  }

    // strips: NBSP(\u00A0), soft-hyphen(\u00AD), zero-width(\u200B-D),
    //          narrow-NBSP(\u202F), curly-quotes(\u2018-9), BOM(\uFEFF), line/para-sep(\u2028-9)
  window.loadHistory = async function loadHistory() {
    historyCurrentPage = 1;
    const today     = new Date().toISOString().slice(0, 10);
    const monthAgo  = new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const startDate = getDateFilterValue("filterStartDate", monthAgo);
    const endDate   = getDateFilterValue("filterEndDate", today);
    const res = await App.api(
      `api/history.php?action=paged&limit=${HISTORY_PAGE_SIZE}&page=1` +
      `&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}`,
      { method: "GET" }
    );
    historyTotalPages  = res.data.totalPages || 1;
    state.history      = res.data.data || [];
    renderHistoryCards("reportGrid", state.history, false);
    setLoadMoreVisible(historyCurrentPage < historyTotalPages);
  };

  // Append next page to existing results
  window.loadMoreHistory = async function loadMoreHistory() {
    if (historyCurrentPage >= historyTotalPages) return;
    historyCurrentPage++;
    const today     = new Date().toISOString().slice(0, 10);
    const monthAgo  = new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const startDate = getDateFilterValue("filterStartDate", monthAgo);
    const endDate   = getDateFilterValue("filterEndDate", today);
    const res = await App.api(
      `api/history.php?action=paged&limit=${HISTORY_PAGE_SIZE}&page=${historyCurrentPage}` +
      `&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}`,
      { method: "GET" }
    );
    const newRows = res.data.data || [];
    state.history = state.history.concat(newRows);
    // re-render full accumulated list (simpler than partial DOM append given card complexity)
    renderHistoryCards("reportGrid", state.history, false);
    setLoadMoreVisible(historyCurrentPage < historyTotalPages);
  };

  window.loadAdminHistory = async function loadAdminHistory() {
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 29 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    const startDate = getDateFilterValue("filterStartDateAdmin", monthAgo);
    const endDate = getDateFilterValue("filterEndDateAdmin", today);
    const url = startDate && endDate
      ? `api/admin.php?action=stats&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}`
      : "api/admin.php?action=stats";
    const stat = await App.api(url, { method: "GET" });
    document.getElementById("scoreTotal").textContent = App.fmt(stat.data.totalToday || 0);
    document.getElementById("scoreTop").textContent = stat.data.topMaterial || "-";
    document.getElementById("scoreAlert").textContent = String(stat.data.alertCount || 0);
    renderCharts(stat.data.chartData || {});
    const histUrl = `api/history.php?action=admin&limit=100&startDate=${encodeURIComponent(startDate)}&endDate=${encodeURIComponent(endDate)}`;
    const history = await App.api(histUrl, { method: "GET" });
    const rows = history.data.data || [];
    renderHistoryCards("adminReportGrid", rows, true);
  };

  function renderCharts(chartData) {
    if (!window.Chart) return;
    const specs = [
      ["chartMain", chartData.topMats?.labels || [], chartData.topMats?.values || [], "#0d6efd"],
      ["chartMini", chartData.shifts?.labels || [], chartData.shifts?.values || [], "#198754"],
      ["chartReject", chartData.rejects?.labels || [], chartData.rejects?.values || [], "#dc3545"],
    ];
    specs.forEach(([id, labels, values, color]) => {
      const canvas = document.getElementById(id);
      if (!canvas) return;
      if (state.charts[id]) state.charts[id].destroy();
      state.charts[id] = new Chart(canvas, {
        type: "bar",
        data: { labels, datasets: [{ data: values, backgroundColor: color }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } },
      });
    });
  }

  // Guards against out-of-order responses: if the user changes Mesin/Shift/Tanggal
  // again before an in-flight previousStock request resolves, a stale response
  // must not overwrite the result of a newer request (or blank the form to 0).
  let _handoverSeq = 0;
  window.triggerHandover = async function triggerHandover() {
    const seq = ++_handoverSeq;
    try {
      const mesin = document.getElementById("inputMesin")?.value || "";
      const shift = document.getElementById("inputShift")?.value || "1";
      const date = document.getElementById("inputTanggal")?.value || "";
      if (!mesin || !date) return; // incomplete selection, nothing to pull yet
      const res = await App.api(`api/transactions.php?action=previousStock&mesin=${encodeURIComponent(mesin)}&shift=${encodeURIComponent(shift)}&date=${encodeURIComponent(date)}`, { method: "GET" });
      if (seq !== _handoverSeq) return; // a newer trigger superseded this one; discard
      snapshotRows();
      Object.entries(res.data || {}).forEach(([material, stock]) => {
        if (!state.formRows[material]) state.formRows[material] = defaultMaterialRow(material);
        state.formRows[material].stockAwal = Number(stock || 0);
      });
      renderMaterialTable();
      App.toast("success", "Stok shift lalu dimuat");
    } catch (error) {
      if (seq !== _handoverSeq) return;
      App.toast("error", "Gagal tarik stok", error.message);
    }
  };

  window.loadInitialData = async function loadInitialData() {
    const init = await App.api("api/init.php", { method: "GET" });
    state.suppliers = init.data.suppliers || { map: {}, order: [] };
    state.conversions = init.data.conversion || [];
    state.config = init.data.config || {};
    state.materials = [...(state.suppliers.order || [])];
    populateSelect("inputMesin", state.config.mesin || [], state.machine);
    populateSelect("inputSize", state.config.size || [], "");
    state.machine = document.getElementById("inputMesin")?.value || state.machine;
    App.saveSession();
    setDefaults();

    // Pre-load draft state BEFORE renderMaterialTable so values appear immediately
    if (!state.isAdmin) {
      try {
        const raw = localStorage.getItem(draftKey());
        if (raw) {
          const draft = JSON.parse(raw);
          state.formRows    = draft.formRows  || {};
          state.outputs     = draft.outputs   || [];
          state.materialPhotos = {};
          (Object.entries(draft.formRows || {})).forEach(([mat, row]) => {
            if (row.photos && row.photos.length) state.materialPhotos[mat] = [...row.photos];
          });
          if (draft.tanggal && document.getElementById("inputTanggal")) document.getElementById("inputTanggal").value = draft.tanggal;
          if (draft.shift   && document.getElementById("inputShift"))   document.getElementById("inputShift").value   = draft.shift;
          if (draft.mesin   && document.getElementById("inputMesin"))   document.getElementById("inputMesin").value   = draft.mesin;
          if (draft.size    && document.getElementById("inputSize"))     document.getElementById("inputSize").value     = draft.size;
          const rpt = draft.rpt || {};
          if (document.getElementById("rptDowntimeMin"))   document.getElementById("rptDowntimeMin").value   = rpt.downtimeMin   || 0;
          if (document.getElementById("rptSpeed"))         document.getElementById("rptSpeed").value         = rpt.speed         || 0;
          if (document.getElementById("rptTrouble"))       document.getElementById("rptTrouble").value       = rpt.trouble       || "";
          if (document.getElementById("rptNearMiss"))      document.getElementById("rptNearMiss").value      = rpt.nearMiss      || "";
          if (document.getElementById("rptNotes"))         document.getElementById("rptNotes").value         = rpt.notes         || "";
          if (document.getElementById("rptRejectPrinting")) document.getElementById("rptRejectPrinting").value = rpt.rejectPrinting || 0;
          const ageMin  = Math.floor((Date.now() - (draft.savedAt || 0)) / 60000);
          const ageText = ageMin < 60 ? `${ageMin} menit lalu` : `${Math.floor(ageMin / 60)} jam lalu`;
          const banner  = document.getElementById("draftBanner");
          const ageEl   = document.getElementById("draftBannerAge");
          if (banner) { if (ageEl) ageEl.textContent = ageText; banner.classList.remove("d-none"); }
          window._pendingDraft = draft;
          App.toast("success", "Draft dilanjutkan otomatis");
        }
      } catch (e) {}
    }

    renderMaterialTable();
    renderOutputs();
    refreshAnalysis();
    try {
      await window.loadHistory();
    } catch (error) {
      App.toast("error", "Gagal memuat riwayat", error.message);
    }
    if (state.isAdmin) {
      document.getElementById("adminView")?.classList.remove("d-none");
      document.getElementById("userView")?.classList.add("d-none");
      document.querySelector(".sticky-top-custom")?.classList.add("d-none");
      try {
        await window.loadAdminHistory();
      } catch (error) {
        App.toast("error", "Gagal memuat panel admin", error.message);
      }
      await window.loadPhotoMigrationProgress();
    } else {
      document.getElementById("adminView")?.classList.add("d-none");
      document.getElementById("userView")?.classList.remove("d-none");
      document.querySelector(".sticky-top-custom")?.classList.remove("d-none");
    }
    const settings = await App.api("api/settings.php", { method: "GET" });
    state.settings = settings.data;
    if (settings.data.broadcastActive && settings.data.broadcastMsg) {
      document.getElementById("broadcastText").textContent = settings.data.broadcastMsg;
      document.getElementById("broadcastBanner").classList.remove("d-none");
    } else {
      document.getElementById("broadcastBanner").classList.add("d-none");
    }
    
    if (state.settings.enableHandover) {
      await window.triggerHandover();
    }

  };

  document.addEventListener("DOMContentLoaded", function () {
    if (!state.materialPhotos) state.materialPhotos = {};

    const triggerIfHandover = () => {
      if (state.settings && state.settings.enableHandover) {
        window.triggerHandover();
      }
    };

    document.getElementById("inputShift")?.addEventListener("change", () => {
      snapshotRows();
      renderMaterialTable();
      triggerIfHandover();
    });
    document.getElementById("inputMesin")?.addEventListener("change", triggerIfHandover);
    document.getElementById("inputTanggal")?.addEventListener("change", triggerIfHandover);
    const autoCalcOutputKg = (e) => {
      const row = e.target.closest("[data-output-row]");
      if (!row) return;
      if (!e.target.classList.contains("output-mid") && !e.target.classList.contains("output-counter")) return;
      const mid = row.querySelector(".output-mid")?.value || "";
      const product = state.conversions.find((p) => p.mid === mid);
      if (!product?.weight) return;
      const counter = Number(row.querySelector(".output-counter")?.value || 0);
      const kgInput = row.querySelector(".output-kg");
      if (kgInput) kgInput.value = ((counter * product.weight) / 1000).toFixed(2);
    };
    document.getElementById("outputRowsContainer")?.addEventListener("input", (e) => {
      autoCalcOutputKg(e);
      collectOutputs();
      autoSaveDraft();
    });
    document.getElementById("outputRowsContainer")?.addEventListener("change", (e) => {
      autoCalcOutputKg(e);
      if (e.target.classList.contains("output-mid")) {
        collectOutputs();
        renderOutputs();
      } else {
        collectOutputs();
      }
      autoSaveDraft();
    });
    document.getElementById("outputRowsContainer")?.addEventListener("click", function (event) {
      if (event.target.closest(".btn-remove-output")) {
        const row = event.target.closest("[data-output-row]");
        collectOutputs(); // sync state sebelum splice agar index akurat
        state.outputs.splice(Number(row.getAttribute("data-output-row")), 1);
        renderOutputs();
        refreshAnalysis();
        autoSaveDraft();
      }
    });
    document.getElementById("tableBody")?.addEventListener("focus", function (e) {
      if (e.target.tagName === "INPUT" && e.target.classList.contains("table-input")) e.target.select();
    }, true);
    document.getElementById("tableBody")?.addEventListener("blur", function (e) {
      if (e.target.tagName === "INPUT" && e.target.classList.contains("table-input")) {
        e.target.value = fmtMat(parseLocalizedFloat(e.target.value));
      }
    }, true);
    document.getElementById("tableBody")?.addEventListener("click", function (e) {
      const cell = e.target.closest("[data-photo-cell]");
      if (cell) openPhotoModal(cell.getAttribute("data-photo-cell"));
    });
    document.getElementById("tableBody")?.addEventListener("input", function () {
      snapshotRows();
      refreshMaterialRowCalculations();
      refreshAnalysis();
      autoSaveDraft();
    });
    document.getElementById("btnSubmit")?.addEventListener("click", window.submitData);
    document.getElementById("btnReset")?.addEventListener("click", window.resetData);
    ["rptDowntimeMin", "rptRejectPrinting", "rptSpeed", "rptTrouble", "rptNearMiss", "rptNotes"].forEach((id) =>
      document.getElementById(id)?.addEventListener("input", () => { refreshAnalysis(); autoSaveDraft(); })
    );
  });
})();
