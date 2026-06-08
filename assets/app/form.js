(function () {
  const App = window.ProdApp;
  const state = App.state;

  /**
   * C4: Parse an Indonesian-locale number string to a plain JS float.
   * Handles: "2,50%" -> 2.5   "1.234,56" -> 1234.56   "0,63" -> 0.63
   * Thousands separator is "." and decimal separator is "," in id-ID locale.
   */
  function parseLocalizedFloat(str) {
    if (str === null || str === undefined) return 0;
    const s = String(str).trim().replace(/%$/, ""); // strip trailing percent sign
    const normalized = s.replace(/\./g, "").replace(",", "."); // remove thousands dots, swap decimal comma
    const v = parseFloat(normalized);
    return isNaN(v) ? 0 : v;
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
      const hours = Array.from(tr.querySelectorAll('[data-hour]')).map((input) => Number(input.value || 0));
      rows.push({
        material,
        supplier,
        stockAwal: Number(tr.querySelector('[data-field="stockAwal"]')?.value || 0),
        masuk: Number(tr.querySelector('[data-field="masuk"]')?.value || 0),
        retur: Number(tr.querySelector('[data-field="retur"]')?.value || 0),
        reject: Number(tr.querySelector('[data-field="reject"]')?.value || 0),
        hours,
        photos: [],
      });
    });
    return rows;
  }

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
    head.innerHTML = `
      <tr class="text-secondary small text-uppercase">
        <th style="width: 18%; padding-left: 20px;">Material Name</th>
        <th style="width: 12%;">Supplier</th>
        <th class="text-center" style="width: 4.8%;">Stk Awal</th>
        <th class="text-center" style="width: 4.8%;">Masuk</th>
        <th class="text-center text-danger" style="width: 4.8%;">Retur</th>
        <th class="text-center text-danger" style="width: 4.8%;">Reject</th>
        ${hours.map((hour) => `<th class="text-center text-primary" style="width: 4.8%; font-size:0.7rem; padding:0;">${hour}</th>`).join("")}
        <th class="text-center bg-prod-subtle" style="width: 6%;">Total</th>
        <th class="text-center bg-sisa-subtle" style="width: 6%;">Sisa</th>
      </tr>
    `;
    tbody.innerHTML = materials.map((material) => {
      const existing = (state.formRows && state.formRows[material]) || defaultMaterialRow(material, state.suppliers.map[material]?.[0] || "");
      const total = existing.hours.reduce((a, b) => a + Number(b || 0), 0) + Number(existing.reject || 0);
      const sisa = Number(existing.stockAwal || 0) + Number(existing.masuk || 0) - Number(existing.retur || 0) - total;
      return `
        <tr data-material-row="1" data-material="${App.esc(material)}">
          <td class="fw-bold ps-4 text-muted">${App.esc(material)}</td>
          <td>
            <select class="form-select form-select-sm table-input text-start" data-field="supplier" style="font-size:0.85rem; min-width:130px;">
              <option value="">- Pilih -</option>
              ${(state.suppliers.map[material] || []).map((supplier) => `<option value="${App.esc(supplier)}" ${supplier === existing.supplier ? "selected" : ""}>${App.esc(supplier)}</option>`).join("")}
            </select>
          </td>
          <td><input class="table-input" data-field="stockAwal" type="number" min="0" value="${existing.stockAwal || 0}"></td>
          <td><input class="table-input" data-field="masuk" type="number" min="0" value="${existing.masuk || 0}"></td>
          <td><input class="table-input" data-field="retur" type="number" min="0" value="${existing.retur || 0}"></td>
          <td><input class="table-input text-danger fw-bold" data-field="reject" type="number" min="0" value="${existing.reject || 0}"></td>
          ${existing.hours.map((value, index) => `<td><input class="table-input" data-hour="${index}" type="number" min="0" value="${value || 0}"></td>`).join("")}
          <td class="text-center bg-prod-subtle"><span class="calc-cell fw-bold text-primary">${App.fmt(total)}</span></td>
          <td class="text-center bg-sisa-subtle"><span class="calc-cell fw-bold ${sisa < 0 ? "text-danger" : ""}">${App.fmt(sisa)}</span></td>
        </tr>
      `;
    }).join("");
    document.getElementById("shiftBadge").textContent = `SHIFT ${document.getElementById("inputShift")?.value || "1"}`;
  }

  function refreshMaterialRowCalculations() {
    document.querySelectorAll("#tableBody tr[data-material-row]").forEach((tr) => {
      const stockAwal = Number(tr.querySelector('[data-field="stockAwal"]')?.value || 0);
      const masuk = Number(tr.querySelector('[data-field="masuk"]')?.value || 0);
      const retur = Number(tr.querySelector('[data-field="retur"]')?.value || 0);
      const reject = Number(tr.querySelector('[data-field="reject"]')?.value || 0);
      const hours = Array.from(tr.querySelectorAll('[data-hour]')).map((input) => Number(input.value || 0));
      const total = hours.reduce((sum, value) => sum + value, 0) + reject;
      const sisa = stockAwal + masuk - retur - total;
      const cells = tr.querySelectorAll(".calc-cell");
      if (cells[0]) cells[0].textContent = App.fmt(total);
      if (cells[1]) {
        cells[1].textContent = App.fmt(sisa);
        cells[1].classList.toggle("text-danger", sisa < 0);
      }
    });
  }

  function snapshotRows() {
    state.formRows = {};
    getActiveMaterials().forEach((row) => { state.formRows[row.material] = row; });
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
          <div class="col-md-3">
            <label class="small fw-bold text-muted">Produk</label>
            <select class="form-select output-mid">
              <option value="">Pilih produk...</option>
              ${state.conversions.map((item) => `<option value="${App.esc(item.mid)}" ${item.mid === output.mid ? "selected" : ""}>${App.esc(item.mid)} - ${App.esc(item.name)}</option>`).join("")}
            </select>
          </div>
          <div class="col-md-2"><label class="small fw-bold text-muted">Qty Box</label><input class="form-control output-qty" type="number" value="${output.qtyBox || 0}"></div>
          <div class="col-md-2"><label class="small fw-bold text-muted">Counter PCS</label><input class="form-control output-counter" type="number" value="${output.counterPcs || 0}"></div>
          <div class="col-md-2"><label class="small fw-bold text-muted">Total Kg</label><input class="form-control output-kg" type="number" step="0.01" value="${output.totalKg || 0}"></div>
          <div class="col-md-2"><label class="small fw-bold text-muted">Loss Kg</label><input class="form-control output-loss" type="number" step="0.01" value="${output.lossKg || 0}"></div>
          <div class="col-md-1"><button class="btn btn-outline-danger w-100 btn-remove-output"><i class="fa-solid fa-trash"></i></button></div>
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

  function refreshAnalysis() {
    const outputs = state.outputs;
    const body = document.getElementById("analysisBody");
    if (!body) return;
    const theoretical = {};
    outputs.forEach((output) => {
      const product = state.conversions.find((item) => item.mid === output.mid);
      if (!product) return;
      if (product.catBag) theoretical[product.catBag] = (theoretical[product.catBag] || 0) + (Number(output.qtyBox || 0) * Number(product.ratio || 0));
      if (product.catBox) theoretical[product.catBox] = (theoretical[product.catBox] || 0) + Number(output.qtyBox || 0);
    });
    const actual = {};
    getActiveMaterials().forEach((row) => {
      actual[row.material] = row.hours.reduce((a, b) => a + Number(b || 0), 0);
    });
    const mats = Object.keys(theoretical);
    if (!mats.length) {
      body.innerHTML = '<tr><td colspan="5" class="text-muted fst-italic py-3">Belum ada data output.</td></tr>';
    } else {
      body.innerHTML = mats.map((material) => {
        const target = Number(theoretical[material] || 0);
        const actualVal = Number(actual[material] || 0);
        const diff = actualVal - target;
        return `<tr>
          <td class="fw-bold">${App.esc(material)}</td>
          <td class="text-center">${App.fmt(target)}</td>
          <td class="text-center">${App.fmt(actualVal)}</td>
          <td class="text-center ${diff !== 0 ? "text-danger" : "text-success"}">${App.fmt(diff)}</td>
          <td class="text-center"><span class="badge ${Math.abs(diff) < 0.0001 ? "text-bg-success" : "text-bg-warning"}">${Math.abs(diff) < 0.0001 ? "OK" : "CHECK"}</span></td>
        </tr>`;
      }).join("");
    }
    const totalKg = outputs.reduce((sum, item) => sum + Number(item.totalKg || 0), 0);
    const totalLoss = outputs.reduce((sum, item) => sum + Number(item.lossKg || 0), 0);
    document.getElementById("grandTotalKg").textContent = App.fmt(totalKg);
    document.getElementById("grandTotalLoss").textContent = App.fmt(totalLoss);
    document.getElementById("grandTotalLossPct").textContent = totalKg > 0 ? `${App.fmt((totalLoss / totalKg) * 100)}%` : "0.00%";
    const downMin = Number(document.getElementById("rptDowntimeMin")?.value || 0);
    document.getElementById("rptDowntimePct").value = App.fmt((downMin / 480) * 100);
  }

  function collectOutputs() {
    state.outputs = Array.from(document.querySelectorAll("[data-output-row]")).map((row) => ({
      mid: row.querySelector(".output-mid")?.value || "",
      qtyBox: Number(row.querySelector(".output-qty")?.value || 0),
      counterPcs: Number(row.querySelector(".output-counter")?.value || 0),
      totalKg: Number(row.querySelector(".output-kg")?.value || 0),
      lossKg: Number(row.querySelector(".output-loss")?.value || 0),
    })).filter((item) => item.mid);
    renderOutputs();
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
    const start = App.getShiftStartIndex(String(entry.shift || "1"));
    (entry.items || []).forEach((item) => {
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
        const lossPct = rptReport.lossPct || '0%';
        const speed = rptReport.speed || '-';
        const downtimeMin = rptReport.downtimeMin || '0';

        const diffBtn = (rpt.revision > 0)
            ? `<button class="btn btn-sm text-info btn-link p-0 me-3" data-action="diff" data-id="${App.esc(rpt.id)}" title="Lihat Perbedaan"><i class="fa-solid fa-clock-rotate-left fs-5"></i></button>`
            : '';

        let mainAction = '';
        if (isAdmin) {
            mainAction = `
                <button class="btn btn-sm btn-outline-danger border-0 me-1" data-action="delete" data-id="${App.esc(rpt.id)}" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                <button class="btn btn-sm btn-warning text-dark fw-bold px-3 rounded-pill" style="font-size:0.75rem" data-action="edit" data-id="${App.esc(rpt.id)}">Revisi</button>
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
            matHtml += `<th>Retur</th><th class="text-danger">Reject</th><th class="text-primary fw-bold">Total</th><th>Sisa</th></tr></thead><tbody>`;
            
            (item.items || []).forEach(mat => {
                const mapIdx = { "1": 0, "2": 8, "3": 16 };
                const startIdx = mapIdx[shift] || 0;
                const displayHours = (mat.hours && mat.hours.length >= 24) ? mat.hours.slice(startIdx, startIdx + 8) : Array(8).fill(0);
                
                let hoursCells = "";
                displayHours.forEach(val => {
                    const style = val > 0 ? "fw-bold text-dark" : "text-light-gray";
                    hoursCells += `<td class="text-center ${style}" style="font-size:0.8rem">${val > 0 ? val : '-'}</td>`;
                });

                matHtml += `<tr>
                    <td class="fw-bold text-secondary small text-start text-truncate" style="max-width: 150px;" title="${App.esc(mat.material)}">${App.esc(mat.material)}</td>
                    <td class="text-center small">${mat.stock}</td>
                    <td class="text-center small">${mat.in}</td>
                    ${hoursCells}
                    <td class="text-center small">${mat.retur}</td>
                    <td class="text-center small text-danger fw-bold">${mat.reject || 0}</td>
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

            const rep = item.logs || {};
            let analHtml = `<h6 class="small fw-bold text-danger border-bottom pb-1">3. Analisis & Catatan</h6>
            <div class="row g-2 small bg-light p-2 rounded border text-start">
                <div class="col-6">Loss Ratio: <b>${App.esc(rep.lossPct)}</b></div>
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
                        ${analHtml}
                       </div>`,
                width: '900px',
                showConfirmButton: true,
                confirmButtonText: 'Tutup',
                showCloseButton: true
            });
            return;
          }
          if (action === "gallery") {
            let galleryHTML = '<div class="d-flex flex-wrap gap-2 justify-content-center mt-2">';
            let hasPhotos = false;
            (item.items || []).forEach(mat => {
              if (mat.photos && mat.photos.length > 0) {
                mat.photos.forEach(photoId => {
                  if (!photoId.trim()) return;
                  hasPhotos = true;
                  // H4: include token in URL so requireSession() can auth the GET request
                  const url = 'api/photos.php?file=' + encodeURIComponent(photoId.trim()) + '&token=' + encodeURIComponent(App.state.token || '');
                  galleryHTML += `
                    <a href="${url}" target="_blank" class="text-decoration-none" title="Klik untuk perbesar" style="width: 140px; display: block; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: relative;">
                      <img src="${url}" alt="Foto" style="width: 100%; height: 140px; object-fit: cover; display: block;">
                      <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: white; font-size: 0.65rem; padding: 4px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${App.esc(mat.material)}</div>
                    </a>`;
                });
              }
            });
            galleryHTML += '</div>';

            if (!hasPhotos) {
              App.toast("info", "Tidak ada foto untuk record ini.");
              return;
            }

            Swal.fire({
              title: `Galeri Foto - Shift ${item.shift}`,
              html: galleryHTML,
              width: '600px',
              showCloseButton: true,
              showConfirmButton: false
            });
            return;
          }
          if (action === "whatsapp") {
            const l = item.logs || {};
            let itemDetails = "";
            if (item.outputs && item.outputs.length > 0) {
                item.outputs.forEach(o => {
                    const kg = parseFloat(o.kg) || 0;
                    const loss = parseFloat(o.loss) || 0;
                    let lp = "0%";
                    if(kg > 0) lp = ((loss/kg)*100).toFixed(2) + "%";
                    itemDetails += `> *${o.name}*\n   Output : *${o.qty} Box*\n   Prod   : ${App.fmt(o.counter)} pcs (${App.fmt(kg)} Kg) | Loss: ${App.fmt(loss)} Kg (${lp})\n\n`;
                });
            } else {
                itemDetails = "> Belum ada input produksi\n";
            }
            const text = `*LAPORAN PRODUKSI*\n` +
                `*Tanggal:* ${item.date}\n` +
                `*Mesin:* ${item.mesin}\n` +
                `*Shift:* ${item.shift}\n\n` +
                `*Rincian Hasil Produk:*\n${itemDetails}` +
                `*Summary Report:*\n` +
                `- Loss : *${l.lossPct || '0%'}*\n` +
                `- Speed : *${l.speed || '0'} ppm*\n` +
                `- Downtime : *${l.downtimeMin || '0'} Menit*\n` +
                `- Reject Print : *${l.rejectPrintingKg || '0'} Kg*\n\n` +
                `*Trouble/Kendala:*\n${l.trouble || '-'}\n\n` +
                `*Catatan/Pesan:*\n${l.notes || '-'}`;
            
            navigator.clipboard.writeText(text).then(() => App.toast('success', 'Teks WA di-copy ke Clipboard!')).catch(() => App.toast('error', 'Gagal copy teks!'));
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
              width: '700px',
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

  window.resetData = function resetData() {
    state.editUuid = "";
    state.editRevision = 0;
    state.formRows = {};
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
    collectOutputs();
    const payload = buildSubmitPayload();
    const action = state.editUuid ? "revise" : "submit";
    try {
      const endpoint = state.editUuid ? "api/transactions.php?action=revise" : "api/transactions.php?action=submit";
      await App.api(endpoint, { method: "POST", body: JSON.stringify(payload) });
      App.toast("success", state.editUuid ? "Revisi tersimpan" : "Data tersimpan");
      window.resetData();
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

  // Fresh search — resets to page 1 and replaces results
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

  window.triggerHandover = async function triggerHandover() {
    try {
      const mesin = document.getElementById("inputMesin")?.value || "";
      const shift = document.getElementById("inputShift")?.value || "1";
      const date = document.getElementById("inputTanggal")?.value || "";
      const res = await App.api(`api/transactions.php?action=previousStock&mesin=${encodeURIComponent(mesin)}&shift=${encodeURIComponent(shift)}&date=${encodeURIComponent(date)}`, { method: "GET" });
      snapshotRows();
      Object.entries(res.data || {}).forEach(([material, stock]) => {
        if (!state.formRows[material]) state.formRows[material] = defaultMaterialRow(material);
        state.formRows[material].stockAwal = Number(stock || 0);
      });
      renderMaterialTable();
      App.toast("success", "Stok shift lalu dimuat");
    } catch (error) {
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
    
    if (state.settings.handover && !state.isAdmin) {
      await window.triggerHandover();
    }
  };

  document.addEventListener("DOMContentLoaded", function () {
    const triggerIfHandover = () => {
      if (state.settings && state.settings.handover && !state.isAdmin) {
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
    document.getElementById("outputRowsContainer")?.addEventListener("input", collectOutputs);
    document.getElementById("outputRowsContainer")?.addEventListener("change", collectOutputs);
    document.getElementById("outputRowsContainer")?.addEventListener("click", function (event) {
      if (event.target.closest(".btn-remove-output")) {
        const row = event.target.closest("[data-output-row]");
        state.outputs.splice(Number(row.getAttribute("data-output-row")), 1);
        renderOutputs();
        refreshAnalysis();
      }
    });
    document.getElementById("tableBody")?.addEventListener("input", function () {
      snapshotRows();
      refreshMaterialRowCalculations();
      refreshAnalysis();
    });
    document.getElementById("btnSubmit")?.addEventListener("click", window.submitData);
    document.getElementById("btnReset")?.addEventListener("click", window.resetData);
    ["rptDowntimeMin", "rptRejectPrinting", "rptSpeed"].forEach((id) => document.getElementById(id)?.addEventListener("input", refreshAnalysis));
  });
})();
