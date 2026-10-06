<?php
if (!isset($_SESSION)) {
    session_start();
}

// Detect base path (admin, employee, client, or root)
$script_path = $_SERVER['PHP_SELF'];
$base_path = '';

if (strpos($script_path, '/admin/') !== false || 
    strpos($script_path, '/employee/') !== false || 
    strpos($script_path, '/client/') !== false) {
    $base_path = '../';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' . APP_NAME : APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- CyberPlaza Custom CSS -->
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/cyber-plaza.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo $base_path; ?>assets/images/5.png">
</head>
<body>