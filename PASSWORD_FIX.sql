-- FIX FOR ADMIN PASSWORD ISSUE
-- Run this SQL query in phpMyAdmin or MySQL to fix the admin password

-- Update admin password to: admin123
UPDATE users 
SET password = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy' 
WHERE username = 'admin';

-- Verify the update
SELECT username, email, role, status FROM users WHERE username = 'admin';
