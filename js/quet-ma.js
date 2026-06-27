// CHỨC NĂNG QUÉT MÃ VÀ LƯU SERIAL - BẢN CAO CẤP V8
(function () {
    console.log('Hệ thống Quét mã (Premium V8) đã nạp!');

    const sounds = {
        success: document.getElementById('sound-success'),
        error: document.getElementById('sound-error')
    };

    function playSound(type) {
        // Âm thanh đã bị vô hiệu hoá theo yêu cầu
    }

    function showToast(message, type = 'success', duration = 600000) {
        const toast = document.getElementById('scan-toast');
        if (!toast) return;
        toast.innerHTML = message.replace(/\n/g, '<br>');
        toast.className = `scan-toast show ${type}`;
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(() => toast.classList.remove('show'), duration);
    }

    function showFeedback(input, message, type) {
        let parent = input.closest('.comp-input-side');
        if (!parent) return;

        let feedback = parent.querySelector('.input-feedback-msg');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'input-feedback-msg';
            parent.appendChild(feedback);
        }

        feedback.innerHTML = message.replace(/\n/g, '<br>');
        feedback.className = `input-feedback-msg show ${type}`;

        // Đã bỏ tự động ẩn thông báo theo yêu cầu (Để hiển thị lâu luôn)
        clearTimeout(input.feedbackTimer);
    }

    // --- LOCAL STORAGE (ĐÃ VÔ HIỆU HÓA THEO YÊU CẦU) ---
    function getStorageKey() {
        const orderId = typeof currentOrderId !== 'undefined' ? currentOrderId : 'none';
        const configName = typeof currentConfigPure !== 'undefined' ? currentConfigPure : 'none';
        const machineIdx = typeof currentMachineIdx !== 'undefined' ? currentMachineIdx : 'none';
        return `RAPMAY_DRAFT_${orderId}_${configName}_${machineIdx}`;
    }

    function saveDraftToStorage() {
        // Vô hiệu hóa tính năng lưu nháp
    }

    function loadDraftFromStorage() {
        // Vô hiệu hóa tính năng nạp nháp
    }

    function clearDraftStorage() {
        localStorage.removeItem(getStorageKey());
    }

    function updateConfirmButton() {
        const confirmBtn = document.getElementById('btnConfirm');
        if (!confirmBtn) return;

        confirmBtn.disabled = false;
        confirmBtn.style.opacity = '1';
        confirmBtn.style.cursor = 'pointer';
    }


    function extractSerialFromUrl(url) {
        if (!url) return '';
        url = url.trim();

        // 1. Nếu có ?id= hoặc &id=, lấy giá trị của tham số id (chữ, số, gạch ngang, gạch dưới)
        const idMatch = url.match(/[?&]id=([a-zA-Z0-9_-]+)/i);
        if (idMatch) {
            let val = idMatch[1];
            // Bóc tách phần trước dấu gạch dưới (_) nếu có
            if (val.includes('_')) {
                val = val.split('_')[0];
            }
            return val;
        }

        // 2. Nếu có ?serial= hoặc &serial= (vd: baohanh.coolerplus.com.vn/tracuu?code=atxp&serial=2026060005283)
        const serialMatch = url.match(/[?&]serial=([a-zA-Z0-9_-]+)/i);
        if (serialMatch) {
            let val = serialMatch[1];
            // Bóc tách phần trước dấu gạch dưới (_) nếu có
            if (val.includes('_')) {
                val = val.split('_')[0];
            }
            return val;
        }

        // 3. Nếu không có id= hay serial=, lấy chuỗi số cuối cùng trong URL (bỏ phần query parameters)
        const cleanUrl = url.split('?')[0];
        const matches = cleanUrl.match(/\d+/g);
        if (matches && matches.length > 0) {
            return matches[matches.length - 1];
        }

        // 4. Nếu vẫn không có, kiểm tra toàn bộ URL xem có chứa mã dạng "XXXXX_XXXXX" không
        // Nếu có, bóc tách phần trước dấu gạch dưới
        if (url.includes('_')) {
            const part = url.split('_')[0].trim();
            if (part && part.length > 0) {
                return part;
            }
        }

        return url;
    }


    const controllers = new Map();

    async function validateSerial(input) {
        let val = input.value.trim();
        if (val.startsWith('http') || val.startsWith('www') || val.includes('/') || val.includes('\\') || val.includes('?')) {
            const extracted = extractSerialFromUrl(val);
            if (extracted) {
                input.value = extracted;
                val = extracted;
            }
        }
        const wrapper = input.closest('.input-wrapper');
        const icon = wrapper ? wrapper.querySelector('.status-indicator') : null;

        // Cancel previous request for this input if exists
        const inputId = input.getAttribute('data-id-ct') || input.name || Math.random();
        if (controllers.has(inputId)) {
            controllers.get(inputId).abort();
            controllers.delete(inputId);
        }

        if (val === '') {
            input.classList.remove('is-valid', 'is-invalid', 'is-loading');
            if (icon) icon.innerHTML = '';
            input.dataset.lastChecked = '';
            return false;
        }

        const type = input.getAttribute('data-loai') || '';
        const typeUpper = type.toUpperCase();

        // [MỚI] KHÔNG KIỂM TRA IMEI/IMER
        if (typeUpper === 'IMEI' || typeUpper === 'IMER') {
            input.classList.remove('is-loading', 'is-invalid');
            input.classList.add('is-valid');
            if (icon) {
                icon.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#22c55e"></i>';
                icon.className = 'status-indicator success anim-pop';
            }
            showFeedback(input, '✓ Hợp lệ (Không kiểm tra)', 'success');
            showToast(`<i class="fa-solid fa-barcode"></i> IMEI: <b>${val}</b>`, 'success', 3000);
            updateConfirmButton();
            return true;
        }

        if (input.dataset.lastChecked === val && (input.classList.contains('is-valid') || input.classList.contains('is-invalid'))) {
            return input.classList.contains('is-valid');
        }

        // --- PHẦN KIỂM TRA TRÙNG LẶP ---
        const allIns = document.querySelectorAll('.scan-input');
        let isDup = false;
        const typeUpperDup = (input.getAttribute('data-loai') || '').toUpperCase();
        if (typeUpperDup !== 'WIN' && typeUpperDup !== 'CASE') {
            allIns.forEach(other => {
                if (other !== input && other.value.trim().toUpperCase() === val.toUpperCase() && val !== '') {
                    if (other.getAttribute('data-loai') === input.getAttribute('data-loai')) {
                        isDup = true;
                    }
                }
            });
        }

        if (isDup) {
            input.classList.remove('is-valid', 'is-loading');
            input.classList.add('is-invalid');
            if (icon) {
                icon.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="color:#ef4444"></i>';
                icon.className = 'status-indicator error anim-shake';
            }
            showFeedback(input, 'Lỗi: Mã linh kiện này đã được nhập ở ô khác!', 'error');
            showToast(`<i class="fa-solid fa-triangle-exclamation"></i> Trùng mã: <b>${val}</b>`, 'error', 3000);
            return false;
        }

        input.classList.remove('is-valid', 'is-invalid');
        input.classList.add('is-loading');
        if (icon) icon.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        const name = input.getAttribute('data-name');
        const id_ct = input.getAttribute('data-id-ct');
        const orderId = typeof currentOrderId !== 'undefined' ? currentOrderId : null;

        const controller = new AbortController();
        controllers.set(inputId, controller);

        try {
            const resp = await fetch('kiemtra.php?ajax=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                signal: controller.signal,
                body: JSON.stringify({
                    id_donhang: orderId,
                    so_serial: val,
                    loai_linhkien: type,
                    ten_linhkien: name,
                    id_ct: id_ct,
                    config_name: typeof currentConfigPure !== 'undefined' ? currentConfigPure : '',
                    machine_idx: typeof currentMachineIdx !== 'undefined' ? currentMachineIdx : 1
                })
            });
            const res = await resp.json();

            input.classList.remove('is-loading');
            input.dataset.lastChecked = val;
            if (res.status === 'match') {
                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
                if (res.id_ct) {
                    input.setAttribute('data-id-ct', res.id_ct);
                }
                if (icon) {
                    icon.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#22c55e"></i>';
                    icon.className = 'status-indicator success anim-pop';
                }
                showFeedback(input, res.message, 'success');
                showToast(`<i class="fa-solid fa-circle-check"></i> <b>${val}</b><br><span style="font-size:12px;opacity:.85">${res.message || ''}</span>`, 'success', 3000);
                return true;
            } else {
                input.classList.remove('is-valid');
                input.classList.add('is-invalid');
                if (icon) {
                    icon.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color:#ef4444"></i>';
                    icon.className = 'status-indicator error anim-shake';
                }
                showFeedback(input, res.message, 'error');
                showToast(`<i class="fa-solid fa-circle-xmark"></i> <b>${val}</b><br><span style="font-size:12px;opacity:.85">${res.message || ''}</span>`, 'error', 3000);
                return false;
            }
        } catch (e) {
            if (e.name === 'AbortError') return;
            input.classList.remove('is-loading');
            console.error('Lỗi kiểm tra:', e);
            return false;
        } finally {
            if (controllers.get(inputId) === controller) {
                controllers.delete(inputId);
            }
            updateConfirmButton();
        }
    }
    function initScanSystem() {
        const confirmBtn = document.getElementById('btnConfirm');
        const allIns = document.querySelectorAll('.scan-input');
        if (!confirmBtn) return;

        // Luôn xóa dữ liệu nháp khi vào máy
        clearDraftStorage();
        allIns.forEach(input => {
            if (input.value.trim() === '') {
                input.classList.remove('is-valid', 'is-invalid', 'is-loading');
                const wrapper = input.closest('.input-wrapper');
                const icon = wrapper ? wrapper.querySelector('.status-indicator') : null;
                if (icon) icon.innerHTML = '';
            }
        });

        updateConfirmButton();

        // --- TÍCH HỢP QUÉT MÃ QUA ĐIỆN THOẠI CAMERA ---
        const scannerModal = document.getElementById('scanner-ui-modal');
        const cameraInput = document.getElementById('scan-file-input');
        const previewArea = document.getElementById('modalPreviewArea');
        const previewImg = document.getElementById('modal-preview-img');
        const placeholder = document.getElementById('modalPlaceholder');
        const scannerStatus = document.getElementById('modalStatus');
        const modalLoading = document.getElementById('modalLoading');
        const loadingText = document.getElementById('loadingTextModal');
        const btnCapture = document.getElementById('btnModalCapture');
        const btnScan = document.getElementById('btnModalScan');
        const resultArea = document.getElementById('modalResultArea');

        const PROXY_URL = 'scanner-proxy.php?path=';
        let currentTargetInput = null;
        let selectedFile = null;

        if (scannerModal) {
            document.querySelectorAll('.btn-close-scanner').forEach(btn => {
                btn.addEventListener('click', () => {
                    scannerModal.style.display = 'none';
                    currentTargetInput = null;
                });
            });

            const triggerFileSelect = () => cameraInput.click();
            previewArea.addEventListener('click', triggerFileSelect);
            btnCapture.addEventListener('click', triggerFileSelect);

            cameraInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;

                selectedFile = file;
                const reader = new FileReader();
                reader.onload = (event) => {
                    previewImg.src = event.target.result;
                    previewImg.style.display = 'block';
                    placeholder.style.display = 'none';
                    btnScan.disabled = false;
                    scannerStatus.textContent = "Đã nhận ảnh. Nhấn 'Xử lý' để tiếp tục.";
                    scannerStatus.className = 'scanner-status-text success';
                };
                reader.readAsDataURL(file);
            });

            btnScan.addEventListener('click', async () => {
                if (!selectedFile) return;
                modalLoading.style.display = 'flex';
                loadingText.textContent = "Đang phân tích mã vạch...";
                btnScan.disabled = true;
                btnCapture.disabled = true;

                const formData = new FormData();
                formData.append('file', selectedFile);

                try {
                    const res = await fetch(PROXY_URL + 'scan', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await res.json();

                    if (result.success && result.results && result.results.length > 0) {
                        const data = result.results[0].data;
                        scannerStatus.textContent = "✓ Quét mã thành công!";
                        scannerStatus.className = 'scanner-status-text success';

                        resultArea.style.display = 'block';
                        resultArea.innerHTML = `<i class="fa-solid fa-check-circle"></i> Mã: <b>${data}</b>`;

                        if (currentTargetInput) {
                            let scanVal = data.trim();
                            if (scanVal.startsWith('http') || scanVal.startsWith('www') || scanVal.includes('/') || scanVal.includes('\\') || scanVal.includes('?')) {
                                const extracted = extractSerialFromUrl(scanVal);
                                if (extracted) {
                                    scanVal = extracted;
                                }
                            }
                            currentTargetInput.value = scanVal;
                            setTimeout(() => {
                                scannerModal.style.display = 'none';
                                const event = new KeyboardEvent('keydown', { key: 'Enter' });
                                currentTargetInput.dispatchEvent(event);
                            }, 1200);
                        }
                    } else {
                        scannerStatus.textContent = "❌ Không tìm thấy mã QR hoặc Barcode trong ảnh.";
                        scannerStatus.className = 'scanner-status-text error';
                    }
                } catch (err) {
                    scannerStatus.textContent = "❌ Lỗi kết nối!";
                    scannerStatus.className = 'scanner-status-text error';
                } finally {
                    modalLoading.style.display = 'none';
                    btnScan.disabled = false;
                    btnCapture.disabled = false;
                }
            });

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.barcode-action-btn');
                if (!btn) return;
                const row = btn.closest('.input-wrapper');
                const input = row ? row.querySelector('.scan-input') : null;
                if (input) {
                    currentTargetInput = input;
                    selectedFile = null;
                    cameraInput.value = '';
                    previewImg.style.display = 'none';
                    previewImg.src = '';
                    placeholder.style.display = 'flex';
                    scannerStatus.textContent = 'Chưa chọn ảnh nào';
                    scannerStatus.className = 'scanner-status-text';
                    btnScan.disabled = true;
                    resultArea.style.display = 'none';
                    resultArea.innerHTML = '';
                    scannerModal.style.display = 'flex';
                }
            });
        }

        const firstEmpty = Array.from(allIns).find(i => !i.value.trim() || i.classList.contains('is-invalid'));
        if (firstEmpty) firstEmpty.focus();

        allIns.forEach((input, idx) => {
            let debounceTimer = null;
            input.addEventListener('input', () => {
                const val = input.value.trim();

                // Đồng bộ hóa IMEI lên header
                const typeUpper = (input.getAttribute('data-loai') || '').toUpperCase();
                if (typeUpper === 'IMEI' || typeUpper === 'IMER') {
                    const headerDisplay = document.getElementById('header-imei-display');
                    if (headerDisplay) {
                        const imeiFields = Array.from(document.querySelectorAll('[data-loai]')).filter(el => {
                            const t = (el.getAttribute('data-loai') || '').toUpperCase();
                            return t === 'IMEI' || t === 'IMER';
                        });
                        const imeiValues = imeiFields.map(el => el.value.trim()).filter(v => v !== '');
                        headerDisplay.textContent = imeiValues.join(' / ');
                    }
                }

                if (val === '') {
                    clearTimeout(debounceTimer);
                    validateSerial(input);
                    updateConfirmButton();
                    return;
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    validateSerial(input);
                }, 200); // GIẢM XUỐNG 200ms để kiểm tra ngay lập tức
            });

            input.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    const val = input.value.trim();
                    const typeUpper = input.getAttribute('data-loai') ? input.getAttribute('data-loai').toUpperCase() : '';
                    let isValid = false;

                    if (val === '' && (typeUpper === 'WIN' || typeUpper === 'IMEI' || typeUpper === 'IMER')) {
                        isValid = true;
                    } else {
                        // Gọi kiểm tra ngay lập tức khi nhấn Enter
                        isValid = await validateSerial(input);
                    }

                    if (isValid) {
                        const nextInputs = Array.from(allIns).slice(idx + 1);
                        const nextEmpty = nextInputs.find(i => !i.value.trim() || i.classList.contains('is-invalid'));
                        if (nextEmpty) {
                            nextEmpty.focus();
                        } else {
                            confirmBtn.focus();
                        }
                    } else {
                        input.select();
                    }
                }
            });

            input.addEventListener('blur', () => {
                if (input.value.trim()) {
                    // Nếu đã có giá trị thì kiểm tra ngay khi mất focus
                    validateSerial(input);
                }
            });
        });

        confirmBtn.onclick = async function () {
            const invalid = document.querySelector('.scan-input.is-invalid');
            if (invalid) {
                showToast('Vui lòng sửa các ô báo đỏ!', 'error');
                invalid.focus();
                return;
            }

            const serialsData = [];
            let allFilled = true;
            allIns.forEach(input => {
                const val = input.value.trim();
                const type = input.getAttribute('data-loai') ? input.getAttribute('data-loai').toUpperCase() : '';
                if (val === '') {
                    if (type === 'WIN' || type === 'IMEI' || type === 'IMER') {
                        serialsData.push({
                            val: val,
                            name: input.getAttribute('data-name'),
                            type: input.getAttribute('data-loai'),
                            choice: input.getAttribute('data-choice'),
                            id_ct: input.getAttribute('data-id-ct')
                        });
                    } else {
                        allFilled = false;
                    }
                }
                else {
                    serialsData.push({
                        val: val,
                        name: input.getAttribute('data-name'),
                        type: input.getAttribute('data-loai'),
                        choice: input.getAttribute('data-choice'),
                        id_ct: input.getAttribute('data-id-ct')
                    });
                }
            });

            // Cho phép lưu ngay cả khi chưa đầy đủ (để kỹ thuật viên có thể xóa serial hoặc lưu nháp)
            /*
            if (!allFilled) {
                showToast('Vui lòng nhập đầy đủ tất cả các linh kiện!', 'error');
                return;
            }
            */
            const originalHTML = this.innerHTML;
            try {
                this.disabled = true;
                this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang lưu...';

                const fd = new FormData();
                fd.append('order_id', typeof currentOrderId !== 'undefined' ? currentOrderId : '');
                fd.append('config_name', typeof currentConfigPure !== 'undefined' ? currentConfigPure : '');
                fd.append('machine_idx', typeof currentMachineIdx !== 'undefined' ? currentMachineIdx : 1);
                serialsData.forEach((item, i) => {
                    fd.append(`serials[${i}][val]`, item.val);
                    fd.append(`serials[${i}][name]`, item.name);
                    fd.append(`serials[${i}][type]`, item.type);
                    fd.append(`serials[${i}][choice]`, item.choice);
                    fd.append(`serials[${i}][id_ct]`, item.id_ct);
                });
                const resp = await fetch('ajax-luu-serial.php', { method: 'POST', body: fd });
                const res = await resp.json();

                if (res.success) {
                    clearDraftStorage();
                    showToast('✓ ' + (res.message || 'Thành công!'), 'success');
                    setTimeout(() => {
                        const nextUrl = confirmBtn.getAttribute('data-next-url');
                        if (nextUrl) {
                            window.location.href = nextUrl;
                        } else {
                            window.location.href = 'kho-hang.php?id=' + (typeof currentOrderId !== 'undefined' ? currentOrderId : '');
                        }
                    }, 800);
                } else {
                    if (res.error_type === 'auth_lock') {
                        // Trường hợp 4: Pop-up báo lỗi và thoát ra trang
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                title: 'Lỗi Xác Thực',
                                text: res.message,
                                icon: 'error',
                                confirmButtonText: 'Quay lại',
                                confirmButtonColor: '#ef4444'
                            }).then(() => {
                                window.location.href = 'kho-hang.php?id=' + (typeof currentOrderId !== 'undefined' ? currentOrderId : '');
                            });
                        } else {
                            alert(res.message);
                            window.location.href = 'kho-hang.php?id=' + (typeof currentOrderId !== 'undefined' ? currentOrderId : '');
                        }
                    } else {
                        showToast('Lỗi: ' + res.message, 'error');
                        this.disabled = false;
                        this.innerHTML = originalHTML;
                    }
                }
            } catch (err) {
                showToast('Lỗi kết nối!', 'error');
                this.disabled = false; this.innerHTML = originalHTML;
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScanSystem);
    } else {
        initScanSystem();
    }
})();
