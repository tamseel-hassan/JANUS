<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.html');
    exit;
}
$theme = $_COOKIE['theme'] ?? 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Janus- Policy & Compliance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="/css/pages/compliance.css">
</head>
<body class="loggedin">
<?php include 'topbar.php'; ?>
<?php include 'sidebar.php'; ?>
<div id="main-content">
    <div class="container-fluid" style="padding: 80px 20px 20px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="section-header"><i data-lucide="shield" class="icon-lucide me-2"></i>Policy & Compliance</h2>
            <small class="text-muted">Secure your access and stay compliant</small>
        </div>
        
        <!-- Introduction -->
        <p class="mb-4">This page provides guidelines for secure intranet access based on ISO 27001 standards, additional security instructions, and access to our organization's key policy documents. All users are required to read, abide by, and follow these policies to maintain a secure environment.</p>
        
        <!-- ISO 27001 Best Practices Section -->
        <div class="card policy-card mb-4">
            <div class="card-header bg-primary text-white">
                <i data-lucide="lock" class="icon-lucide me-2"></i>General Best Practices for Secure Intranet Access According to ISO 27001
            </div>
            <div class="card-body">
                <p>ISO 27001 emphasizes a risk-based approach to information security. For secure intranet access, organizations should implement controls from Annex A, particularly those related to network security (e.g., A.8.20 Network Security), access control, and human resources security.<grok-card data-id="14fb38" data-type="citation_card"></grok-card><grok-card data-id="02b285" data-type="citation_card"></grok-card><grok-card data-id="c5f0de" data-type="citation_card"></grok-card> Key practices include:</p>
                <ul class="best-practice-list">
                    <li><i data-lucide="network" class="icon-lucide"></i><strong>Network Segmentation:</strong> Segregate networks to prevent unauthorized access and contain potential breaches (ISO 27001 Annex A 8.22).</li>
                    <li><i data-lucide="user" class="icon-lucide -lock"></i><strong>Access Controls:</strong> Implement role-based access control (RBAC) to ensure users only access necessary resources (Annex A 5.15).</li>
                    <li><i data-lucide="key" class="icon-lucide"></i><strong>Multi-Factor Authentication (MFA):</strong> Require MFA for all intranet logins to enhance security.</li>
                    <li><i data-lucide="shield-virus" class="icon-lucide"></i><strong>Encryption:</strong> Use end-to-end encryption for data in transit and at rest (Annex A 8.24).</li>
                    <li><i data-lucide="eye" class="icon-lucide"></i><strong>Monitoring and Logging:</strong> Continuously monitor network activity and maintain logs for auditing and incident response (Annex A 8.15).</li>
                    <li><i data-lucide="user" class="icon-lucide s-cog"></i><strong>Employee Training and Awareness:</strong> Educate staff on security policies, threats, and best practices (Annex A 6.3).</li>
                    <li><i data-lucide="refresh-ccw" class="icon-lucide -alt"></i><strong>Regular Patching and Updates:</strong> Apply security patches promptly to mitigate vulnerabilities (Annex A 8.8).</li>
                    <li><i data-lucide="check-circle" class="icon-lucide"></i><strong>Audits and Compliance Checks:</strong> Conduct regular security audits and vulnerability assessments to ensure ongoing compliance (Annex A 5.35).</li>
                    <li><i data-lucide="ban" class="icon-lucide"></i><strong>Web Filtering and Threat Protection:</strong> Implement web filtering to block malicious content (Annex A 8.23).</li>
                    <li><i data-lucide="handshake-slash" class="icon-lucide"></i><strong>Secure Third-Party Integrations:</strong> Vet and secure any external services connected to the intranet.</li>
                </ul>
                <p class="mt-3">These controls help reduce risks associated with intranet access, such as unauthorized entry, data leaks, and cyber threats.<grok-card data-id="534306" data-type="citation_card"></grok-card><grok-card data-id="e1fb36" data-type="citation_card"></grok-card><grok-card data-id="b92aef" data-type="citation_card"></grok-card></p>
            </div>
        </div>
        
        <!-- Additional Security Instructions Section -->
        <div class="card policy-card mb-4">
            <div class="card-header bg-info text-white">
                <i data-lucide="triangle-alert" class="icon-lucide me-2"></i>Additional Security Instructions and Policy Guidelines
            </div>
            <div class="card-body">
                <p>In addition to ISO 27001 practices, follow these organization-specific guidelines to enhance security:</p>
                <div class="additional-guidelines">
                    <ul class="best-practice-list">
                        <li><i data-lucide="user" class="icon-lucide -secret"></i><strong>Avoid Sharing Credentials:</strong> Never share your login details with anyone, including colleagues.</li>
                        <li><i data-lucide="bug" class="icon-lucide"></i><strong>Report Suspicious Activity:</strong> Immediately report any unusual behavior or potential security incidents to the IT team.</li>
                        <li><i data-lucide="lock" class="icon-lucide -open"></i><strong>Use Strong Passwords:</strong> Create unique, complex passwords and change them regularly.</li>
                        <li><i data-lucide="wifi" class="icon-lucide"></i><strong>Secure Network Usage:</strong> Do not access the intranet from public or unsecured Wi-Fi networks; use VPN if remote access is needed.</li>
                        <li><i data-lucide="mobile-alt" class="icon-lucide"></i><strong>Device Security:</strong> Ensure all devices used for intranet access have up-to-date antivirus software and firewalls enabled.</li>
                        <li><i data-lucide="file-signature" class="icon-lucide"></i><strong>Policy Adherence:</strong> Regularly review and comply with all updated security policies.</li>
                        <li><i data-lucide="trash" class="icon-lucide -alt"></i><strong>Data Handling:</strong> Properly dispose of sensitive information and avoid storing it on personal devices.</li>
                        <li><i data-lucide="headset" class="icon-lucide"></i><strong>Incident Response:</strong> Familiarize yourself with the incident reporting procedure and participate in security drills.</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Organization Files Section -->
        <div class="card policy-card">
            <div class="card-header bg-success text-white">
                <i data-lucide="file-pdf" class="icon-lucide me-2"></i>Organization Policies for Download
            </div>
            <div class="card-body">
                <p>Download and review the following documents. All employees must read, understand, and follow these policies:</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><i data-lucide="shield" class="icon-lucide me-2 text-primary"></i>Organization Network Security Policy 2024</h5>
                                <p class="card-text">This policy outlines the standards for network security within our organization.</p>
                                <a href="Security policy 2024_Final version.pdf" class="btn btn-primary mt-auto download-btn" download>Download PDF</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><i data-lucide="tools" class="icon-lucide me-2 text-primary"></i>SOP for Technical Troubleshooting</h5>
                                <p class="card-text">Standard Operating Procedures for handling network and technical issues.</p>
                                <a href="SOP Manual for Network Troubleshoot 2024_Final version submitted for approval.pdf" class="btn btn-primary mt-auto download-btn" download>Download PDF</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('main-content');
    const adjust = () => content.style.marginLeft = sidebar?.classList.contains('collapsed') ? '80px' : '250px';
    adjust();
    sidebar && new MutationObserver(adjust).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
});
</script>
</body>
</html>

