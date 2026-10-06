<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CYBER POS - Cloud-Based POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-label{
            color:black;
        }
        .hero-section {
            background: linear-gradient(135deg, #6B46C1 0%, #805AD5 100%);
            color: white;
            padding: 100px 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .feature-card {
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            transition: all 0.3s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.2);
        }
        .register-card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            max-height: 600px;
            overflow-y: auto;
        }
        .btn-demo {
            background: #ED8936;
            color: white;
            padding: 15px 40px;
            font-size: 1.2rem;
            border-radius: 50px;
        }
        .btn-demo:hover {
            background: #DD6B20;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: #6B46C1;">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-bolt me-2"></i>CYBER POS
            </a>
            <div class="ms-auto">
                <a href="Login" class="btn btn-light me-2">Login</a>
                
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4">Powerful Cloud POS System</h1>
                    <p class="lead mb-4">Complete point-of-sale solution for retail businesses. Inventory management, sales tracking, customer management, and more!</p>
                    <ul class="list-unstyled mb-4">
                        <li class="mb-2"><i class="fas fa-check-circle me-2"></i>Multi-store Management</li>
                        <li class="mb-2"><i class="fas fa-check-circle me-2"></i>Real-time Inventory Tracking</li>
                        <li class="mb-2"><i class="fas fa-check-circle me-2"></i>Credit & Cash Sales</li>
                        <li class="mb-2"><i class="fas fa-check-circle me-2"></i>Comprehensive Reports</li>
                        <li class="mb-2"><i class="fas fa-check-circle me-2"></i>Customer Management</li>
                    </ul>
                    <a href="#register" class="btn btn-demo">
                        <i class="fas fa-rocket me-2"></i>Start 30-Day Free Trial
                    </a>
                </div>
                <div class="col-lg-6">
                    <div class="register-card" id="register">
                        <h3 class="text-center mb-4" style="color: #6B46C1;">Register Your Organization</h3>
                        <form method="POST" action="Register_Your_Company" id="registrationForm">
                            <div class="mb-3">
                                <label class="form-label">Organization Name *</label>
                                <input type="text" name="org_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contact Person *</label>
                                <input type="text" name="contact_person" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="tel" name="phone" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="2"></textarea>
                            </div>
                            
                            <hr class="my-4">
                            <h5 class="mb-3" style="color: #6B46C1;"><i class="fas fa-user-lock me-2"></i>Login Credentials</h5>
                            
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" required minlength="4" pattern="[a-zA-Z0-9_]+" title="Only letters, numbers and underscore allowed">
                                <small class="text-muted">Minimum 4 characters. Letters, numbers and underscore only.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" name="password" id="password" class="form-control" required minlength="6">
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                <small class="text-danger" id="password-error" style="display:none;">Passwords do not match</small>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>30-Day Free Trial</strong><br>
                                No credit card required. Full access to all features.
                            </div>
                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="fas fa-check me-2"></i>Register Now
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5">
        <div class="container">
            <h2 class="text-center mb-5">Why Choose CYBER POS?</h2>
            <div class="row">
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <i class="fas fa-cloud fa-3x text-primary mb-3"></i>
                        <h4>Cloud-Based</h4>
                        <p>Access your business from anywhere, anytime. All data securely stored in the cloud.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <i class="fas fa-shield-alt fa-3x text-success mb-3"></i>
                        <h4>Secure & Reliable</h4>
                        <p>Bank-level security with automatic backups and 99.9% uptime guarantee.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center">
                        <i class="fas fa-headset fa-3x text-warning mb-3"></i>
                        <h4>24/7 Support</h4>
                        <p>Dedicated support team ready to help you succeed with your business.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password confirmation validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const errorMsg = document.getElementById('password-error');
            
            if (password !== confirmPassword) {
                e.preventDefault();
                errorMsg.style.display = 'block';
                document.getElementById('confirm_password').classList.add('is-invalid');
                return false;
            } else {
                errorMsg.style.display = 'none';
                document.getElementById('confirm_password').classList.remove('is-invalid');
            }
        });
        
        // Remove error on typing
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            const errorMsg = document.getElementById('password-error');
            
            if (password === confirmPassword) {
                errorMsg.style.display = 'none';
                this.classList.remove('is-invalid');
            }
        });
    </script>
</body>
</html>