        </div><!-- /.content-area -->
    </div><!-- /.main-content -->
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?php echo $base_path ?? '../'; ?>assets/js/sidebar.js"></script>
    
    <script>
    // DataTable init for any table with class "datatable"
    jQuery(document).ready(function($) {
        if ($('.datatable').length) {
            $('.datatable').each(function() {
                const $table = $(this);
                if (!$.fn.DataTable.isDataTable(this)) {
                    $table.DataTable({
                        pageLength: 25,
                        order: [],
                        responsive: true,
                        language: {
                            search: "",
                            searchPlaceholder: "Search...",
                            lengthMenu: "Show _MENU_ entries",
                            info: "Showing _START_ to _END_ of _TOTAL_",
                            infoEmpty: "No entries",
                            infoFiltered: "(filtered from _MAX_)",
                            paginate: {
                                first: '<i class="fas fa-angle-double-left"></i>',
                                last: '<i class="fas fa-angle-double-right"></i>',
                                next: '<i class="fas fa-angle-right"></i>',
                                previous: '<i class="fas fa-angle-left"></i>'
                            }
                        }
                    });
                }
            });
        }
        
        // Auto-dismiss alerts
        setTimeout(function() {
            $('.alert-dismissible').fadeOut('slow');
        }, 5000);
    });
    </script>
</body>
</html>