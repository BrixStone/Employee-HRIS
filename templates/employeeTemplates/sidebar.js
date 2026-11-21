
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('employeeSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (!sidebar || !backdrop || !toggleBtn) return;

    function closeSidebar() {
        sidebar.classList.add('hidden');
        backdrop.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    function openSidebar() {
        sidebar.classList.remove('hidden');
        document.body.classList.add('sidebar-open');
        backdrop.classList.add('active');
        toggleBtn.setAttribute('aria-expanded', 'true');
    }

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.contains('hidden')
            ? openSidebar()
            : closeSidebar();
    });

    backdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeSidebar();
    });
});
