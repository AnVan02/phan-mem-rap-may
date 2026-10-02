async function exportWithFilePicker(form, btn, url) {
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="btn-content"><i class="fa-solid fa-spinner fa-spin"></i><span>Đang xuất...</span></div>';

    try {
        const formData = new FormData(form);
        const res = await fetch(url, { method: 'POST', body: formData });

        if (!res.ok) throw new Error('HTTP ' + res.status);

        const contentType = res.headers.get('Content-Type') || '';
        if (contentType.includes('application/json') || contentType.includes('text/html')) {
            const text = await res.text();
            throw new Error(text.substring(0, 200));
        }

        const blob = await res.blob();

        // Lấy tên file từ header Content-Disposition
        let filename = 'export.xlsx';
        const cd = res.headers.get('Content-Disposition') || '';
        const match = cd.match(/filename[^;=\n]*=(?:(['"])([^'"]*)\1|([^;\n]*))/i);
        if (match) filename = decodeURIComponent((match[2] || match[3] || '').trim());

        // Dùng File System Access API nếu trình duyệt hỗ trợ
        if (window.showSaveFilePicker) {
            try {
                const handle = await window.showSaveFilePicker({
                    suggestedName: filename,
                    types: [{
                        description: 'Excel Workbook',
                        accept: { 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'] }
                    }]
                });
                const writable = await handle.createWritable();
                await writable.write(blob);
                await writable.close();
                showExportSuccess(filename);
                return;
            } catch (e) {
                if (e.name === 'AbortError') return; // User cancelled picker
                // Fall through to legacy download
            }
        }

        // Fallback: tạo link tạm thời để tải
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
        showExportSuccess(filename);
    } catch (err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Xuất file thất bại', text: err.message, confirmButtonColor: '#3085d6' });
        } else {
            alert('Xuất file thất bại: ' + err.message);
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}

function showExportSuccess(filename) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: 'Xuất file thành công!',
            html: '<span style="word-break:break-all;">' + filename + '</span>',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }
}
