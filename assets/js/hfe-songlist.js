(function () {
  "use strict";

  var config = window.hfeSonglistConfig || {};
  var ajaxUrl = typeof config.ajaxUrl === "string" ? config.ajaxUrl : "";
  var action = typeof config.action === "string" ? config.action : "hfe_songlist_generate";
  var globalNonce = typeof config.nonce === "string" ? config.nonce : "";
  var i18n = config.i18n || {};

  function t(key, fallback) {
    if (typeof i18n[key] === "string" && i18n[key] !== "") {
      return i18n[key];
    }
    return fallback;
  }

  function renderSongs(tableBody, songs) {
    tableBody.innerHTML = "";

    songs.forEach(function (song, index) {
      var row = document.createElement("tr");

      var idx = document.createElement("td");
      idx.textContent = String(index + 1).padStart(2, "0");
      row.appendChild(idx);

      var title = document.createElement("td");
      title.textContent = typeof song.title === "string" ? song.title : "";
      row.appendChild(title);

      var fileCell = document.createElement("td");
      var code = document.createElement("code");
      code.textContent = typeof song.filename === "string" ? song.filename : "";
      fileCell.appendChild(code);
      row.appendChild(fileCell);

      tableBody.appendChild(row);
    });
  }

  function showMessage(messageEl, type, text) {
    messageEl.classList.remove("hfe-songlist-hidden", "hfe-songlist-error", "hfe-songlist-success");
    messageEl.classList.add(type === "success" ? "hfe-songlist-success" : "hfe-songlist-error");
    messageEl.textContent = text;
  }

  function hideMessage(messageEl) {
    messageEl.textContent = "";
    messageEl.classList.add("hfe-songlist-hidden");
    messageEl.classList.remove("hfe-songlist-error", "hfe-songlist-success");
  }

  function initInstance(wrap) {
    var form = wrap.querySelector(".hfe-songlist-form");
    var fileInput = wrap.querySelector(".hfe-songlist-file-input");
    var submitButton = wrap.querySelector(".hfe-songlist-submit");
    var status = wrap.querySelector(".hfe-songlist-status");
    var message = wrap.querySelector(".hfe-songlist-message");
    var result = wrap.querySelector(".hfe-songlist-result");
    var album = wrap.querySelector('[data-hfe-field="album"]');
    var diskLine = wrap.querySelector(".hfe-songlist-disk");
    var disk = wrap.querySelector('[data-hfe-field="disk"]');
    var textArea = wrap.querySelector(".hfe-songlist-textarea");
    var tableBody = wrap.querySelector(".hfe-songlist-table-body");
    var nonceInput = wrap.querySelector('input[name="hfe_songlist_nonce"]');

    if (!form || !fileInput || !submitButton || !status || !message || !result || !album || !diskLine || !disk || !textArea || !tableBody) {
      return;
    }

    form.addEventListener("submit", function (event) {
      if (!ajaxUrl) {
        return;
      }

      event.preventDefault();

      var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (!file) {
        showMessage(message, "error", t("noFile", "Choose an HFE file first."));
        return;
      }

      var formData = new FormData();
      formData.set("action", action);
      formData.set("nonce", (nonceInput && nonceInput.value) || globalNonce);
      formData.set("hfe_songlist_file", file);

      submitButton.disabled = true;
      submitButton.classList.add("hfe-songlist-loading");
      status.textContent = t("processing", "Processing disk image. This can take a moment.");
      hideMessage(message);

      fetch(ajaxUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      })
        .then(function (response) {
          return response
            .json()
            .catch(function () {
              return null;
            })
            .then(function (json) {
              return { ok: response.ok, json: json };
            });
        })
        .then(function (packet) {
          if (!packet.json || packet.json.success !== true) {
            var errText =
              packet.json &&
              packet.json.data &&
              typeof packet.json.data.error === "string"
                ? packet.json.data.error
                : t("genericError", "Unable to process file.");

            showMessage(message, "error", errText);
            result.classList.add("hfe-songlist-hidden");
            return;
          }

          var data = packet.json.data || {};
          var songs = Array.isArray(data.songs) ? data.songs : [];

          album.textContent = typeof data.album_name === "string" && data.album_name !== "" ? data.album_name : "Unknown Album";
          if (typeof data.disk_kb === "number" && data.disk_kb > 0) {
            disk.textContent = String(data.disk_kb) + " KB";
            diskLine.classList.remove("hfe-songlist-hidden");
          } else {
            disk.textContent = "";
            diskLine.classList.add("hfe-songlist-hidden");
          }

          textArea.value = typeof data.songlist_text === "string" ? data.songlist_text : "";
          renderSongs(tableBody, songs);

          result.classList.remove("hfe-songlist-hidden");
          showMessage(message, "success", t("success", "Songlist generated successfully."));
        })
        .catch(function () {
          showMessage(message, "error", t("networkError", "Network error while uploading or processing."));
        })
        .finally(function () {
          submitButton.disabled = false;
          submitButton.classList.remove("hfe-songlist-loading");
          status.textContent = "";
        });
    });
  }

  document.querySelectorAll('.hfe-songlist-wrap[data-hfe-songlist="1"]').forEach(initInstance);
})();
