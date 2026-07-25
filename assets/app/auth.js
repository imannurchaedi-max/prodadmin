(function () {
  const App = window.ProdApp;
  let takeoverMonitor = 0;
  let requestPromptOpen = false;

  function makeUiHandledError(message) {
    const error = new Error(message);
    error.uiHandled = true;
    return error;
  }

  async function showRetryCountdown(seconds, options = {}) {
    const {
      title = "Login gagal",
      message = "Permintaan takeover timeout.",
      doneMessage = "Silakan coba login lagi.",
      buttonText = "OK",
    } = options;

    if (!window.Swal) {
      await new Promise((resolve) => window.setTimeout(resolve, seconds * 1000));
      return;
    }

    let remaining = seconds;
    let intervalId = 0;
    await Swal.fire({
      icon: "error",
      title,
      html: `${message}<br>Tunggu <b id="takeoverRetryCountdown">${remaining}</b> detik.<br><span class="text-muted">${doneMessage}</span>`,
      allowOutsideClick: false,
      allowEscapeKey: false,
      confirmButtonText: buttonText,
      didOpen: () => {
        const countdownEl = Swal.getHtmlContainer()?.querySelector("#takeoverRetryCountdown");
        intervalId = window.setInterval(() => {
          remaining -= 1;
          if (countdownEl) countdownEl.textContent = String(Math.max(remaining, 0));
          if (remaining <= 0) {
            window.clearInterval(intervalId);
            Swal.getConfirmButton()?.removeAttribute("disabled");
          }
        }, 1000);
        Swal.getConfirmButton()?.setAttribute("disabled", "disabled");
      },
      willClose: () => {
        if (intervalId) window.clearInterval(intervalId);
      },
      preConfirm: () => {
        if (remaining > 0) {
          Swal.showValidationMessage(`Tunggu ${remaining} detik lagi.`);
          return false;
        }
        return true;
      },
    });
  }

  function setAppVisible(visible) {
    const login = document.getElementById("login-wrapper");
    const app = document.getElementById("app-wrapper");
    const loader = document.getElementById("loader");
    if (login) login.style.display = visible ? "none" : "flex";
    if (app) app.style.display = visible ? "block" : "none";
    if (loader) loader.style.display = "none";
  }

  async function loadLoginMachines() {
    try {
      const res = await App.api("api/config.php?action=get", { headers: {} });
      const machines = res.data?.mesin || [];
      const select = document.getElementById("loginMachineSelect");
      if (!select) return;
      select.innerHTML = machines.map((item, index) => `<option value="${App.esc(item)}" ${index === 0 ? "selected" : ""}>${App.esc(item)}</option>`).join("");
    } catch (error) {
      App.toast("error", "Gagal memuat mesin", error.message);
    }
  }

  function updateUserBadge() {
    const el = document.getElementById("navUserName");
    if (el) el.textContent = App.state.username || "-";
  }

  async function afterLogin() {
    updateUserBadge();
    setAppVisible(true);
    await window.loadInitialData();
    startTakeoverMonitor();
  }

  async function enterAdminMode() {
    App.state.isAdmin = true;
    App.saveSession();
    updateUserBadge();
    await window.loadInitialData();
    App.toast("success", "Admin aktif");
  }

  async function exitAdminMode() {
    App.state.isAdmin = false;
    App.saveSession();
    updateUserBadge();
    if (typeof window.resetData === "function") window.resetData();
    await window.loadInitialData();
  }

  async function rawJson(path, payload) {
    const response = await fetch(path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    // Check HTTP status before parsing -- server errors can return HTML, crashing response.json()
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
  }

  async function approveTakeover(approved) {
    if (!App.state.username || !App.state.machine) return;
    await rawJson("api/auth.php?action=takeoverDecision", {
      username: App.state.username,
      machine: App.state.machine,
      approved,
    });
  }

  async function startTakeoverMonitor() {
    if (takeoverMonitor) window.clearInterval(takeoverMonitor);
    takeoverMonitor = window.setInterval(async function () {
      if (!App.state.token || !App.state.username || !App.state.machine) return;
      
      const pollToken = App.state.token;
      try {
        const res = await rawJson("api/auth.php?action=checkTakeover", {
          username: App.state.username,
          machine: App.state.machine,
          token: App.state.token,
        });
        
        // Prevent race condition: if the token changed during the request (e.g. user logged in as Admin), ignore this response
        if (pollToken !== App.state.token) return;

        const data = res.data || {};
        if (data.logout) {
          App.toast("warning", "Sesi berakhir", data.reason || "Sesi diambil alih.");
          await window.handleLogout();
          return;
        }
        if (data.hasRequest && !requestPromptOpen) {
          requestPromptOpen = true;
          try {
            const result = await Swal.fire({
              title: "Permintaan Ambil Alih",
              text: `Ada user lain yang ingin masuk ke ${App.state.machine}. Izinkan?`,
              icon: "question",
              showCancelButton: true,
              confirmButtonText: "Izinkan",
              cancelButtonText: "Tolak",
              allowOutsideClick: false,
            });
            await approveTakeover(result.isConfirmed);
          } finally {
            requestPromptOpen = false;  // Always reset, even if approveTakeover() throws
          }
        }
      } catch (error) {
        // Log polling failures so devs can see when the monitor breaks (was silently swallowed)
        console.warn("[takeoverMonitor]", error.message);
      }
    }, 5000);
  }

  async function waitForTakeover(username, tempToken, machine, isAdmin) {
    let SwalInstance = null;
    if (window.Swal) {
      SwalInstance = Swal.fire({
        title: "Menunggu Akses...",
        html: `Sesi di mesin ini sedang aktif.<br>Permintaan izin (Takeover) telah dikirim.<br><br><b>Mohon tunggu...</b>`,
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
      });
    }

    const poll = async () => rawJson("api/auth.php?action=takeoverStatus", {
      username,
      tempToken,
      machine,
    });

    try {
      while (true) {
        const res = await poll();
        // If server returns an error response, stop polling instead of looping forever
        if (res.success === false) {
          throw new Error(res.message || "Takeover poll error dari server.");
        }
        const data = res.data || {};
        if (data.status === "SUCCESS" && data.token) {
          App.state.token = data.token;
          App.state.username = data.username || username;
          App.state.machine = machine;
          App.state.isAdmin = isAdmin;
          App.saveSession();
          await afterLogin();
          App.toast("success", "Akses takeover disetujui");
          return;
        }
        if (data.status === "REJECTED") {
          throw new Error("Permintaan takeover ditolak oleh user aktif.");
        }
        if (data.status === "TIMEOUT") {
          const attempts = Number(data.attempts || 0);
          if (data.canDirectTakeover || attempts >= 2) {
            await showRetryCountdown(30, {
              message: "Permintaan takeover timeout 2x.",
              doneMessage: "Setelah hitungan selesai, login berikutnya akan menampilkan opsi paksa masuk.",
            });
            throw makeUiHandledError("Permintaan takeover timeout 2x.");
          }
          await showRetryCountdown(30);
          throw makeUiHandledError("Permintaan takeover timeout.");
        }
        await new Promise((resolve) => window.setTimeout(resolve, 2000));
      }
    } finally {
      // Guarantee Swal is always closed regardless of how the loop exits
      if (SwalInstance) Swal.close();
    }
  }

  async function doLogin(username, password, machine, isAdmin = false, force = false) {
    const res = await rawJson("api/auth.php?action=login", { username, password, machine, force });
    if (res.success && res.data?.token) {
      App.state.token = res.data.token;
      App.state.username = res.data.username;
      App.state.machine = machine;
      App.state.isAdmin = isAdmin;
      App.saveSession();
      await afterLogin();
      if (res.data.takeoverDirect) {
        App.toast("warning", "Takeover Berhasil", `Sesi lama di mesin ini telah diputus.`);
      }
      return;
    }
    if (res.status === "MACHINE_IN_USE" && !force) {
      const activeUser = res.activeUser || "user lain";
      const confirm = await Swal.fire({
        title: "Mesin Sedang Digunakan",
        text: `Mesin ini sedang digunakan oleh ${activeUser}. Ambil alih sekarang? (${activeUser} akan dikeluarkan)`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        confirmButtonText: "Ya, Ambil Alih",
        cancelButtonText: "Batal",
      });
      if (confirm.isConfirmed) {
        return doLogin(username, password, machine, isAdmin, true);
      }
      return;
    }
    // Guard against infinite recursion -- only show force prompt if we have NOT already sent force=true
    if (res.status === "FORCE_TAKEOVER_PROMPT" && !force) {
      const confirm = await Swal.fire({
        title: "Sesi Menggantung",
        text: "Mesin ini masih digunakan dan tidak merespon (timeout 2x). Paksa masuk (Take Over) sekarang?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        confirmButtonText: "Ya, Take Over",
        cancelButtonText: "Batal Masuk",
      });
      if (confirm.isConfirmed) {
        return doLogin(username, password, machine, isAdmin, true);
      }
      return;
    }
    if (res.status === "WAITING_APPROVAL" && res.tempToken) {
      await waitForTakeover(username, res.tempToken, machine, isAdmin);
      return;
    }
    throw new Error(res.message || "Login gagal");
  }

  async function submitLogin() {
    const username = document.getElementById("loginUserSelect")?.value || "";
    const password = document.getElementById("loginPasswordInput")?.value || "";
    const machine = document.getElementById("loginMachineSelect")?.value || "";
    if (!password || !machine) {
      App.toast("warning", "Lengkapi login", "Password dan mesin wajib diisi.");
      return;
    }
    try {
      await doLogin(username, password, machine, false);
    } catch (error) {
      if (error?.uiHandled) return;
      App.toast("error", "Login gagal", error.message);
    }
  }

  window.handleLogout = async function handleLogout() {
    try {
      if (App.state.username && App.state.machine) {
        await App.api("api/auth.php?action=logout", {
          method: "POST",
          body: JSON.stringify({ username: App.state.username, machine: App.state.machine }),
        });
      }
    } catch (error) {
      console.warn("[handleLogout]", error);
    }
    if (takeoverMonitor) window.clearInterval(takeoverMonitor);
    App.clearSession();
    if (typeof window.resetData === "function") window.resetData();
    setAppVisible(false);
  };

  window.logoutAdmin = async function logoutAdmin() {
    await exitAdminMode();
  };

  window.showAdminLogin = async function showAdminLogin() {
    const result = await Swal.fire({
      title: "Login Admin",
      input: "password",
      inputPlaceholder: "Password Admin",
      showCancelButton: true,
      confirmButtonText: "MASUK",
      inputAttributes: {
        autocapitalize: "off",
        autocorrect: "off",
        autocomplete: "current-password",
      },
      preConfirm: async (pin) => {
        if (!pin) {
          Swal.showValidationMessage("Password admin wajib diisi.");
          return false;
        }
        try {
          const res = await App.api("api/admin.php?action=verifyAdmin", {
            method: "POST",
            headers: { Authorization: "" },
            body: JSON.stringify({ pin }),
          });
          if (!res.data) {
            Swal.showValidationMessage("Password admin salah.");
            return false;
          }
          return pin;
        } catch (error) {
          Swal.showValidationMessage(error.message || "Verifikasi admin gagal.");
          return false;
        }
      },
    });
    if (!result.isConfirmed) return;
    if (!App.state.token) {
      const machine = document.getElementById("loginMachineSelect")?.value || "Mesin BHP 1";
      await doLogin("Admin", result.value, machine, true);
    } else {
      await enterAdminMode();
    }
  };

  async function validateExistingSession() {
    if (!App.state.token) return false;
    try {
      const res = await App.api("api/auth.php?action=validate", { method: "GET" });
      App.state.username = res.data.username || App.state.username;
      App.state.isAdmin = App.state.isAdmin || !!res.data.isAdmin;
      App.saveSession();
      await afterLogin();
      return true;
    } catch (error) {
      App.clearSession();
      return false;
    }
  }

  document.addEventListener("DOMContentLoaded", async function () {
    document.getElementById("loginForm")?.addEventListener("submit", async function (event) {
      event.preventDefault();
      // Disable button to prevent double submit
      const btn = document.getElementById("btnLoginAction");
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> LOADING...'; }
      await submitLogin();
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-right-to-bracket me-2"></i> MASUK'; }
    });

    await loadLoginMachines();
    const restored = await validateExistingSession();
    if (!restored) setAppVisible(false);
  });
})();
