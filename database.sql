-- Electrical & Electronics Shop POS System Database
-- Created: 2025

CREATE DATABASE IF NOT EXISTS electrical_pos_db;
USE electrical_pos_db;

-- Users Table (Admin & Employees)
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'employee') DEFAULT 'employee',
    profile_image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stores/Branches Table
CREATE TABLE IF NOT EXISTS stores (
    store_id INT AUTO_INCREMENT PRIMARY KEY,
    store_name VARCHAR(100) NOT NULL,
    store_code VARCHAR(20) UNIQUE NOT NULL,
    location VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(100),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories Table
CREATE TABLE IF NOT EXISTS categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    category_code VARCHAR(20) UNIQUE,
    description TEXT,
    parent_category_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_category_id) REFERENCES categories(category_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Suppliers Table
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL,
    supplier_code VARCHAR(20) UNIQUE,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products Table
CREATE TABLE IF NOT EXISTS products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) UNIQUE NOT NULL,
    barcode VARCHAR(100) UNIQUE,
    product_name VARCHAR(255) NOT NULL,
    category_id INT,
    supplier_id INT,
    description TEXT,
    unit VARCHAR(50) DEFAULT 'piece',
    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    profit_margin DECIMAL(5,2) DEFAULT 0.00,
    tax_rate DECIMAL(5,2) DEFAULT 0.00,
    has_expiry ENUM('yes', 'no') DEFAULT 'no',
    reorder_level INT DEFAULT 10,
    product_image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store Inventory Table
CREATE TABLE IF NOT EXISTS store_inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_number VARCHAR(50),
    quantity INT NOT NULL DEFAULT 0,
    expiry_date DATE,
    manufacturing_date DATE,
    location_in_store VARCHAR(100),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    UNIQUE KEY unique_store_product_batch (store_id, product_id, batch_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customers Table
CREATE TABLE IF NOT EXISTS customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(20) UNIQUE,
    customer_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    customer_type ENUM('regular', 'wholesale', 'vip') DEFAULT 'regular',
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    total_purchases DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales Table
CREATE TABLE IF NOT EXISTS sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) UNIQUE NOT NULL,
    store_id INT NOT NULL,
    customer_id INT,
    sale_date DATETIME NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash', 'card', 'mobile_money', 'bank_transfer', 'credit') DEFAULT 'cash',
    payment_status ENUM('paid', 'partial', 'unpaid') DEFAULT 'paid',
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    amount_due DECIMAL(15,2) DEFAULT 0.00,
    served_by INT,
    notes TEXT,
    status ENUM('completed', 'returned', 'cancelled') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL,
    FOREIGN KEY (served_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sale Items Table
CREATE TABLE IF NOT EXISTS sale_items (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    inventory_id INT,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,2) DEFAULT 0.00,
    total_price DECIMAL(15,2) NOT NULL,
    profit DECIMAL(15,2) DEFAULT 0.00,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (inventory_id) REFERENCES store_inventory(inventory_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchases/Stock Receiving Table
CREATE TABLE IF NOT EXISTS purchases (
    purchase_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_number VARCHAR(50) UNIQUE NOT NULL,
    store_id INT NOT NULL,
    supplier_id INT,
    purchase_date DATE NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('paid', 'partial', 'unpaid') DEFAULT 'unpaid',
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    received_by INT,
    notes TEXT,
    status ENUM('pending', 'received', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Items Table
CREATE TABLE IF NOT EXISTS purchase_items (
    purchase_item_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_number VARCHAR(50),
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_price DECIMAL(15,2) NOT NULL,
    expiry_date DATE,
    manufacturing_date DATE,
    FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Returns Table
CREATE TABLE IF NOT EXISTS returns (
    return_id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(50) UNIQUE NOT NULL,
    sale_id INT,
    store_id INT NOT NULL,
    customer_id INT,
    return_date DATETIME NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    refund_method ENUM('cash', 'card', 'mobile_money', 'credit') DEFAULT 'cash',
    reason TEXT,
    processed_by INT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE SET NULL,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Return Items Table
CREATE TABLE IF NOT EXISTS return_items (
    return_item_id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    sale_item_id INT,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_price DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (return_id) REFERENCES returns(return_id) ON DELETE CASCADE,
    FOREIGN KEY (sale_item_id) REFERENCES sale_items(sale_item_id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses Table
CREATE TABLE IF NOT EXISTS expenses (
    expense_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    expense_category VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    expense_date DATE NOT NULL,
    description TEXT,
    receipt_image VARCHAR(255),
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cash Flow/Shifts Table
CREATE TABLE IF NOT EXISTS shifts (
    shift_id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    user_id INT NOT NULL,
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    closing_balance DECIMAL(15,2),
    expected_balance DECIMAL(15,2),
    difference DECIMAL(15,2),
    shift_start DATETIME NOT NULL,
    shift_end DATETIME,
    status ENUM('open', 'closed') DEFAULT 'open',
    notes TEXT,
    FOREIGN KEY (store_id) REFERENCES stores(store_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity Logs Table
CREATE TABLE IF NOT EXISTS activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    activity_type VARCHAR(50) NOT NULL,
    activity_description TEXT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System Settings Table
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert Default Admin User (password: admin123)
INSERT INTO users (username, password, full_name, email, role, status) VALUES 
('admin', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'System Administrator', 'admin@electricalshop.com', 'admin', 'active');

-- Insert Default Store
INSERT INTO stores (store_name, store_code, location, phone, status) VALUES 
('Main Branch', 'STR001', 'Main Street, City Center', '+256-XXX-XXXXXX', 'active');

-- Insert Default Categories
INSERT INTO categories (category_name, category_code, description) VALUES 
('Electrical Cables', 'CAT001', 'Various types of electrical cables'),
('Switches & Sockets', 'CAT002', 'Electrical switches and socket outlets'),
('Lighting', 'CAT003', 'LED bulbs, tubes, and lighting fixtures'),
('Circuit Breakers', 'CAT004', 'MCBs, RCDs, and distribution boards'),
('Electronics', 'CAT005', 'Electronic components and devices'),
('Power Tools', 'CAT006', 'Electrical power tools and accessories'),
('Security Systems', 'CAT007', 'CCTV cameras and security equipment'),
('Solar Equipment', 'CAT008', 'Solar panels and inverters');

-- Insert Default Supplier
INSERT INTO suppliers (supplier_name, supplier_code, contact_person, phone, status) VALUES 
('Main Electrical Supplier', 'SUP001', 'John Doe', '+256-XXX-XXXXXX', 'active');

-- Insert Default System Settings
INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES 
('company_name', 'Electrical & Electronics Shop', 'text'),
('company_email', 'info@electricalshop.com', 'text'),
('company_phone', '+256-XXX-XXXXXX', 'text'),
('company_address', 'Main Street, City Center', 'text'),
('tax_rate', '18', 'number'),
('currency', 'UGX', 'text'),
('low_stock_threshold', '10', 'number'),
('receipt_footer', 'Thank you for your business!', 'text');

-- Create Indexes for Better Performance
CREATE INDEX idx_products_barcode ON products(barcode);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_sales_date ON sales(sale_date);
CREATE INDEX idx_sales_invoice ON sales(invoice_number);
CREATE INDEX idx_inventory_store_product ON store_inventory(store_id, product_id);
CREATE INDEX idx_sale_items_sale ON sale_items(sale_id);
CREATE INDEX idx_customers_phone ON customers(phone);
CREATE INDEX idx_users_username ON users(username);

-- Create Views for Common Queries

-- Product Stock View
CREATE OR REPLACE VIEW vw_product_stock AS
SELECT 
    p.product_id,
    p.product_code,
    p.barcode,
    p.product_name,
    c.category_name,
    s.store_name,
    si.quantity,
    p.reorder_level,
    si.expiry_date,
    CASE 
        WHEN si.quantity <= p.reorder_level THEN 'Low Stock'
        WHEN si.quantity = 0 THEN 'Out of Stock'
        ELSE 'In Stock'
    END AS stock_status,
    p.selling_price,
    p.purchase_price
FROM products p
LEFT JOIN categories c ON p.category_id = c.category_id
LEFT JOIN store_inventory si ON p.product_id = si.product_id
LEFT JOIN stores s ON si.store_id = s.store_id
WHERE p.status = 'active';

-- Sales Summary View
CREATE OR REPLACE VIEW vw_sales_summary AS
SELECT 
    s.sale_id,
    s.invoice_number,
    s.sale_date,
    st.store_name,
    c.customer_name,
    u.full_name AS served_by,
    s.subtotal,
    s.discount_amount,
    s.tax_amount,
    s.total_amount,
    s.payment_method,
    s.payment_status,
    s.status
FROM sales s
LEFT JOIN stores st ON s.store_id = st.store_id
LEFT JOIN customers c ON s.customer_id = c.customer_id
LEFT JOIN users u ON s.served_by = u.user_id;

-- Top Selling Products View
CREATE OR REPLACE VIEW vw_top_selling_products AS
SELECT 
    p.product_id,
    p.product_name,
    p.product_code,
    c.category_name,
    SUM(si.quantity) AS total_sold,
    SUM(si.total_price) AS total_revenue,
    SUM(si.profit) AS total_profit
FROM sale_items si
JOIN products p ON si.product_id = p.product_id
LEFT JOIN categories c ON p.category_id = c.category_id
JOIN sales s ON si.sale_id = s.sale_id
WHERE s.status = 'completed'
GROUP BY p.product_id, p.product_name, p.product_code, c.category_name
ORDER BY total_sold DESC;
