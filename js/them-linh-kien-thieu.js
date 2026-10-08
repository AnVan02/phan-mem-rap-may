document.addEventListener('DOMContentLoaded', function () {
    // 0. Khởi tạo 2 Tab chính (Danh sách đơn hàng / Thêm linh kiện thiếu)
    initMainTabs();

    // 1. Quản lý Bảng Đơn hàng Full (Tab 1)
    initFullOrdersTable();

    // 2. Quản lý Đơn hàng Sidebar (Search, Filter, Pagination)
    initSidebarOrders();

    // 3. Quản lý Tab Filter & Grid Máy (Tabs, Pagination, Selection)
    initMachinesGrid();

    // 4. Close bulk dropdown on click outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.bulk-dropdown-wrap')) {
            hideBulkMenu();
        }
    });
});

/* ==========================================
   0. MAIN TOP TABS MANAGEMENT
   ========================================== */
let currentMainTab = 'missing';

function initMainTabs() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    const idParam = urlParams.get('id');

    // Nếu có query param tab thì ưu tiên, nếu có id thì mở tab missing
    if (tabParam === 'orders' || tabParam === 'missing') {
        currentMainTab = tabParam;
    } else if (idParam) {
        currentMainTab = 'missing';
    } else {
        const savedTab = sessionStorage.getItem('lk_main_tab');
        if (savedTab) currentMainTab = savedTab;
    }

    switchMainTab(currentMainTab, false);
}

function switchMainTab(tabName, updateUrl = true) {
    currentMainTab = tabName;
    sessionStorage.setItem('lk_main_tab', tabName);

    // Update Tab Buttons
    const btnOrders = document.getElementById('btnTabOrders');
    const btnMissing = document.getElementById('btnTabMissing');
    if (btnOrders) btnOrders.classList.toggle('active', tabName === 'orders');
    if (btnMissing) btnMissing.classList.toggle('active', tabName === 'missing');

    // Update Sections Display
    const secOrders = document.getElementById('mainSectionOrders');
    const secMissing = document.getElementById('mainSectionMissing');
    if (secOrders) secOrders.style.display = (tabName === 'orders') ? 'block' : 'none';
    if (secMissing) secMissing.style.display = (tabName === 'missing') ? 'block' : 'none';

    if (updateUrl) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
    }

    if (tabName === 'orders') {
        renderFullOrdersTable();
    } else {
        renderSidebarOrders();
        renderMachinesGrid();
    }
}

function openAddForOrder(orderId, compType) {
    window.location.href = `them-linh-kien-thieu.php?id=${orderId}&type=${compType}&tab=missing`;
}

/* ==========================================
   FULL ORDERS TABLE (TAB 1)
   ========================================== */
let fullOrdersCurrentPage = 1;
const FULL_ORDERS_PER_PAGE = 10;

function initFullOrdersTable() {
    const searchInput = document.getElementById('fullOrderSearchInput');
    const statusSelect = document.getElementById('fullOrderStatusFilter');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            fullOrdersCurrentPage = 1;
            renderFullOrdersTable();
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            fullOrdersCurrentPage = 1;
            renderFullOrdersTable();
        });
    }

    renderFullOrdersTable();
}

function getFilteredFullOrders() {
    const searchInput = document.getElementById('fullOrderSearchInput');
    const statusSelect = document.getElementById('fullOrderStatusFilter');
    const searchText = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const statusVal = statusSelect ? statusSelect.value : 'all';

    const allRows = Array.from(document.querySelectorAll('.full-order-tr'));
    return allRows.filter(row => {
        const matchesSearch = row.dataset.search ? row.dataset.search.includes(searchText) : true;
        const missingCount = parseInt(row.dataset.missingCount || '0', 10);
        let matchesStatus = true;
        if (statusVal === 'missing') {
            matchesStatus = missingCount > 0;
        } else if (statusVal === 'full') {
            matchesStatus = missingCount === 0;
        }
        return matchesSearch && matchesStatus;
    });
}

function renderFullOrdersTable() {
    const allRows = Array.from(document.querySelectorAll('.full-order-tr'));
    const filteredRows = getFilteredFullOrders();
    const totalFiltered = filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / FULL_ORDERS_PER_PAGE));

    if (fullOrdersCurrentPage > totalPages) fullOrdersCurrentPage = totalPages;
    if (fullOrdersCurrentPage < 1) fullOrdersCurrentPage = 1;

    const startIndex = (fullOrdersCurrentPage - 1) * FULL_ORDERS_PER_PAGE;
    const endIndex = startIndex + FULL_ORDERS_PER_PAGE;

    allRows.forEach(r => r.style.display = 'none');
    filteredRows.slice(startIndex, endIndex).forEach(r => r.style.display = '');

    // Empty message row
    const noResultRow = document.getElementById('fullTableNoResult');
    if (noResultRow) {
        noResultRow.style.display = (totalFiltered === 0) ? '' : 'none';
    }

    // Pagination controls
    renderPaginationButtons(
        'fullOrdersPaginationControls',
        fullOrdersCurrentPage,
        totalPages,
        page => {
            fullOrdersCurrentPage = page;
            renderFullOrdersTable();
        }
    );

    // Info text
    const infoText = document.getElementById('fullOrdersPaginationInfo');
    if (infoText) {
        if (totalFiltered === 0) {
            infoText.textContent = 'Không tìm thấy đơn hàng nào';
        } else {
            const startNum = startIndex + 1;
            const endNum = Math.min(endIndex, totalFiltered);
            infoText.textContent = `Hiển thị ${startNum} - ${endNum} trong ${totalFiltered} đơn hàng`;
        }
    }
}

function initSidebarOrders() {
    const searchInput = document.getElementById('orderSearchInput');
    const toggleShowAll = document.getElementById('toggleShowAll');

    if (searchInput) {
        const savedSearch = sessionStorage.getItem('lk_search_text');
        if (savedSearch !== null) {
            searchInput.value = savedSearch;
        }
        searchInput.addEventListener('input', function () {
            sessionStorage.setItem('lk_search_text', searchInput.value);
            sidebarCurrentPage = 1;
            renderSidebarOrders();
        });
    }

    if (toggleShowAll) {
        const savedToggle = localStorage.getItem('lk_toggle_show_all');
        if (savedToggle !== null) {
            toggleShowAll.checked = savedToggle === '1';
        }
        toggleShowAll.addEventListener('change', function () {
            localStorage.setItem('lk_toggle_show_all', toggleShowAll.checked ? '1' : '0');
            sidebarCurrentPage = 1;
            renderSidebarOrders();
        });
    }

    renderSidebarOrders();
}

function getFilteredOrders() {
    const searchInput = document.getElementById('orderSearchInput');
    const toggleShowAll = document.getElementById('toggleShowAll');
    const searchText = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const showAll = toggleShowAll ? toggleShowAll.checked : true;

    const allItems = Array.from(document.querySelectorAll('.order-item'));
    return allItems.filter(item => {
        const matchesSearch = item.dataset.search ? item.dataset.search.includes(searchText) : true;
        const missingCount = parseInt(item.dataset.missingCount || '0', 10);
        const matchesFilter = showAll || missingCount > 0;
        return matchesSearch && matchesFilter;
    });
}

function renderSidebarOrders() {
    const allItems = Array.from(document.querySelectorAll('.order-item'));
    const filteredItems = getFilteredOrders();
    const totalFiltered = filteredItems.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / SIDEBAR_ITEMS_PER_PAGE));

    if (sidebarCurrentPage > totalPages) sidebarCurrentPage = totalPages;
    if (sidebarCurrentPage < 1) sidebarCurrentPage = 1;

    // Direct visibility
    const startIndex = (sidebarCurrentPage - 1) * SIDEBAR_ITEMS_PER_PAGE;
    const endIndex = startIndex + SIDEBAR_ITEMS_PER_PAGE;

    allItems.forEach(item => {
        item.style.display = 'none';
    });

    filteredItems.slice(startIndex, endIndex).forEach(item => {
        item.style.display = 'flex';
    });

    // Update count badge
    const badge = document.getElementById('sidebarOrdersBadge');
    if (badge) badge.textContent = totalFiltered;

    // Render pagination buttons
    renderPaginationButtons(
        'sidebarPaginationControls',
        sidebarCurrentPage,
        totalPages,
        page => {
            sidebarCurrentPage = page;
            renderSidebarOrders();
        }
    );

    // Render info text
    const infoText = document.getElementById('sidebarPaginationInfo');
    if (infoText) {
        if (totalFiltered === 0) {
            infoText.textContent = 'Không có đơn hàng';
        } else {
            const startNum = startIndex + 1;
            const endNum = Math.min(endIndex, totalFiltered);
            infoText.textContent = `Hiển thị ${startNum} - ${endNum} trong ${totalFiltered} đơn hàng`;
        }
    }
}

/* ==========================================
   2. MACHINE GRID TABS & PAGINATION
   ========================================== */
let activeMachineTab = 'all';
let gridCurrentPage = 1;
const GRID_ITEMS_PER_PAGE = 8;

function initMachinesGrid() {
    const savedTab = sessionStorage.getItem('lk_active_tab');
    if (savedTab) {
        activeMachineTab = savedTab;
        const tabBtns = document.querySelectorAll('.tabs-nav-list .tab-item');
        tabBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === savedTab);
        });
    }

    renderMachinesGrid();

    const grid = document.getElementById('machinesGrid');
    if (grid) {
        grid.addEventListener('click', function (e) {
            const card = e.target.closest('.machine-card');
            if (!card) return;

            // Bỏ qua nếu bấm vào link, button hoặc checkbox trực tiếp
            if (e.target.closest('a, button, input, label, .checkbox-container')) {
                return;
            }

            const cb = card.querySelector('.machine-checkbox');
            if (cb) {
                cb.checked = !cb.checked;
                card.classList.toggle('card-selected', cb.checked);
            }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('machine-checkbox')) {
            const card = e.target.closest('.machine-card');
            if (card) {
                card.classList.toggle('card-selected', e.target.checked);
            }
        }
    });
}

function switchMachineTab(tabName) {
    activeMachineTab = tabName;
    sessionStorage.setItem('lk_active_tab', tabName);
    gridCurrentPage = 1;

    // Update active tab UI
    const tabBtns = document.querySelectorAll('.tabs-nav-list .tab-item');
    tabBtns.forEach(btn => {
        if (btn.dataset.tab === tabName) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    renderMachinesGrid();
}

function getFilteredMachines() {
    const cards = Array.from(document.querySelectorAll('.machine-card'));
    return cards.filter(card => {
        const cardStatus = card.dataset.status;
        if (activeMachineTab === 'all') return true;
        return cardStatus === activeMachineTab;
    });
}

function renderMachinesGrid() {
    const allCards = Array.from(document.querySelectorAll('.machine-card'));
    const filteredCards = getFilteredMachines();
    const totalFiltered = filteredCards.length;
    const totalPages = Math.max(1, Math.ceil(totalFiltered / GRID_ITEMS_PER_PAGE));

    if (gridCurrentPage > totalPages) gridCurrentPage = totalPages;
    if (gridCurrentPage < 1) gridCurrentPage = 1;

    const startIndex = (gridCurrentPage - 1) * GRID_ITEMS_PER_PAGE;
    const endIndex = startIndex + GRID_ITEMS_PER_PAGE;

    allCards.forEach(card => {
        card.style.display = 'none';
    });

    filteredCards.slice(startIndex, endIndex).forEach(card => {
        card.style.display = 'flex';
    });

    // Render grid pagination controls
    renderPaginationButtons(
        'gridPaginationControls',
        gridCurrentPage,
        totalPages,
        page => {
            gridCurrentPage = page;
            renderMachinesGrid();
        }
    );

    // Info text
    const infoText = document.getElementById('gridPaginationInfo');
    if (infoText) {
        if (totalFiltered === 0) {
            infoText.textContent = 'Không tìm thấy máy';
        } else {
            const startNum = startIndex + 1;
            const endNum = Math.min(endIndex, totalFiltered);
            infoText.textContent = `Hiển thị ${startNum} - ${endNum} trong ${totalFiltered} máy`;
        }
    }
}

/* ==========================================
   GENERIC PAGINATION BUTTONS RENDERER
   ========================================== */
function renderPaginationButtons(containerId, currentPage, totalPages, onPageClick) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';
    if (totalPages <= 1) return;

    // Nút Prev «
    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'page-btn';
    prevBtn.innerHTML = '«';
    prevBtn.disabled = currentPage === 1;
    prevBtn.onclick = () => onPageClick(currentPage - 1);
    container.appendChild(prevBtn);

    // Page Numbers algorithm (e.g. 1 2 3 ... 15)
    let pages = [];
    if (totalPages <= 7) {
        for (let i = 1; i <= totalPages; i++) pages.push(i);
    } else {
        pages.push(1);
        if (currentPage > 3) pages.push('...');

        let start = Math.max(2, currentPage - 1);
        let end = Math.min(totalPages - 1, currentPage + 1);

        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        if (currentPage < totalPages - 2) pages.push('...');
        pages.push(totalPages);
    }

    pages.forEach(p => {
        if (p === '...') {
            const span = document.createElement('span');
            span.className = 'page-ellipsis';
            span.textContent = '...';
            container.appendChild(span);
        } else {
            const pageBtn = document.createElement('button');
            pageBtn.type = 'button';
            pageBtn.className = `page-btn ${p === currentPage ? 'active' : ''}`;
            pageBtn.textContent = p;
            pageBtn.onclick = () => onPageClick(p);
            container.appendChild(pageBtn);
        }
    });

    // Nút Next »
    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'page-btn';
    nextBtn.innerHTML = '»';
    nextBtn.disabled = currentPage === totalPages;
    nextBtn.onclick = () => onPageClick(currentPage + 1);
    container.appendChild(nextBtn);
}

/* ==========================================
   NAVIGATION & SELECTION ACTIONS
   ========================================== */
function selectOrder(orderId, currentType) {
    window.location.href = `them-linh-kien-thieu.php?id=${orderId}&type=${currentType}`;
}

function changeComponentType(newType) {
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id') || '';

    if (id) {
        window.location.href = `them-linh-kien-thieu.php?id=${id}&type=${newType}`;
    } else {
        window.location.href = `them-linh-kien-thieu.php?type=${newType}`;
    }
}

function toggleSelectAllMachines(isChecked) {
    const checkboxes = document.querySelectorAll('.machine-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
        const card = cb.closest('.machine-card');
        if (card) {
            card.classList.toggle('card-selected', isChecked);
        }
    });
}

function selectAllMissing(status) {
    const checkboxes = document.querySelectorAll('.machine-checkbox');
    if (checkboxes.length === 0) {
        showToast('Không có máy nào trong danh sách!', 'error');
        return;
    }
    checkboxes.forEach(cb => {
        const card = cb.closest('.machine-card');
        if (!card) return;
        if (status) {
            // Chỉ chọn máy bị thiếu linh kiện
            if (card.dataset.status === 'missing') {
                cb.checked = true;
                card.classList.add('card-selected');
            } else {
                cb.checked = false;
                card.classList.remove('card-selected');
            }
        } else {
            cb.checked = false;
            card.classList.remove('card-selected');
        }
    });

    showToast(status ? 'Đã chọn tất cả máy thiếu linh kiện' : 'Đã bỏ chọn tất cả máy', 'success');
}

function toggleBulkMenu(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('bulkMenuDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

function hideBulkMenu() {
    const dropdown = document.getElementById('bulkMenuDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
    }
}

/* ==========================================
   SUBMIT AJAX COMPONENT ADDITION
   ========================================== */
function submitAddComponent(event) {
    event.preventDefault();

    const orderId = document.getElementById('submit_order_id').value;
    const compType = document.getElementById('submit_comp_type').value;
    const compNameInput = document.getElementById('comp_name');
    const compName = compNameInput ? compNameInput.value.trim() : '';
    const btnSubmit = document.getElementById('btnSubmitAdd');

    if (!orderId || orderId <= 0) {
        showToast('Không xác định được ID đơn hàng.', 'error');
        return;
    }

    if (!compType) {
        showToast('Không xác định được loại linh kiện.', 'error');
        return;
    }

    if (!compName) {
        showToast('Vui lòng nhập tên hoặc mã linh kiện.', 'error');
        return;
    }

    // Thu thập danh sách máy tính được chọn
    const selectedMachines = [];
    const checkboxes = document.querySelectorAll('.machine-checkbox:checked');

    checkboxes.forEach(cb => {
        selectedMachines.push({
            so_may: parseInt(cb.dataset.soMay, 10),
            ten_cauhinh: cb.dataset.tenCauhinh
        });
    });

    if (selectedMachines.length === 0) {
        // Tự động chọn tất cả máy missing nếu người dùng chưa tích chọn
        const missingCards = document.querySelectorAll('.machine-card[data-status="missing"]');
        missingCards.forEach(card => {
            const cb = card.querySelector('.machine-checkbox');
            if (cb) {
                cb.checked = true;
                selectedMachines.push({
                    so_may: parseInt(cb.dataset.soMay, 10),
                    ten_cauhinh: cb.dataset.tenCauhinh
                });
            }
        });

        if (selectedMachines.length === 0) {
            showToast('Vui lòng tích chọn ít nhất 1 máy bị thiếu để thêm linh kiện.', 'error');
            return;
        }

        showToast('Tự động chọn tất cả máy thiếu linh kiện để thực hiện.', 'success');
    }

    // Progress state
    btnSubmit.disabled = true;
    const originalText = btnSubmit.innerHTML;
    btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang xử lý...';

    fetch('them-linh-kien-thieu.php?action=add_component', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            id_donhang: parseInt(orderId, 10),
            type: compType,
            comp_name: compName,
            machines: selectedMachines
        })
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message || 'Thành công!', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1200);
            } else {
                showToast(res.message || 'Có lỗi xảy ra khi thêm.', 'error');
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = originalText;
            }
        })
        .catch(err => {
            showToast('Lỗi kết nối máy chủ.', 'error');
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = originalText;
        });
}

/* ==========================================
   TOAST NOTIFICATION
   ========================================== */
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let iconClass = 'fa-circle-check';
    if (type === 'error') iconClass = 'fa-circle-xmark';

    toast.innerHTML = `
        <i class="fa-solid ${iconClass} toast-icon"></i>
        <div class="toast-message">${message}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideUp 0.3s reverse forwards';
        toast.addEventListener('animationend', () => {
            toast.remove();
        });
    }, 3500);
}
