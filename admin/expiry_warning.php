<?php
// Check if we should show expiry warning
if (show_expiry_warning()) {
    $days_remaining = get_days_remaining();
    $subscription_type = $_SESSION['subscription_type'] ?? 'demo';
    ?>
    <div class="alert alert-warning alert-dismissible fade show sticky-top" role="alert" style="z-index: 1050;">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-auto">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                </div>
                <div class="col">
                    <h5 class="alert-heading mb-1">
                        <i class="fas fa-clock me-2"></i>
                        <?php if ($subscription_type == 'demo'): ?>
                            Trial Expiring Soon!
                        <?php else: ?>
                            Subscription Expiring Soon!
                        <?php endif; ?>
                    </h5>
                    <p class="mb-0">
                        Your <?php echo $subscription_type == 'demo' ? 'free trial' : 'subscription'; ?> expires in 
                        <strong class="text-danger"><?php echo $days_remaining; ?> day<?php echo $days_remaining != 1 ? 's' : ''; ?></strong>.
                        Please contact administrator to renew your subscription.
                    </p>
                    <small>
                        <i class="fas fa-envelope me-1"></i>admin@cyberpos.com | 
                        <i class="fas fa-phone me-1"></i>+256-XXX-XXXXXX
                    </small>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>