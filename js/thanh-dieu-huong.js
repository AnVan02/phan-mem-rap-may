document.addEventListener("DOMContentLoaded", function () {
    const menuToggles = document.querySelectorAll('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    const toggleSidebar = () => {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        // Prevent scrolling when sidebar is open
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    };

    menuToggles.forEach(btn => {
        btn.addEventListener('click', toggleSidebar);
    });

    overlay?.addEventListener('click', toggleSidebar);

    // Close on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            toggleSidebar();
        }
    });
});