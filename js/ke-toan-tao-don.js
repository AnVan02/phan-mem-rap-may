document.addEventListener("DOMContentLoaded", function () {
  // --- KHỞI TẠO CÁC SỰ KIỆN TỔNG QUÁT ---

  // Hàm thêm nhóm cấu hình mới
  const btnsAddGroup = document.querySelectorAll(".btn-add-group");
  btnsAddGroup.forEach(btn => {
    btn.addEventListener("click", function () {
      const groups = document.querySelectorAll(".config-group");
      const newIndex = groups.length + 1;

      const firstGroup = document.querySelector(".config-group");
      if (firstGroup) {
        const newGroup = firstGroup.cloneNode(true);
        // Mặc định nhóm mới phải "mở ra" ngay (không bị thu gọn)
        newGroup.classList.remove("collapsed");
        newGroup.classList.add("active");
        delete newGroup.dataset.eventsAttached; // Xóa trạng thái đã gán sự kiện để nhóm mới được gán lại

        // Cập nhật số thứ tự và Tên nhóm
        const badge = newGroup.querySelector(".group-badge");
        if (badge) badge.textContent = "Cấu hình " + newIndex;

        const nameInput = newGroup.querySelector(".group-name-input");
        if (nameInput) nameInput.value = "Cấu hình " + newIndex;

        // Xoá trắng các dữ liệu nhập
        newGroup.querySelectorAll("input").forEach((input) => {
          if (input.type === "number") {
            if (
              input.classList.contains("qty-bubble") ||
              input.classList.contains("item-qty")
            ) {
              input.value = 1;
            }
          } else if (input.type === "text") {
            input.value = "";
          }
        });

        // Reset trạng thái toggle serial về mặc định "Có serial"
        newGroup.querySelectorAll(".config-row").forEach(r => {
          r.classList.remove("no-serial-mode");
          const btns = r.querySelectorAll(".smg-btn");
          btns.forEach(b => b.classList.remove("smg-active"));
          const hasBtn = r.querySelector('.smg-btn[data-val="1"]');
          if (hasBtn) hasBtn.classList.add("smg-active");
        });

        // Xoá các dòng linh kiện phụ (nếu có ở nhóm bị clone)
        newGroup.querySelectorAll(".multi-field-row").forEach(row => row.remove());
        newGroup.querySelectorAll(".config-field-qty div").forEach(div => {
          if (div.style.height === "38px") div.remove();
        });
        newGroup.querySelectorAll(".link-group").forEach(lg => {
          const row = lg.closest(".config-row");
          const mainField = row.querySelector(".config-field-main");
          const label = row.querySelector("label").textContent;
          const toggle = lg.querySelector(".serial-mode-toggle");
          lg.remove();

          const newLinkGroup = document.createElement("div");
          newLinkGroup.className = "link-group";
          newLinkGroup.style.cssText = "display: flex; align-items: center; gap: 12px; flex-wrap: wrap;";

          const btnLink = document.createElement("button");
          btnLink.type = "button";
          btnLink.className = "btn-link";
          btnLink.textContent = "+ Thêm loại " + label + " khác";
          newLinkGroup.appendChild(btnLink);

          if (toggle) newLinkGroup.appendChild(toggle);

          mainField.appendChild(newLinkGroup);
        });

        // Chèn vào trước nút bấm cuối trang
        const section = document.querySelector(".order-section:last-of-type");
        const bottomFooter = section.querySelector(".add-group-footer");

        if (bottomFooter) {
          section.insertBefore(newGroup, bottomFooter);
        } else {
          section.appendChild(newGroup);
        }

        attachGroupEvents(newGroup);
        updateFooterStats();
        saveFormState();

        // Cuộn tới nhóm vừa thêm để người dùng thấy ngay
        newGroup.scrollIntoView({ behavior: "smooth", block: "start" });

        // Focus vào ô tên nhóm để thao tác nhanh
        const newNameInput = newGroup.querySelector(".group-name-input");
        if (newNameInput) newNameInput.focus();
      }
    });
  });

  // Hàm gán các sự kiện cho một nhóm cấu hình
  function attachGroupEvents(group) {
    if (!group || group.dataset.eventsAttached) return;
    group.dataset.eventsAttached = "true";

    // 1. Xử lý nút "Thêm nhanh linh kiện" trong Header
    const btnQuickAdd = group.querySelector(".btn-quick-add");
    const quickMenu = group.querySelector(".quick-add-menu");

    if (btnQuickAdd && quickMenu) {
      btnQuickAdd.addEventListener("click", function (e) {
        e.stopPropagation();
        quickMenu.classList.toggle("active");
      });

      quickMenu.querySelectorAll(".quick-add-item").forEach((item) => {
        item.addEventListener("click", function (e) {
          e.stopPropagation();
          const type = this.getAttribute("data-type");

          // Tìm hàng linh kiện tương ứng trong Grid
          const rows = group.querySelectorAll(".config-row");
          let targetRow = null;
          rows.forEach((r) => {
            const labelText = r.querySelector("label")?.textContent.trim();
            if (labelText === type) {
              targetRow = r;
            }
          });
          if (targetRow) {
            const addBtn = targetRow.querySelector(".btn-link");
            if (addBtn) addBtn.click();
            quickMenu.classList.remove("active");
          }
        });
      });
    }

    // 2. Thay đổi số lượng dàn máy
    const qtyInput = group.querySelector(".qty-bubble");
    if (qtyInput) {
      qtyInput.addEventListener("input", () => {
        updateFooterStats();
        saveFormState();
      });
    }

    // 3. Sự kiện Đóng/Mở và Xóa sẽ do Global Click Listener xử lý để tránh xung đột
    if (qtyInput) {
      // qtyInput already has listener from above if it exists
    }

    // 5. Nhấn Enter trong ô tên linh kiện → thêm dòng mới + focus vào đó
    group.addEventListener("keydown", function (e) {
      if (e.key !== "Enter") return;
      const input = e.target;
      if (input.tagName !== "INPUT" || input.type !== "text") return;
      const row = input.closest(".config-row");
      if (!row) return;

      e.preventDefault();

      const mainField = row.querySelector(".config-field-main");
      const addBtn = mainField.querySelector(".btn-link:not(.danger)");
      if (!addBtn) return;

      addBtn.click();

      const allMultiRows = mainField.querySelectorAll(".multi-field-row");
      if (allMultiRows.length > 0) {
        const lastInput = allMultiRows[allMultiRows.length - 1].querySelector("input");
        if (lastInput) lastInput.focus();
      }
    });

    // 5b. Xử lý toggle "Có serial / Không serial"
    group.addEventListener("click", function (e) {
      const smgBtn = e.target.closest(".smg-btn");
      if (smgBtn) {
        e.stopPropagation();
        const toggle = smgBtn.closest(".serial-mode-toggle");
        toggle.querySelectorAll(".smg-btn").forEach(b => b.classList.remove("smg-active"));
        smgBtn.classList.add("smg-active");
        const row = smgBtn.closest(".config-row");
        if (row) row.classList.toggle("no-serial-mode", smgBtn.dataset.val === "0");
        saveFormState();
        return;
      }
    });

    // 6. Thêm/Xoá từng dòng linh kiện (Event Delegation)
    group.addEventListener("click", function (e) {
      const btn = e.target.closest(".btn-link");
      if (
        !btn ||
        btn.classList.contains("btn-add-group") ||
        btn.classList.contains("btn-quick-add")
      )
        return;

      const row = btn.closest(".config-row");
      if (!row) return;

      const mainField = row.querySelector(".config-field-main");
      const qtyField = row.querySelector(".config-field-qty");
      const inputWrapper = row.querySelector(".input-wrapper");
      const listId = inputWrapper.querySelector("input").getAttribute("list");
      const placeholder = inputWrapper
        .querySelector("input")
        .getAttribute("placeholder");

      if (btn.classList.contains("danger")) {
        // Xoá dòng phụ
        const multiRows = mainField.querySelectorAll(".multi-field-row");
        const qtyRows = qtyField.querySelectorAll("div");

        if (multiRows.length > 0) {
          multiRows[multiRows.length - 1].remove();
          if (qtyRows.length > 0) {
            qtyRows[qtyRows.length - 1].remove();
          }

          if (mainField.querySelectorAll(".multi-field-row").length === 0) {
            const linkGroup = mainField.querySelector(".link-group");
            const addText = linkGroup.querySelector(
              ".btn-link:not(.danger)",
            ).textContent;
            linkGroup.remove();

            const newBtn = document.createElement("button");
            newBtn.className = "btn-link";
            newBtn.textContent = addText;
            mainField.appendChild(newBtn);
          }
        }
      } else {
        // Thêm dòng linh kiện mới
        const newMultiRow = document.createElement("div");
        newMultiRow.className = "multi-field-row";
        newMultiRow.style.marginTop = "10px";
        newMultiRow.innerHTML = `
                      <div class="input-wrapper">
                          <input type="text" list="${listId}" placeholder="${placeholder}">
                          <button class="btn-input-toggle" type="button"></button>
                      </div>
                  `;

        let linkGroup = mainField.querySelector(".link-group");
        if (!linkGroup) {
          linkGroup = document.createElement("div");
          linkGroup.className = "link-group";
          linkGroup.innerHTML = `
                          <button class="btn-link">${btn.textContent}</button>
                          <span class="header-sep">|</span>
                          <button class="btn-link danger">Xoá</button>
                      `;
          mainField.appendChild(linkGroup);
          btn.remove();
        }

        mainField.insertBefore(newMultiRow, linkGroup);

        const newQtyRow = document.createElement("div");
        newQtyRow.style.height = "38px";
        newQtyRow.style.marginTop = "10px";
        newQtyRow.innerHTML = `<input type="number" value="1" class="item-qty">`;
        qtyField.appendChild(newQtyRow);
      }
      saveFormState();
    });
  }


  // Cập nhật lại số thứ tự Cấu hình
  function updateGroupIndices() {
    const groups = document.querySelectorAll(".config-group");
    groups.forEach((group, index) => {
      const badge = group.querySelector(".group-badge");
      const newName = "Cấu hình " + (index + 1);
      if (badge) badge.textContent = newName;

      const nameInput = group.querySelector(".group-name-input");
      if (
        nameInput &&
        (nameInput.value === "" || nameInput.value.startsWith("Cấu hình "))
      ) {
        nameInput.value = newName;
      }
    });
    updateFooterStats();
  }

  // Cập nhật tổng số PC và số nhóm ở footer
  function updateFooterStats() {
    const groups = document.querySelectorAll(".config-group");
    const statGroups = document.querySelector(
      ".stat-item:last-of-type .stat-value strong",
    );
    const statTotal = document.querySelector(
      ".stat-item:first-of-type .stat-value strong",
    );

    let totalQuantity = 0;
    let validGroupCount = 0;

    groups.forEach((group) => {
      let groupHasContent = false;
      const mainInputs = group.querySelectorAll('.config-field-main input[type="text"]');
      mainInputs.forEach((inp) => {
        if (inp.value.trim() !== "") {
          groupHasContent = true;
        }
      });

      if (groupHasContent) {
        validGroupCount++;
        const qtyInput = group.querySelector(".qty-bubble");
        const qty = parseInt(qtyInput ? qtyInput.value : 1) || 0;
        totalQuantity += qty;
      }
    });

    if (statGroups)
      statGroups.textContent = validGroupCount.toString().padStart(2, "0");
    if (statTotal) statTotal.textContent = totalQuantity.toString();
  }

  // --- LOGIC TỰ ĐỘNG LƯU (AUTO-SAVE) ---
  const STORAGE_KEY = "order_page_draft_v5";
  let saveTimeout = null;

  function debounceSave() {
    if (saveTimeout) clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => {
      saveFormState();
    }, 1000); // Lưu sau 1 giây không nhập liệu
  }

  function saveFormState() {
    const orderData = {
      customer: {
        name: document.getElementById("customer_name")?.value.trim() || "",
        phone: document.getElementById("customer_phone")?.value.trim() || "",
        code: document.getElementById("order_code")?.value.trim() || "",
      },
      groups: [],
    };

    document.querySelectorAll(".config-group").forEach((group) => {
      const groupData = {
        name:
          group
            .querySelector(".group-name-input")
            ?.value.replace(/\s+/g, " ")
            .trim() || "",
        quantity: parseInt(group.querySelector(".qty-bubble")?.value || 1),
        isCollapsed: group.classList.contains("collapsed"),
        rows: [],
      };

      group.querySelectorAll(".config-row").forEach((row) => {
        const label = row.querySelector("label")?.textContent || "";
        const mainInputs = Array.from(
          row.querySelectorAll('.config-field-main input[type="text"]'),
        ).map((i) => i.value.replace(/\s+/g, " ").trim());
        const qtyInputs = Array.from(
          row.querySelectorAll('.config-field-qty input[type="number"]'),
        ).map((i) => i.value.replace(/\s+/g, " ").trim());

        const toggle = row.querySelector('.serial-mode-toggle .smg-btn.smg-active');
        const hasSerial = toggle ? parseInt(toggle.dataset.val) : 1;
        groupData.rows.push({
          label,
          mainInputs,
          qtyInputs,
          hasSerial,
        });
      });
      orderData.groups.push(groupData);
    });

    localStorage.setItem(STORAGE_KEY, JSON.stringify(orderData));
  }

  function loadFormState() {
    const savedData = localStorage.getItem(STORAGE_KEY);
    if (!savedData) return;

    try {
      const data = JSON.parse(savedData);

      if (data.customer) {
        if (document.getElementById("customer_name")) document.getElementById("customer_name").value = data.customer.name;
        if (document.getElementById("customer_phone")) document.getElementById("customer_phone").value = data.customer.phone;
        if (document.getElementById("order_code")) document.getElementById("order_code").value = data.customer.code;
      }

      if (data.groups && data.groups.length > 0) {
        const container = document.querySelector(".order-section:last-of-type");
        const footerConfig = container.querySelector(".footer-config");

        document.querySelectorAll(".config-group").forEach((g) => g.remove());

        data.groups.forEach((groupData, gIndex) => {
          const groupDiv = document.createElement("div");
          groupDiv.className = `config-group ${groupData.isCollapsed ? "collapsed" : "active"}`;
          groupDiv.innerHTML = `
                          <div class="config-group-header">
                              <div class="header-left">
                                  <span class="group-badge">Cấu hình ${gIndex + 1}</span>
                                  <input type="text" class="group-name-input" value="${groupData.name || "Cấu hình " + (gIndex + 1)}">
                                  <span class="header-sep">|</span>
                                  <div class="quantity-control-header">
                                      <span>Số lượng:</span>
                                      <input type="number" class="qty-bubble" value="${groupData.quantity}">
                                  </div>
                              </div>
                          
                              <div class="header-right">
                                  <div class="btn-toggle-accordion"><i class="fa-solid fa-chevron-down"></i></div>
                                  <button class="btn-delete"><i class="fa-regular fa-trash-can"></i></button>
                              </div>
                          </div>
                          <div class="config-grid"></div>
                      `;

          const grid = groupDiv.querySelector(".config-grid");
          groupData.rows.forEach((rowData) => {
            const rowDiv = document.createElement("div");
            const hasSerial = rowData.hasSerial !== undefined ? parseInt(rowData.hasSerial) : 1;
            rowDiv.className = "config-row" + (hasSerial === 0 ? " no-serial-mode" : "");
            let mainContent = `
              <div class="row-label-wrap">
                <label>${rowData.label}</label>
              </div>
              <div class="config-field-main">`;
            rowData.mainInputs.forEach((val, i) => {
              const labelLower = rowData.label
                ? rowData.label.trim().toLowerCase()
                : "";
              const listId =
                labelLower === "nguồn" || labelLower === "nguon"
                  ? "pdu-list"
                  : `${labelLower}-list`;
              const placeholder = `Nhập tên ${labelLower}`;

              if (i === 0) {
                mainContent += `<div class="input-wrapper"><input type="text" list="${listId}" placeholder="${placeholder}" value="${val}"><button class="btn-input-toggle" type="button"></button></div>`;
              } else {
                mainContent += `<div class="multi-field-row" style="margin-top: 10px;"><div class="input-wrapper"><input type="text" list="${listId}" placeholder="${placeholder}" value="${val}"><button class="btn-input-toggle" type="button"><i class="fa-solid fa-chevron-down"></i></button></div></div>`;
              }
            });

            const toggleHtml = `
                <div class="serial-mode-toggle">
                  <button type="button" class="smg-btn${hasSerial === 1 ? ' smg-active' : ''}" data-val="1"><i class="fa-solid fa-barcode"></i> Có serial</button>
                  <button type="button" class="smg-btn${hasSerial === 0 ? ' smg-active' : ''}" data-val="0"><i class="fa-solid fa-ban"></i> Không serial</button>
                </div>`;

            if (
              rowData.mainInputs.length > 1 ||
              rowData.label.toLowerCase() === "nguồn"
            ) {
              // Nếu có nhiều dòng hoặc là phần Nguồn (thường có sẵn nút xóa trong mẫu)
              mainContent += `
                                      <div class="link-group" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                          <button class="btn-link" type="button">+ Thêm loại ${rowData.label} khác</button>
                                          <span class="header-sep">|</span>
                                          <button class="btn-link danger" type="button">Xoá</button>
                                          ${toggleHtml}
                                      </div>
                                  `;
            } else {
              mainContent += `
                  <div class="link-group" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                      <button class="btn-link" type="button">+ Thêm loại ${rowData.label} khác</button>
                      ${toggleHtml}
                  </div>
              `;
            }
            mainContent += `</div><div class="config-field-qty"><span class="qty-label">SỐ LƯỢNG</span>`;

            rowData.qtyInputs.forEach((qVal, i) => {
              if (i === 0) {
                mainContent += `<input type="number" value="${qVal}" class="item-qty">`;
              } else {
                mainContent += `<div style="height: 38px; margin-top: 10px;"><input type="number" value="${qVal}" class="item-qty"></div>`;
              }
            });
            mainContent += `</div>`;

            rowDiv.innerHTML = mainContent;
            grid.appendChild(rowDiv);
          });

          const bottomFooter = container.querySelector(".add-group-footer");
          if (bottomFooter) {
            container.insertBefore(groupDiv, bottomFooter);
          } else {
            container.appendChild(groupDiv);
          }
          attachGroupEvents(groupDiv);
        });
      }
    } catch (e) {
      console.error("Lỗi tải bản nháp:", e);
      localStorage.removeItem(STORAGE_KEY);
    }
  }
  // Nút tạo đơn hàng (Lưu vào SQL và Chuyển trang)
  const btnCreate = document.querySelector(".btn-primary");
  const btnDraft = document.querySelector(".btn-secondary");

  function submitOrder(nextPage, event) {
    // Lấy dữ liệu hiện tại từ Form
    const orderData = {
      customer: {
        name: document.getElementById("customer_name")?.value.trim() || "",
        phone: document.getElementById("customer_phone")?.value.trim() || "",
        code: document.getElementById("order_code")?.value.trim() || "",
        note: document.getElementById("note")?.value.trim() || "",
      },
      groups: [],
    };

    document.querySelectorAll(".config-group").forEach((group) => {
      const groupData = {
        name:
          group
            .querySelector(".group-name-input")
            ?.value.replace(/\s+/g, " ")
            .trim() || "",
        quantity: parseInt(group.querySelector(".qty-bubble")?.value || 1),
        rows: [],
      };

      let groupHasContent = false;
      group.querySelectorAll(".config-row").forEach((row) => {
        const label = row.querySelector("label")?.textContent || "";
        const mainInputs = Array.from(
          row.querySelectorAll('.config-field-main input[type="text"]'),
        ).map((i) => i.value.replace(/\s+/g, " ").trim());
        const qtyInputs = Array.from(
          row.querySelectorAll('.config-field-qty input[type="number"]'),
        ).map((i) => i.value.replace(/\s+/g, " ").trim());

        if (mainInputs.some((val) => val !== "")) {
          groupHasContent = true;
        }

        const toggle = row.querySelector('.serial-mode-toggle .smg-btn.smg-active');
        const hasSerial = toggle ? parseInt(toggle.dataset.val) : 1;
        groupData.rows.push({ label, mainInputs, qtyInputs, hasSerial });
      });

      if (groupHasContent) {
        orderData.groups.push(groupData);
      }
    });

    // --- VALIDATION ---
    if (!orderData.customer.name.trim()) {
      alert("Vui lòng nhập tên khách hàng hoặc tên đơn hàng!");
      document.getElementById("customer_name")?.focus();
      return;
    }

    let hasContent = false;
    orderData.groups.forEach((g) => {
      g.rows.forEach((r) => {
        if (r.mainInputs.some((val) => val.trim() !== "")) {
          hasContent = true;
        }
      });
    });

    if (!hasContent) {
      alert("Vui lòng nhập cấu hình linh kiện cho đơn hàng!");
      return;
    }

    const activeBtn = event ? (event.currentTarget || event.target) : btnCreate;
    const originalText = activeBtn.textContent;
    activeBtn.disabled = true;
    activeBtn.textContent = "Đang lưu...";

    fetch("luu-don-hang.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(orderData),
    })
      .then(async (res) => {
        const text = await res.text();
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error("Server trả về dữ liệu không hợp lệ.");
        }
      })
      .then((data) => {
        if (data.success) {
          localStorage.removeItem(STORAGE_KEY);
          window.location.href =
            nextPage +
            (nextPage.includes("?") ? "&" : "?") +
            "id=" +
            data.order_id;
        } else {
          alert("Lỗi: " + data.message);
          activeBtn.disabled = false;
          activeBtn.textContent = originalText;
        }
      })
      .catch((err) => {
        alert("Lỗi hệ thống: " + err.message);
        activeBtn.disabled = false;
        activeBtn.textContent = originalText;
      });
  }
  if (btnCreate) {
    btnCreate.onclick = (e) => submitOrder("nhap-serial.php", e);
  }

  if (btnDraft) {
    btnDraft.onclick = (e) => submitOrder("nhap-serial.php", e);
  }

  // Đóng nhanh tất cả menu khi click ra ngoài
  document.addEventListener("click", function () {
    document
      .querySelectorAll(".quick-add-menu")
      .forEach((m) => m.classList.remove("active"));
  });

  // Lắng nghe thay đổi input tổng quát
  document.addEventListener("input", (e) => {
    debounceSave();
    updateFooterStats();

    // Nếu thay đổi số lượng, cập nhật nhãn hiển thị tổng
    if (e.target.classList.contains("item-qty") || e.target.classList.contains("qty-bubble")) {
      updateRowTotals(e.target.closest(".config-group"));
    }

    // Bóc số ID từ URL dán vào ô linh kiện
    if (e.target.matches('input[type="text"]')) {
      const row = e.target.closest(".config-row");
      const urlExtractTypes = ["CPU", "MAIN", "MAINBOARD", "VGA", "SSD", "PSU", "CASE", "WIN", "FAN", "RAM"];
      if (row && urlExtractTypes.includes(row.querySelector("label")?.textContent.trim().toUpperCase())) {
        const wrapper = e.target.closest(".input-wrapper");
        let hint = wrapper.querySelector(".url-id-hint");
        const match = e.target.value.match(/[?&]id=(\d+)/i);
        if (match) {
          const idNum = match[1];
          const url = e.target.value.trim();
          // Thay nội dung ô input bằng số ID luôn
          e.target.value = idNum;
          if (!hint) {
            hint = document.createElement("div");
            hint.className = "url-id-hint";
            wrapper.appendChild(hint);
          }
        } else if (hint) {
          hint.remove();
        }
      }
    }
  });

  // Khởi tạo
  loadFormState();
  document.querySelectorAll(".config-group").forEach((group) => {
    attachGroupEvents(group);
  });

  // Cải tiến: "Bấm vào thì nhả dữ liệu" ngay lập tức
  // D. Cập nhật hiển thị Tổng Số Lượng (Realtime feedback)

  function updateRowTotals(group) {
    if (!group) return;
    const groupQty = parseInt(group.querySelector(".qty-bubble")?.value || 1);
    group.querySelectorAll(".config-row").forEach((row) => {
      const qtyInputs = row.querySelectorAll(".item-qty");
      qtyInputs.forEach((input) => {
        const total = parseInt(input.value || 0);
        const groupTotal = total * groupQty;

        // Tìm hoặc tạo label hiển thị tổng
        let totalDisplay =
          input.parentElement.querySelector(".item-total-hint");
        if (!totalDisplay) {
          totalDisplay = document.createElement("span");
          totalDisplay.className = "item-total-hint";
          totalDisplay.style.fontSize = "12px";
          totalDisplay.style.color = "#3b82f6"; // Blue
          totalDisplay.style.display = "block";
          totalDisplay.style.marginTop = "2px";
          totalDisplay.style.fontWeight = "600";
          input.parentElement.appendChild(totalDisplay);
        }
        // totalDisplay.textContent = 'Tổng: ' + groupTotal;
      });
    });
  }

  // Chạy lần đầu sau khi load
  setTimeout(() => {
    document
      .querySelectorAll(".config-group")
      .forEach((group) => updateRowTotals(group));
  }, 500);

  document.addEventListener("click", function (e) {
    // A. Xử lý Nhóm Cấu hình (Accordion - Nhả ra nhả vào)
    const header = e.target.closest(".config-group-header");
    if (
      header &&
      !e.target.closest("input") &&
      !e.target.closest(".btn-delete")
    ) {
      const group = header.closest(".config-group");
      if (group) {
        group.classList.toggle("collapsed");
        group.classList.toggle("active");
        saveFormState();
        return;
      }
    }
    // xử lý dropdown nhã ra hiển thị nhã ra linh kiện 
    const toggleBtn = e.target.closest(".btn-input-toggle");
    const input = toggleBtn
      ? toggleBtn.parentElement.querySelector("input")
      : e.target.tagName === "INPUT" && e.target.getAttribute("list")
        ? e.target
        : null;

    if (input && input.getAttribute("list")) {
      input.focus();

      // Xóa tạm placeholder
      const oldPlh = input.placeholder;
      input.placeholder = "";

      if (typeof input.showPicker === "function") {
        try {
          input.showPicker();
        } catch (err) {
          forceTrigger(input);
        }
      } else {
        forceTrigger(input);
      }

      setTimeout(() => {
        if (!input.value) input.placeholder = oldPlh;
      }, 400);
    }

    // C. Xử lý nút Xóa (Trash)
    const btnDel = e.target.closest(".btn-delete");
    if (btnDel) {
      const group = btnDel.closest(".config-group");
      if (group && confirm("Bạn có chắc chắn muốn xóa cấu hình này?")) {
        group.remove();
        updateGroupIndices();
        saveFormState();
      }
    }
  });

  function forceTrigger(input) {
    // Mẹo ép trình duyệt hiện datalist: đổi sang khoảng trắng rồi trả lại sau 1ms
    const val = input.value;
    input.value = " ";
    setTimeout(() => {
      input.value = val;
    }, 1);
  }

  document.addEventListener("focusin", function (e) {
    if (e.target.tagName === "INPUT" && e.target.getAttribute("list")) {
      e.target.setAttribute("data-plh", e.target.placeholder);
      e.target.placeholder = "";
    }
  });

  document.addEventListener("focusout", function (e) {
    if (e.target.tagName === "INPUT" && e.target.getAttribute("list")) {
      if (e.target.getAttribute("data-plh")) {
        e.target.placeholder = e.target.getAttribute("data-plh");
      }
    }
  });

  updateFooterStats();
});
