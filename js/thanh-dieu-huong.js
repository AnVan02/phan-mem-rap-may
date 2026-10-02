document.addEventListener("DOMContentLoaded", function () {

    const sidebar    = document.getElementById('main-sidebar');
    const toggleBtn  = document.getElementById('sidebar-toggle-btn');
    const overlay    = document.getElementById('sidebar-overlay');
    const STORAGE_KEY = 'sidebar_collapsed';

    // ─── Desktop: thu gọn / mở rộng ────────────────────────────
    const isDesktop = () => window.innerWidth > 768;

    const setCollapsed = (collapsed) => {
        if (collapsed) {
            sidebar.classList.add('collapsed');
        } else {
            sidebar.classList.remove('collapsed');
        }
        // Lưu trạng thái
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    };

    // Khôi phục trạng thái từ lần trước
    if (isDesktop()) {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved === '1') {
            sidebar.classList.add('collapsed');
        }
    }

    // Bấm nút 3 gạch
    toggleBtn?.addEventListener('click', () => {
        if (isDesktop()) {
            // Desktop: toggle thu gọn / mở rộng
            const isCollapsed = sidebar.classList.contains('collapsed');
            setCollapsed(!isCollapsed);
        } else {
            // Mobile: đóng sidebar
            closeMobile();
        }
    });

    // ─── Mobile: mở / đóng như drawer ──────────────────────────
    const mobileBtn = document.querySelector('.mobile-float-btn');

    const openMobile = () => {
        sidebar.classList.add('mobile-open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    const closeMobile = () => {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    };

    mobileBtn?.addEventListener('click', openMobile);
    overlay?.addEventListener('click', closeMobile);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeMobile();
    });

    // Resize: reset mobile state khi quay lại desktop
    window.addEventListener('resize', () => {
        if (isDesktop()) {
            closeMobile();
        }
    });

});