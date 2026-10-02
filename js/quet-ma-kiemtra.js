document.addEventListener('DOMContentLoaded', () => {
    const inputs = document.querySelectorAll('.scan-input');
    const confirmBtn = document.getElementById('btnConfirm');
    const orderId = typeof currentOrderId !== 'undefined' ? currentOrderId : null;

    // Tự động focus vào ô đầu tiên
    if (inputs.length > 0) {
        inputs[0].focus();
    }

    inputs.forEach((input, index) => {
        // Tự động chuyển ô khi nhấn Enter (máy quét mã vạch thường gửi phím Enter)
        input.addEventListener('keydown', async (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();

                // Thực hiện kiểm tra serial khi nhấn Enter
                await validateSerial(input);

                if (index < inputs.length - 1) {
                    inputs[index + 1].focus();
                } else {
                    confirmBtn.focus();
                }
            }
        });

        // Kiểm tra khi rời khỏi ô hoặc thay đổi nội dung
        input.addEventListener('blur', () => {
            validateSerial(input);
        });
    });

    /**
     * Kiểm tra Serial Realtime
     */
    async function validateSerial(input) {
        input.value = input.value.toUpperCase().trim();
        const val = input.value;
        const id = input.getAttribute('data-id');
        const parent = input.closest('.comp-input-side');
        const statusIcon = parent.querySelector('.scan-status-icon');
        const errorMsg = parent.querySelector('.scan-error-msg');

        // Reset trạng thái
        input.classList.remove('is-invalid', 'is-valid');
        statusIcon.className = 'scan-status-icon';
        statusIcon.innerHTML = '';
        errorMsg.style.display = 'none';
        errorMsg.textContent = '';

        if (!val) {
            return; // Trống thì không làm gì
        }

        // 1. Kiểm tra trùng lặp ngay trên form (Client-side)
        let isDuplicateOnForm = false;
        inputs.forEach(otherInput => {
            if (otherInput !== input && otherInput.value.trim().toUpperCase() === val && val !== "") {
                isDuplicateOnForm = true;
            }
        });

        if (isDuplicateOnForm) {
            setInputStatus(input, 'error', 'Mã serial này đang bị nhập trùng trong máy này!');
            return;
        }
        

        // 2. Kiểm tra database (Server-side)
        try {
            statusIcon.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            const response = await fetch(`kiemtra.php?ajax=1`, {
                method: 'POST',
                body: JSON.stringify({
                    id_donhang: orderId,
                    so_serial: val,
                    ten_linhkien: input.getAttribute('data-name'),
                    ten_cauhinh: typeof currentConfigName !== 'undefined' ? currentConfigName : ''
                }),
                headers: { 'Content-Type': 'application/json' }
            });
            const result = await response.json();

            if (result.status === 'match') {
                setInputStatus(input, 'success');
            } else if (result.status === 'no_match') {
                // Nếu là serial mới hoàn toàn (thông báo có chữ "Hợp lệ")
                if (result.message.includes("Hợp lệ") || result.message.includes("mới hoàn toàn")) {
                    setInputStatus(input, 'success');
                } else {
                    // Nếu là serial thuộc đơn hàng khác hoặc linh kiện khác (thông báo lỗi)
                    setInputStatus(input, 'error', result.message);
                }
            }
        } catch (err) {
            console.error(err);
        }
    }
    function setInputStatus(input, status, message = '') {
        const parent = input.closest('.comp-input-side');
        const statusIcon = parent.querySelector('.scan-status-icon');
        const errorMsg = parent.querySelector('.scan-error-msg');
        input.classList.remove('is-invalid', 'is-valid');
        statusIcon.className = 'scan-status-icon';

        if (status === 'success') {
            input.classList.add('is-valid');
            statusIcon.classList.add('success');
            statusIcon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
        } else if (status === 'error') {
            input.classList.add('is-invalid');
            statusIcon.classList.add('error');
            statusIcon.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
            if (message) {
                errorMsg.textContent = message;
                errorMsg.style.display = 'block';
            }
        }
    }

       confirmBtn?.addEventListener('click', () => {
        // Kiểm tra lỗi trước khi lưu
        const hasError = document.querySelectorAll('.scan-input.is-invalid').length > 0;
        if (hasError) {
            alert('Vui lòng sửa các lỗi serial (màu đỏ) trước khi xác nhận.');
            return;
        }

        let serialsToSave = [];
        let allFilled = true;

        inputs.forEach(input => {
            const val = input.value.trim();
            const id = input.getAttribute('data-id');

            if (!val) {
                allFilled = false;
                input.style.borderColor = '#ef4444';
            } else {
                serialsToSave.push({ id: id, val: val.trim().toUpperCase() });
            }
        });

        if (!allFilled) {
            alert('Vui lòng nhập đầy đủ Serial cho tất cả linh kiện.');
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang lưu...';
        const formData = new FormData();
        const configName = typeof currentConfigName !== 'undefined' ? currentConfigName : '';
        const orderId = typeof currentOrderId !== 'undefined' ? currentOrderId : null;

        formData.append('config_name', configName);
        formData.append('order_id', orderId);

        serialsToSave.forEach((item, index) => {
            formData.append(`serials[${index}][id]`, item.id);
            formData.append(`serials[${index}][val]`, item.val);
        });

        fetch('kiemtra.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('✓ ' + result.message);
                    window.location.href = 'kho-hang.php?id=' + orderId;
                } else {
                    alert('❌ Lỗi: ' + (result.message || 'Không xác định'));
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = 'Xác nhận Lưu <i class="fa-solid fa-paper-plane"></i>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Lỗi kết nối server!');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Xác nhận Lưu <i class="fa-solid fa-paper-plane"></i>';
            });
    });

});     
