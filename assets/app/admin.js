(function () {
  const App = window.ProdApp;
  const state = App.state;

  function activeSortable() {
    const list = document.getElementById("materialSortableList");
    if (!list || !window.Sortable) return;
    if (state._sortable) state._sortable.destroy();
    state._sortable = new Sortable(list, { animation: 150 });
  }

  function renderMaterialList() {
    const list = document.getElementById("materialSortableList");
    if (!list) return;
    list.innerHTML = state.materials.map((material) => `
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <span class="material-name"><i class="fa-solid fa-grip-vertical text-muted me-2" style="cursor:grab"></i>${App.esc(material)}</span>
        <span>
          <button class="btn btn-sm btn-outline-primary me-1" data-edit-material="${App.esc(material)}"><i class="fa-solid fa-pen"></i></button>
          <button class="btn btn-sm btn-outline-danger" data-del-material="${App.esc(material)}"><i class="fa-solid fa-trash"></i></button>
        </span>
      </li>
    `).join("");
    activeSortable();
    list.querySelectorAll("[data-edit-material]").forEach((btn) => btn.addEventListener("click", function () {
      window.openMaterialEditModal(this.getAttribute("data-edit-material"));
    }));
    list.querySelectorAll("[data-del-material]").forEach((btn) => btn.addEventListener("click", async function () {
      const name = this.getAttribute("data-del-material");
      await App.api("api/materials.php?action=delete", { method: "POST", body: JSON.stringify({ name }) });
      state.materials = state.materials.filter((item) => item !== name);
      if (state.suppliers?.map) delete state.suppliers.map[name];
      if (state.suppliers?.order) state.suppliers.order = state.suppliers.order.filter((item) => item !== name);
      renderMaterialList();
      window.renderTable();
    }));
  }

  window.openMaterialEditModal = function openMaterialEditModal(name) {
    document.getElementById("matOldName").value = name;
    document.getElementById("matName").value = name;
    document.getElementById("matSuppliers").value = (state.suppliers?.map?.[name] || []).join("\n");
    bootstrap.Modal.getOrCreateInstance(document.getElementById("modalMaterial")).show();
  };

  window.saveMaterialEdit = async function saveMaterialEdit() {
    const btn = document.getElementById("btnSaveMaterial");
    const oldName = document.getElementById("matOldName").value;
    const newName = document.getElementById("matName").value.trim().toUpperCase();
    const suppliers = document.getElementById("matSuppliers").value
      .split("\n").map((s) => s.trim()).filter(Boolean);
    if (!newName) { App.toast("error", "Nama material wajib diisi."); return; }
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Menyimpan...'; }
    try {
      await App.api("api/materials.php?action=update", { method: "POST", body: JSON.stringify({ oldName, newName, suppliers }) });
      const res = await App.api("api/materials.php?action=list", { method: "GET" });
      state.suppliers = res.data || { map: {}, order: [] };
      state.materials = [...(state.suppliers.order || [])];
      renderMaterialList();
      window.renderTable();
      bootstrap.Modal.getOrCreateInstance(document.getElementById("modalMaterial")).hide();
      App.toast("success", "Material tersimpan");
    } catch (e) {
      App.toast("error", "Gagal menyimpan material", e.message);
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Simpan'; }
    }
  };

  function renderConversionTable() {
    const tbody = document.querySelector("#tableConversion tbody");
    if (!tbody) return;
    const keyword = (document.getElementById("searchConversion")?.value || "").toLowerCase();
    tbody.innerHTML = state.conversions.filter((item) => {
      const hay = `${item.mid} ${item.name}`.toLowerCase();
      return hay.includes(keyword);
    }).map((item) => `
      <tr>
        <td>${App.esc(item.mid)}</td>
        <td>${App.esc(item.name)}</td>
        <td>${App.fmt(item.weight)}</td>
        <td>${App.fmt(item.ratio, 0)}</td>
        <td>${App.esc(item.catBag || "")}</td>
        <td>${App.esc(item.catBox || "")}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-primary" data-edit-conv="${App.esc(item.mid)}">Edit</button>
          <button class="btn btn-sm btn-outline-danger" data-del-conv="${App.esc(item.mid)}">Delete</button>
        </td>
      </tr>
    `).join("");
    tbody.querySelectorAll("[data-edit-conv]").forEach((button) => button.addEventListener("click", function () {
      const mid = this.getAttribute("data-edit-conv");
      const item = state.conversions.find((row) => row.mid === mid);
      if (!item) return;
      document.getElementById("modalConvTitle").textContent = "Edit Produk";
      document.getElementById("convOldMid").value = item.mid;
      document.getElementById("convMid").value = item.mid;
      document.getElementById("convName").value = item.name;
      document.getElementById("convWeight").value = item.weight || 0;
      document.getElementById("convRatio").value = item.ratio || 0;
      document.getElementById("convCatBag").value = item.catBag || "";
      document.getElementById("convCatBox").value = item.catBox || "";
      resetConversionModalBtn();
      populateMaterialDatalist();
      bootstrap.Modal.getOrCreateInstance(document.getElementById("modalConversion")).show();
    }));
    tbody.querySelectorAll("[data-del-conv]").forEach((button) => button.addEventListener("click", async function () {
      const mid = this.getAttribute("data-del-conv");
      await App.api("api/conversions.php?action=delete", { method: "POST", body: JSON.stringify({ mid }) });
      state.conversions = state.conversions.filter((item) => item.mid !== mid);
      renderConversionTable();
    }));
  }

  window.switchAdminTab = function switchAdminTab(tab) {
    document.querySelectorAll(".admin-tab-pane").forEach((pane) => pane.classList.add("d-none"));
    document.querySelectorAll(".admin-tab-btn").forEach((btn) => btn.classList.toggle("active", btn.getAttribute("data-tab") === tab));
    document.getElementById(`adminTab_${tab}`)?.classList.remove("d-none");
  };

  function populateMaterialDatalist() {
    const dl = document.getElementById("materialDatalistConv");
    if (dl) dl.innerHTML = (state.materials || []).map((m) => `<option value="${App.esc(m)}">`).join("");
  }

  function resetConversionModalBtn() {
    const btn = document.getElementById("btnSaveConversion");
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Simpan'; }
  }

  window.openConversionModal = function openConversionModal() {
    document.getElementById("modalConvTitle").textContent = "Tambah Produk Baru";
    ["convOldMid", "convMid", "convName", "convWeight", "convRatio", "convCatBag", "convCatBox"].forEach((id) => document.getElementById(id).value = "");
    resetConversionModalBtn();
    populateMaterialDatalist();
    bootstrap.Modal.getOrCreateInstance(document.getElementById("modalConversion")).show();
  };

  window.saveConversionData = async function saveConversionData() {
    const btn = document.getElementById("btnSaveConversion");
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Menyimpan...'; }
    const payload = {
      oldMid: document.getElementById("convOldMid").value,
      mid: document.getElementById("convMid").value.trim(),
      name: document.getElementById("convName").value.trim(),
      weight: Number(document.getElementById("convWeight").value || 0),
      ratio: Number(document.getElementById("convRatio").value || 0),
      catBag: document.getElementById("convCatBag").value.trim(),
      catBox: document.getElementById("convCatBox").value.trim(),
    };
    try {
      await App.api("api/conversions.php?action=save", { method: "POST", body: JSON.stringify(payload) });
      const res = await App.api("api/conversions.php?action=list", { method: "GET" });
      state.conversions = res.data || [];
      renderConversionTable();
      bootstrap.Modal.getOrCreateInstance(document.getElementById("modalConversion")).hide();
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Simpan'; }
      App.toast("success", "Produk tersimpan");
    } catch (e) {
      App.toast("error", "Gagal menyimpan produk", e.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i>Simpan'; }
    }
  };

  window.filterConversionTable = renderConversionTable;

  window.addNewMaterial = async function addNewMaterial() {
    const name = String(document.getElementById("newMaterialInput")?.value || "").trim().toUpperCase();
    if (!name) return;
    await App.api("api/materials.php?action=update", { method: "POST", body: JSON.stringify({ oldName: "", newName: name, suppliers: [] }) });
    state.materials.push(name);
    state.materials.sort();
    document.getElementById("newMaterialInput").value = "";
    renderMaterialList();
    window.renderTable();
  };

  window.saveMaterialChanges = async function saveMaterialChanges() {
    const order = Array.from(document.querySelectorAll("#materialSortableList li .material-name")).map((el) => el.textContent.trim());
    await App.api("api/materials.php?action=saveList", { method: "POST", body: JSON.stringify({ order }) });
    state.materials = order;
    App.toast("success", "Urutan material tersimpan");
  };

  window.saveMachineSizeConfig = async function saveMachineSizeConfig() {
    const payload = {
      mesin: document.getElementById("adminListMesin").value.trim(),
      size: document.getElementById("adminListSize").value.trim(),
      aliases: document.getElementById("adminListAlias").value.trim() || "{}",
    };
    await App.api("api/config.php?action=save", { method: "POST", body: JSON.stringify(payload) });
    App.toast("success", "Config mesin/size tersimpan");
  };

  window.saveLockDate = async function saveLockDate() {
    await App.api("api/settings.php", { method: "POST", body: JSON.stringify({ lockDate: document.getElementById("adminLockDate").value }) });
    App.toast("success", "Lock date tersimpan");
  };

  window.saveBroadcast = async function saveBroadcast(forceToggle) {
    await App.api("api/settings.php", {
      method: "POST",
      body: JSON.stringify({
        broadcastMsg: document.getElementById("adminBroadcastMsg").value || "",
        broadcastActive: document.getElementById("switchBroadcast").checked,
      }),
    });
    App.toast("success", "Broadcast tersimpan");
  };

  window.saveAppSettings = async function saveAppSettings() {
    await App.api("api/settings.php", { method: "POST", body: JSON.stringify({ handover: document.getElementById("switchHandover").checked }) });
    App.toast("success", "Setting tersimpan");
  };

  window.loadAuditLogs = async function loadAuditLogs() {
    const pin = document.getElementById("adminPinForLogs").value;
    // Send PIN in POST body -- never in URL query params (prevents server log exposure)
    const res = await App.api("api/admin.php?action=logs", { method: "POST", body: JSON.stringify({ pin }) });
    const tbody = document.querySelector("#auditLogTable tbody");
    tbody.innerHTML = (res.data || []).map((item) => `<tr><td>${App.esc(item.time)}</td><td>${App.esc(item.actor)}</td><td>${App.esc(item.action)}</td><td class="text-break-all">${App.esc(item.details)}</td></tr>`).join("") || '<tr><td colspan="4" class="text-center text-muted">Tidak ada log / PIN salah.</td></tr>';
  };

  window.openChangePassword = async function openChangePassword() {
    const result = await Swal.fire({
      title: "Ganti Password",
      html: '<input id="oldPass" type="password" class="swal2-input" placeholder="Password lama"><input id="newPass" type="password" class="swal2-input" placeholder="Password baru">',
      focusConfirm: false,
      preConfirm: () => ({
        oldPass: document.getElementById("oldPass").value,
        newPass: document.getElementById("newPass").value,
      }),
      showCancelButton: true,
    });
    if (!result.isConfirmed) return;
    await App.api("api/auth.php?action=changePassword", {
      method: "POST",
      body: JSON.stringify({ username: state.username, oldPass: result.value.oldPass, newPass: result.value.newPass }),
    });
    App.toast("success", "Password berubah");
  };

  window.loadPhotoMigrationProgress = async function loadPhotoMigrationProgress() {
    const res = await App.api("api/admin.php?action=photoProgress", { method: "GET" });
    const summary = res.data.summary || {};
    const range = res.data.range || {};
    document.getElementById("photoProgressStatus").textContent = `Done ${summary.doneCount || 0} / ${summary.totalCount || 0} (${summary.progressPct || 0}%)`;
    document.getElementById("photoProgressRange").textContent = `${range.firstAnyDone || "-"} s/d ${range.lastAnyDone || "-"}`;
    document.getElementById("photoProgressSummary").textContent = `Pending ${summary.pendingCount || 0} | Failed ${summary.failedCount || 0}`;
    const tbody = document.querySelector("#photoProgressTable tbody");
    tbody.innerHTML = (res.data.latestDays || []).map((row) => `
      <tr>
        <td>${App.esc(row.productionDate)}</td>
        <td class="text-end">${App.fmt(row.totalPhotos, 0)}</td>
        <td class="text-end">${App.fmt(row.donePhotos, 0)}</td>
        <td class="text-end">${App.fmt(row.pendingPhotos, 0)}</td>
        <td class="text-end">${App.fmt(row.failedPhotos, 0)}</td>
        <td class="text-end">${App.fmt(row.progressPct)}%</td>
      </tr>
    `).join("") || '<tr><td colspan="6" class="text-center text-muted">Belum ada progres.</td></tr>';
  };

  document.addEventListener("DOMContentLoaded", function () {
    // searchConversion sudah punya oninput="filterConversionTable()" di HTML — tidak perlu addEventListener lagi
    document.getElementById("switchBroadcast")?.addEventListener("change", window.saveBroadcast);
    const obs = new MutationObserver(() => {
      if (state.isAdmin) {
        renderMaterialList();
        renderConversionTable();
        document.getElementById("adminListMesin").value = (state.config?.mesin || []).join(", ");
        document.getElementById("adminListSize").value = (state.config?.size || []).join(", ");
        document.getElementById("adminListAlias").value = state.config?.aliases || "{}";
      }
    });
    const target = document.getElementById("adminView");
    if (target) obs.observe(target, { attributes: true, attributeFilter: ["class"] });
  });
})();
