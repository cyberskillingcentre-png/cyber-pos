# Electrical & Electronics Shop POS System

## Professional Point of Sale System for Electrical & Electronics Businesses

### Features

#### Core POS Features
- ✅ **Point of Sale Interface** - Fast and intuitive sales processing
- ✅ **Barcode Scanner Support** - Quick product lookup
- ✅ **Multiple Payment Methods** - Cash, Card, Mobile Money, Bank Transfer, Credit
- ✅ **Receipt Generation** - PDF receipts with barcodes
- ✅ **Print Receipts** - Direct printing with barcode on receipts

#### Product Management
- ✅ **Product Registration** - Add products with detailed information
- ✅ **Barcode Generation** - Automatic or manual barcode assignment
- ✅ **Expiry Date Tracking** - Track products with expiry dates
- ✅ **No Expiry Option** - For products without expiry
- ✅ **Category Management** - Organize products by categories
- ✅ **Supplier Management** - Track product suppliers
- ✅ **Product Images** - Upload and display product images
- ✅ **Profit Margin Settings** - Set profit margins per product
- ✅ **Tax Configuration** - Configure tax rates per product

#### Inventory Management
- ✅ **Multi-Store Support** - Manage multiple store locations
- ✅ **Store-Specific Inventory** - Products assigned to specific stores
- ✅ **Stock Tracking** - Real-time inventory levels
- ✅ **Low Stock Alerts** - Automatic notifications for low stock
- ✅ **Stock Transfers** - Transfer stock between stores
- ✅ **Purchase Orders** - Manage incoming stock

#### Sales Management
- ✅ **Create Sales** - Quick sale processing
- ✅ **Sales History** - Complete sales records
- ✅ **Invoice Management** - Unique invoice numbering
- ✅ **Sales Returns** - Process returns and refunds
- ✅ **Payment Status Tracking** - Paid, Partial, Unpaid
- ✅ **Customer Association** - Link sales to customers
- ✅ **Discount Management** - Apply discounts to sales

#### Customer Management
- ✅ **Customer Database** - Store customer information
- ✅ **Customer Types** - Regular, Wholesale, VIP
- ✅ **Customer Discounts** - Set custom discount percentages
- ✅ **Purchase History** - Track customer purchase patterns
- ✅ **Customer Reports** - Top customers analysis

#### Reports & Analytics
- ✅ **Sales Reports** - Daily, weekly, monthly sales
- ✅ **Sales Graph** - Visual sales trends
- ✅ **Best Selling Products** - Top performing products
- ✅ **Top Sellers Report** - Best performing staff
- ✅ **Top Customers Report** - Most valuable customers
- ✅ **Inventory Reports** - Stock levels and valuations
- ✅ **Profit Reports** - Profit margins and analysis
- ✅ **Tax Reports** - Tax collection summaries
- ✅ **Export to XML** - Export data in XML format
- ✅ **Filter Transactions** - Advanced filtering options

#### User Management
- ✅ **Admin Panel** - Full system control
- ✅ **Employee Panel** - Limited access for staff
- ✅ **User Roles** - Admin and Employee roles
- ✅ **User Permissions** - Role-based access control
- ✅ **Activity Logs** - Track all user actions
- ✅ **Shift Management** - Track employee shifts

#### Additional Features
- ✅ **Dashboard** - Real-time business metrics
- ✅ **Expense Tracking** - Record business expenses
- ✅ **System Settings** - Configurable system parameters
- ✅ **Backup Functionality** - Data backup and restore
- ✅ **Responsive Design** - Works on all devices
- ✅ **Professional UI** - Purple and Orange theme
- ✅ **Search Functionality** - Quick search across all modules
- ✅ **Audit Trail** - Complete activity logging

### Technology Stack

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+ / MariaDB 10.3+
- **Frontend**: Bootstrap 5, jQuery
- **Charts**: Chart.js
- **Tables**: DataTables
- **PDF Generation**: TCPDF/FPDF
- **Barcode**: JsBarcode
- **Icons**: Font Awesome 6

### Installation Instructions

#### Requirements
- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server
- mod_rewrite enabled (for Apache)

#### Installation Steps

1. **Extract Files**
   ```bash
   unzip electrical_pos_system.zip
   ```

2. **Configure Database**
   - Create a MySQL database named `electrical_pos_db`
   - Import the database schema:
   ```bash
   mysql -u root -p electrical_pos_db < database.sql
   ```

3. **Configure Connection**
   - Edit `/config/config.php`
   - Update database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'electrical_pos_db');
   ```

4. **Set Permissions**
   ```bash
   chmod 777 uploads/
   chmod 777 uploads/products/
   chmod 777 uploads/receipts/
   ```

5. **Access System**
   - Open your browser and navigate to: `http://your-domain.com/electrical_pos_system/`
   - Default login credentials:
     - **Username**: admin
     - **Password**: admin123

6. **Post-Installation**
   - Change the default admin password
   - Configure system settings
   - Add your stores/branches
   - Add categories
   - Add suppliers
   - Start adding products

### Usage Guide

#### Adding Products
1. Navigate to Products > Add Product
2. Fill in product details
3. Assign to store
4. Set expiry date (if applicable) or select "No Expiry"
5. Upload product image
6. Set pricing and profit margins
7. Configure tax rates

#### Making a Sale
1. Navigate to Point of Sale
2. Select store
3. Search for products (by name, code, or barcode)
4. Add products to cart
5. Adjust quantities as needed
6. Select customer (optional)
7. Choose payment method
8. Enter amount paid
9. Complete sale
10. Print receipt

#### Managing Inventory
1. Navigate to Inventory
2. View stock levels by store
3. Add new stock via Purchases
4. Track expiry dates
5. Monitor low stock alerts

#### Viewing Reports
1. Navigate to Reports
2. Select report type
3. Choose date range
4. Apply filters
5. Export data if needed

### Default Account

**Admin Account**
- Username: admin
- Password: admin123
- Role: Administrator

**Important**: Change this password immediately after first login!

### Support & Customization

For support or customization needs:
- Review the code documentation
- Check activity logs for troubleshooting
- Customize colors in `/assets/css/style.css`
- Modify reports as needed

### Security Features

- Password hashing (bcrypt)
- SQL injection prevention (prepared statements)
- XSS protection (input sanitization)
- CSRF protection
- Session management
- Role-based access control
- Activity logging

### File Structure

```
electrical_pos_system/
├── admin/              # Admin panel files
├── employee/           # Employee panel files
├── assets/             # CSS, JS, images
├── config/             # Configuration files
├── includes/           # Reusable components
├── reports/            # Report files
├── uploads/            # Uploaded files
├── database.sql        # Database schema
├── login.php           # Login page
├── logout.php          # Logout handler
└── README.md           # This file
```

### License

This software is provided as-is for use in your business operations.

### Version

Version 1.0.0 - December 2025

---

**Electrical POS System** - Professional Point of Sale for Electrical & Electronics Shops
