</div><!-- /.content-area -->
</div><!-- /.main-content -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
/* ============================================================
   CYBERPLAZA - GLOBAL SCRIPTS
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    const desktopToggle = document.getElementById('sidebarDesktopToggle');
    const mobileToggles = document.querySelectorAll('.sidebar-toggle');
    
    if (!sidebar) return;
    
    // ============================================================
    // RESTORE STATE FROM LOCALSTORAGE
    // ============================================================
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    
    if (isCollapsed && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
        document.body.classList.add('sidebar-collapsed');
    }
    
    // ============================================================
    // COLLAPSE TOGGLE (Desktop)
    // ============================================================
    function toggleCollapse() {
        if (window.innerWidth < 992) return;
        
        sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed');
        
        const nowCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebarCollapsed', nowCollapsed);
        
        // Trigger resize for charts/tables
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
        }, 350);
    }
    
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleCollapse();
        });
    }
    
    if (desktopToggle) {
        desktopToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleCollapse();
        });
    }
    
    // ============================================================
    // KEYBOARD SHORTCUT: Ctrl+B
    // ============================================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            toggleCollapse();
        }
    });
    
    // ============================================================
    // MOBILE TOGGLE
    // ============================================================
    mobileToggles.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show');
        });
    });
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }
    
    // Close mobile sidebar when clicking link
    sidebar.querySelectorAll('.nav-link:not(.section-toggle)').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992) {
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
            }
        });
    });
    
    // ============================================================
    // WINDOW RESIZE HANDLER
    // ============================================================
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth >= 992) {
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
                
                const savedCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                sidebar.classList.toggle('collapsed', savedCollapsed);
                document.body.classList.toggle('sidebar-collapsed', savedCollapsed);
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
            }
        }, 200);
    });
    
    // ============================================================
    // SECTION TOGGLE (Accordion)
    // ============================================================
    const toggles = document.querySelectorAll('.sidebar .section-toggle');
    toggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            // If collapsed, expand sidebar first
            if (sidebar.classList.contains('collapsed') && window.innerWidth >= 992) {
                e.preventDefault();
                e.stopPropagation();
                
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', false);
                
                setTimeout(() => {
                    const targetId = this.getAttribute('href').substring(1);
                    const targetMenu = document.getElementById(targetId);
                    if (targetMenu) {
                        sidebar.querySelectorAll('.collapse').forEach(m => m.classList.remove('show'));
                        sidebar.querySelectorAll('.section-toggle').forEach(t => t.classList.add('collapsed'));
                        
                        targetMenu.classList.add('show');
                        this.classList.remove('collapsed');
                    }
                }, 350);
                return;
            }
            
            e.preventDefault();
            e.stopPropagation();
            
            const targetId = this.getAttribute('href').substring(1);
            const targetMenu = document.getElementById(targetId);
            if (!targetMenu) return;
            
            const isCurrentlyOpen = targetMenu.classList.contains('show');
            
            // Close all
            sidebar.querySelectorAll('.collapse').forEach(function(menu) {
                menu.classList.remove('show');
            });
            sidebar.querySelectorAll('.section-toggle').forEach(function(btn) {
                btn.classList.add('collapsed');
            });
            
            // Open clicked if it was closed
            if (!isCurrentlyOpen) {
                targetMenu.classList.add('show');
                this.classList.remove('collapsed');
            }
        });
    });
    
    // ============================================================
    // AUTO-EXPAND ACTIVE SECTION
    // ============================================================
    const activeLink = document.querySelector('.sidebar .sub-nav .nav-link.active');
    if (activeLink && !sidebar.classList.contains('collapsed')) {
        const parentCollapse = activeLink.closest('.collapse');
        if (parentCollapse) {
            parentCollapse.classList.add('show');
            const toggleBtn = document.querySelector('[href="#' + parentCollapse.id + '"]');
            if (toggleBtn) toggleBtn.classList.remove('collapsed');
        }
    }
});

/* ============================================================
   DATATABLES INIT
   ============================================================ */
jQuery(document).ready(function($) {
    if ($('.datatable').length) {
        $('.datatable').DataTable({
            pageLength: 25,
            responsive: true,
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "No entries",
                infoFiltered: "(filtered from _MAX_ total)",
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            },
            dom: "<'row mb-3'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
        });
    }
    
    // Auto dismiss alerts
    setTimeout(function() {
        $('.alert-dismissible').fadeOut('slow');
    }, 5000);
});
</script>

</body>
</html>