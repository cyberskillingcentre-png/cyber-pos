/**
 * ============================================================
 * CYBERPLAZA SIDEBAR - UNIVERSAL COLLAPSE SCRIPT
 * ============================================================
 * Works on all admin, employee, and client pages.
 * 
 * Usage: Add to footer.php: <script src="...sidebar.js"></script>
 * 
 * Features:
 * - Desktop collapse (72px ↔ 270px)
 * - Mobile slide-in with overlay
 * - Ctrl+B keyboard shortcut
 * - localStorage persistence
 * - Auto-detects sidebar presence
 * ============================================================
 */

(function() {
    'use strict';
    
    console.log('🚀 CyberSidebar loading...');
    
    // ============================================
    // STATE MANAGEMENT
    // ============================================
    const STORAGE_KEY = 'cyberSidebarCollapsed';
    const MOBILE_BREAKPOINT = 992;
    
    function getSidebar() {
        return document.getElementById('sidebar') || document.querySelector('.sidebar');
    }
    
    function getOverlay() {
        return document.getElementById('sidebarOverlay') || document.querySelector('.sidebar-overlay');
    }
    
    function isMobile() {
        return window.innerWidth < MOBILE_BREAKPOINT;
    }
    
    function isCollapsed() {
        const sidebar = getSidebar();
        return sidebar && sidebar.classList.contains('collapsed');
    }
    
    // ============================================
    // CORE FUNCTIONS
    // ============================================
    function toggleDesktopCollapse() {
        const sidebar = getSidebar();
        if (!sidebar) {
            console.warn('❌ No sidebar found');
            return;
        }
        
        if (isMobile()) return;
        
        sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed');
        
        const collapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        
        console.log('🔄 Sidebar toggled:', collapsed ? 'COLLAPSED' : 'EXPANDED');
        
        // Trigger resize for charts/tables
        setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
        }, 350);
    }
    
    function toggleMobileSidebar() {
        const sidebar = getSidebar();
        const overlay = getOverlay();
        if (!sidebar) return;
        
        sidebar.classList.toggle('show');
        if (overlay) overlay.classList.toggle('show');
        
        console.log('📱 Mobile sidebar:', sidebar.classList.contains('show') ? 'SHOWN' : 'HIDDEN');
    }
    
    function closeMobileSidebar() {
        const sidebar = getSidebar();
        const overlay = getOverlay();
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
    }
    
    function restoreState() {
        const sidebar = getSidebar();
        if (!sidebar) return;
        
        if (!isMobile() && localStorage.getItem(STORAGE_KEY) === '1') {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
            console.log('📌 Restored collapsed state');
        } else if (isMobile()) {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
        }
    }
    
    // ============================================
    // EVENT DELEGATION (catches clicks anywhere)
    // ============================================
    document.addEventListener('click', function(e) {
        // Desktop collapse button (sidebar footer)
        if (e.target.closest('#sidebarCollapseBtn')) {
            e.preventDefault();
            e.stopPropagation();
            toggleDesktopCollapse();
            return false;
        }
        
        // Desktop toggle (top navbar hamburger)
        if (e.target.closest('#sidebarDesktopToggle')) {
            e.preventDefault();
            e.stopPropagation();
            toggleDesktopCollapse();
            return false;
        }
        
        // Mobile toggle
        if (e.target.closest('.sidebar-toggle')) {
            e.preventDefault();
            e.stopPropagation();
            toggleMobileSidebar();
            return false;
        }
        
        // Overlay click → close mobile
        if (e.target.closest('#sidebarOverlay') || e.target.closest('.sidebar-overlay')) {
            closeMobileSidebar();
        }
        
        // Close mobile sidebar on nav link click
        if (e.target.closest('.sidebar .nav-link:not(.section-toggle)')) {
            if (isMobile()) {
                closeMobileSidebar();
            }
        }
    }, true); // Capture phase - runs before other listeners
    
    // ============================================
    // KEYBOARD SHORTCUT: Ctrl+B
    // ============================================
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            toggleDesktopCollapse();
        }
    });
    
    // ============================================
    // WINDOW RESIZE HANDLER
    // ============================================
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (!isMobile()) {
                // Desktop: restore state, close mobile view
                closeMobileSidebar();
                restoreState();
            } else {
                // Mobile: remove collapse state
                const sidebar = getSidebar();
                if (sidebar) {
                    sidebar.classList.remove('collapsed');
                    document.body.classList.remove('sidebar-collapsed');
                }
            }
        }, 200);
    });
    
    // ============================================
    // INITIALIZATION
    // ============================================
    function init() {
        const sidebar = getSidebar();
        if (!sidebar) {
            console.log('ℹ️ No sidebar on this page');
            return;
        }
        
        restoreState();
        console.log('✅ CyberSidebar ready');
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Also run after short delay for late-loading sidebars
    setTimeout(init, 300);
    
    // Expose globally for manual use
    window.CyberSidebar = {
        toggle: toggleDesktopCollapse,
        restore: restoreState,
        closeMobile: closeMobileSidebar
    };
})();