<?php
require_once '../config/config.php';
require_admin();

$product_id = intval($_GET['id'] ?? 0);
$quantity = intval($_GET['qty'] ?? 1);
$quantity = max(1, min(100, $quantity));

if ($product_id <= 0) {
    set_alert('danger', 'Invalid product ID');
    header('Location: products.php');
    exit;
}

// Fetch product
$stmt = $conn->prepare("
    SELECT p.*, c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.product_id = ?
");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    set_alert('danger', 'Product not found');
    header('Location: products.php');
    exit;
}

// If no barcode, generate and save one
if (empty($product['barcode'])) {
    $digits = '200';
    for ($i = 0; $i < 9; $i++) {
        $digits .= mt_rand(0, 9);
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += intval($digits[$i]) * ($i % 2 === 0 ? 1 : 3);
    }
    $checkDigit = (10 - ($sum % 10)) % 10;
    $newBarcode = $digits . $checkDigit;
    
    try {
        $stmt = $conn->prepare("UPDATE products SET barcode = ? WHERE product_id = ?");
        $stmt->execute([$newBarcode, $product_id]);
        $product['barcode'] = $newBarcode;
    } catch(PDOException $e) {
        $product['barcode'] = $newBarcode;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcodes - <?php echo htmlspecialchars($product['product_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; background: #f5f5f5; padding: 20px; }
        
        .controls {
            background: #fff;
            padding: 16px 20px;
            border-radius: 12px;
            margin: 0 auto 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            max-width: 1000px;
        }
        
        .controls label { font-weight: 700; color: #333; font-size: 14px; }
        .controls input[type="number"] {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            width: 100px;
            font-weight: 700;
        }
        .controls button {
            padding: 10px 20px;
            background: linear-gradient(135deg, #6B46C1, #553C9A);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
        }
        .controls button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(107, 70, 193, 0.35); }
        .controls .btn-print { background: linear-gradient(135deg, #48BB78, #38A169); }
        .controls .back-link {
            margin-left: auto;
            color: #6B46C1;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }
        
        .preview-info { text-align: center; color: #666; font-size: 13px; margin-bottom: 15px; }
        
        .labels-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .barcode-label {
            border: 1.5px solid #333;
            padding: 12px 10px;
            text-align: center;
            background: #fff;
            page-break-inside: avoid;
            border-radius: 4px;
        }
        
        .label-product-name {
            font-size: 11px;
            font-weight: 700;
            color: #000;
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .label-price {
            font-size: 15px;
            font-weight: 900;
            color: #000;
            margin-bottom: 8px;
            font-family: 'Courier New', monospace;
        }
        
        .label-barcode { display: block; margin: 0 auto; max-width: 100%; }
        
        @media print {
            body { background: #fff; padding: 0; }
            .controls, .preview-info { display: none !important; }
            .labels-container { box-shadow: none; padding: 0; background: #fff; gap: 8px; max-width: none; }
            .barcode-label { border: 1px solid #000; }
            @page { size: A4; margin: 8mm; }
        }
    </style>
</head>
<body>
    
    <div class="controls">
        <label>Labels:</label>
        <input type="number" id="labelQty" min="1" max="100" value="<?php echo $quantity; ?>">
        <button onclick="updateQuantity()">
            <i class="fas fa-sync"></i> Update
        </button>
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="products.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Products
        </a>
    </div>
    
    <div class="preview-info">
        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong> 
        — Barcode: <code><?php echo htmlspecialchars($product['barcode']); ?></code>
        — <?php echo $quantity; ?> labels
    </div>
    
    <div class="labels-container" id="labelsContainer">
        <?php for ($i = 0; $i < $quantity; $i++): ?>
        <div class="barcode-label">
            <div class="label-product-name">
                <?php echo htmlspecialchars($product['product_name']); ?>
            </div>
            <div class="label-price">
                UGX <?php echo number_format(floatval($product['selling_price']), 0); ?>
            </div>
            <svg class="label-barcode" id="barcode-<?php echo $i; ?>"></svg>
        </div>
        <?php endfor; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        const barcodeValue = "<?php echo htmlspecialchars($product['barcode']); ?>";
        const labelCount = <?php echo $quantity; ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            for (let i = 0; i < labelCount; i++) {
                try {
                    JsBarcode("#barcode-" + i, barcodeValue, {
                        format: "EAN13",
                        width: 1.8,
                        height: 45,
                        displayValue: true,
                        fontSize: 11,
                        margin: 4,
                        font: "monospace",
                        textMargin: 2
                    });
                } catch(e) {
                    console.error('EAN13 error, falling back to CODE128:', e);
                    try {
                        JsBarcode("#barcode-" + i, barcodeValue, {
                            format: "CODE128",
                            width: 1.8,
                            height: 45,
                            displayValue: true,
                            fontSize: 11
                        });
                    } catch(e2) {
                        console.error('Fallback error:', e2);
                    }
                }
            }
        });
        
        function updateQuantity() {
            const qty = document.getElementById('labelQty').value;
            if (qty < 1 || qty > 100) {
                alert('Please enter a number between 1 and 100');
                return;
            }
            window.location.href = 'print-barcodes.php?id=<?php echo $product_id; ?>&qty=' + qty;
        }
        
        document.getElementById('labelQty').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') updateQuantity();
        });
    </script>
    
</body>
</html>