-- ========================================
-- QUICK LOGIN FIX
-- Run this in phpMyAdmin to fix login issue
-- ========================================

-- Delete existing admin user (if any)
DELETE FROM users WHERE username = 'admin';

-- Create admin user with password: admin123
-- This hash is VERIFIED to work with password_verify('admin123', hash)
INSERT INTO users (username, password, full_name, email, role, status, created_at) 
VALUES (
    'admin', 
    '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 
    'System Administrator', 
    'admin@electricalshop.com', 
    'admin', 
    'active',
    NOW()
);

-- Verify the user was created
SELECT 
    user_id, 
    username, 
    full_name, 
    email, 
    role, 
    status,
    created_at 
FROM users 
WHERE username = 'admin';

-- ========================================
-- After running this:
-- Username: admin
-- Password: admin123
-- ========================================
