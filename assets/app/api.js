(function () {
  const state = {
    token: localStorage.getItem("PROD3_TOKEN") || "",
    username: localStorage.getItem("PROD3_USER") || "",
    machine: localStorage.getItem("PROD3_MACHINE") || "",
    isAdmin: localStorage.getItem("PROD3_ADMIN") === "1",
    config: null,
    suppliers: { map: {}, order: [] },
    conversions: [],
    materials: [],
    outputs: [],
    history: [],
    editUuid: "",
    editRevision: 0,
    charts: {},
  };

  async function api(path, options = {}) {
    const headers = Object.assign({ "Content-Type": "application/json" }, options.headers || {});
    if (state.token) headers.Authorization = `Bearer ${state.token}`;
    const response = await fetch(path, Object.assign({}, options, { headers }));
    const text = await response.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (error) {}
    if (!response.ok) {
      const message = json && json.message ? json.message : `HTTP ${response.status}`;
      throw new Error(message);
    }
    if (json && json.success === false) throw new Error(json.message || "Request gagal");
    return json;
  }

  function saveSession() {
    localStorage.setItem("PROD3_TOKEN", state.token || "");
    localStorage.setItem("PROD3_USER", state.username || "");
    localStorage.setItem("PROD3_MACHINE", state.machine || "");
    localStorage.setItem("PROD3_ADMIN", state.isAdmin ? "1" : "0");
  }

  function clearSession() {
    state.token = "";
    state.username = "";
    state.machine = "";
    state.isAdmin = false;
    saveSession();
  }

  function fmt(value, digits = 2) {
    const n = Number(value || 0);
    return n.toLocaleString("id-ID", { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }

  function esc(value) {
    return String(value ?? "").replace(/[&<>"]/g, (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[ch]));
  }

  function toast(icon, title, text = "") {
    if (window.Swal) return Swal.fire({ icon, title, text, timer: icon === "success" ? 1600 : undefined, showConfirmButton: icon !== "success" });
    alert(`${title}${text ? "\n" + text : ""}`);
  }

  function currentShiftHours(shiftVal) {
    const shift = shiftVal || document.getElementById("inputShift")?.value || "1";
    if (shift === "1") return ["07", "08", "09", "10", "11", "12", "13", "14"];
    if (shift === "2") return ["15", "16", "17", "18", "19", "20", "21", "22"];
    return ["23", "00", "01", "02", "03", "04", "05", "06"];
  }

  function getShiftStartIndex(shift) {
    return shift === "1" ? 0 : shift === "2" ? 8 : 16;
  }

  window.ProdApp = {
    state,
    api,
    saveSession,
    clearSession,
    fmt,
    esc,
    toast,
    currentShiftHours,
    getShiftStartIndex,
  };

  window.toggleTheme = function toggleTheme() {
    const root = document.documentElement;
    const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
    root.setAttribute("data-theme", next);
    localStorage.setItem("PROD3_THEME", next);
  };

  const theme = localStorage.getItem("PROD3_THEME");
  if (theme) document.documentElement.setAttribute("data-theme", theme);

  document.addEventListener("keydown", function(e) {
    if (e.key === "Enter") {
      if (e.target.tagName === "TEXTAREA") return;

      const swalContainer = document.querySelector(".swal2-container");
      if (swalContainer && swalContainer.contains(e.target)) {
        const confirmBtn = swalContainer.querySelector(".swal2-confirm");
        if (e.target.tagName === "INPUT" || e.target.tagName === "SELECT") {
            e.preventDefault();
            if (confirmBtn && !confirmBtn.disabled) confirmBtn.click();
        }
        return;
      }

      const viewInput = document.getElementById("viewInput");
      if (viewInput && viewInput.contains(e.target) && !viewInput.classList.contains("d-none")) {
          if (e.target.tagName === "INPUT" || e.target.tagName === "SELECT") {
              e.preventDefault();
              const btnSubmit = document.getElementById("btnSubmit");
              if (btnSubmit && !btnSubmit.disabled) btnSubmit.click();
          }
          return;
      }

      const loginWrapper = document.getElementById("login-wrapper");
      if (loginWrapper && loginWrapper.contains(e.target) && !loginWrapper.classList.contains("d-none")) {
          if (e.target.tagName === "INPUT" || e.target.tagName === "SELECT") {
              e.preventDefault();
              const btnLogin = document.getElementById("btnLoginAction");
              if (btnLogin && !btnLogin.disabled) btnLogin.click();
          }
          return;
      }
    }
  });
})();
