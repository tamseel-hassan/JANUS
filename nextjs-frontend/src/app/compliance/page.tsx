import {
  Shield,
  Lock,
  Network,
  User,
  Key,
  Eye,
  RefreshCcw,
  CheckCircle,
  Ban,
  Handshake,
  Bug,
  Wifi,
  Smartphone,
  FileSignature,
  Trash,
  Headset,
  FileText,
  Wrench,
} from "lucide-react";

export default function CompliancePage() {
  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 mb-8">
        <div>
          <h1 className="text-2xl font-medium text-foreground tracking-wide flex items-center">
            <Shield className="w-8 h-8 mr-3 text-accent-primary" /> Policy &
            Compliance
          </h1>
          <p className="text-text-muted mt-2 text-lg">
            Secure your access and stay compliant
          </p>
        </div>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 mb-6">
        <p className="text-[#d8cce2] leading-relaxed text-lg">
          This page provides guidelines for secure intranet access based on ISO
          27001 standards, additional security instructions, and access to our
          organization's key policy documents. All users are required to read,
          abide by, and follow these policies to maintain a secure environment.
        </p>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden mb-6">
        <div className="bg-blue-900/40 p-4 border-b border-border-subtle flex items-center">
          <Lock className="w-6 h-6 mr-3 text-blue-400" />
          <h2 className="text-xl font-medium text-foreground">
            General Best Practices (ISO 27001)
          </h2>
        </div>
        <div className="p-6">
          <p className="text-[#d8cce2] mb-6">
            ISO 27001 emphasizes a risk-based approach to information security.
            For secure intranet access, organizations should implement controls
            from Annex A, particularly those related to network security (e.g.,
            A.8.20 Network Security), access control, and human resources
            security. Key practices include:
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="flex items-start">
              <Network className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Network Segmentation
                </h4>
                <p className="text-text-muted text-sm">
                  Segregate networks to prevent unauthorized access and contain
                  potential breaches (ISO 27001 Annex A 8.22).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <User className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Access Controls
                </h4>
                <p className="text-text-muted text-sm">
                  Implement role-based access control (RBAC) to ensure users
                  only access necessary resources (Annex A 5.15).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <Key className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Multi-Factor Authentication (MFA)
                </h4>
                <p className="text-text-muted text-sm">
                  Require MFA for all intranet logins to enhance security.
                </p>
              </div>
            </div>

            <div className="flex items-start">
              {/* <ShieldVirus className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" /> */}
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Encryption
                </h4>
                <p className="text-text-muted text-sm">
                  Use end-to-end encryption for data in transit and at rest
                  (Annex A 8.24).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <Eye className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Monitoring and Logging
                </h4>
                <p className="text-text-muted text-sm">
                  Continuously monitor network activity and maintain logs for
                  auditing and incident response (Annex A 8.15).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <User className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Employee Training and Awareness
                </h4>
                <p className="text-text-muted text-sm">
                  Educate staff on security policies, threats, and best
                  practices (Annex A 6.3).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <RefreshCcw className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Regular Patching and Updates
                </h4>
                <p className="text-text-muted text-sm">
                  Apply security patches promptly to mitigate vulnerabilities
                  (Annex A 8.8).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <CheckCircle className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Audits and Compliance Checks
                </h4>
                <p className="text-text-muted text-sm">
                  Conduct regular security audits and vulnerability assessments
                  to ensure ongoing compliance (Annex A 5.35).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <Ban className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Web Filtering and Threat Protection
                </h4>
                <p className="text-text-muted text-sm">
                  Implement web filtering to block malicious content (Annex A
                  8.23).
                </p>
              </div>
            </div>

            <div className="flex items-start">
              <Handshake className="w-6 h-6 text-accent-primary mr-4 flex-shrink-0 mt-1" />
              <div>
                <h4 className="text-foreground font-bold text-lg mb-1">
                  Secure Third-Party Integrations
                </h4>
                <p className="text-text-muted text-sm">
                  Vet and secure any external services connected to the
                  intranet.
                </p>
              </div>
            </div>
          </div>
          <div className="mt-8 p-4 bg-bg-main border-l-4 border-accent-primary rounded-r text-text-muted">
            These controls help reduce risks associated with intranet access,
            such as unauthorized entry, data leaks, and cyber threats.
          </div>
        </div>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden mb-6">
        <div className="bg-yellow-900/30 p-4 border-b border-border-subtle flex items-center">
          <Shield className="w-6 h-6 mr-3 text-yellow-500" />
          <h2 className="text-xl font-bold text-foreground">
            Additional Security Instructions
          </h2>
        </div>
        <div className="p-6">
          <p className="text-[#d8cce2] mb-6">
            In addition to ISO 27001 practices, follow these
            organization-specific guidelines to enhance security:
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Lock className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">
                  Use Strong Passwords
                </h4>
                <p className="text-text-muted text-sm mt-1">
                  Create unique, complex passwords and change them regularly.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <User className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">
                  Avoid Sharing Credentials
                </h4>
                <p className="text-text-muted text-sm mt-1">
                  Never share your login details with anyone, including
                  colleagues.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Bug className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">
                  Report Suspicious Activity
                </h4>
                <p className="text-text-muted text-sm mt-1">
                  Immediately report any unusual behavior or potential security
                  incidents to the IT team.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Wifi className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">
                  Secure Network Usage
                </h4>
                <p className="text-text-muted text-sm mt-1">
                  Do not access the intranet from public or unsecured Wi-Fi
                  networks; use VPN if remote access is needed.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Smartphone className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">Device Security</h4>
                <p className="text-text-muted text-sm mt-1">
                  Ensure all devices used for intranet access have up-to-date
                  antivirus software and firewalls enabled.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <FileSignature className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">Policy Adherence</h4>
                <p className="text-text-muted text-sm mt-1">
                  Regularly review and comply with all updated security
                  policies.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Trash className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">Data Handling</h4>
                <p className="text-text-muted text-sm mt-1">
                  Properly dispose of sensitive information and avoid storing it
                  on personal devices.
                </p>
              </div>
            </div>

            <div className="flex items-start bg-bg-main p-4 rounded-2xl border border-border-subtle">
              <Headset className="w-5 h-5 text-yellow-500 mr-3 mt-0.5" />
              <div>
                <h4 className="text-foreground font-bold">Incident Response</h4>
                <p className="text-text-muted text-sm mt-1">
                  Familiarize yourself with the incident reporting procedure and
                  participate in security drills.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden mb-6">
        <div className="bg-green-900/30 p-4 border-b border-border-subtle flex items-center">
          <FileText className="w-6 h-6 mr-3 text-green-500" />
          <h2 className="text-xl font-bold text-foreground">
            Organization Policies for Download
          </h2>
        </div>
        <div className="p-6">
          <p className="text-[#d8cce2] mb-6">
            Download and review the following documents. All employees must
            read, understand, and follow these policies:
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-6 flex flex-col hover:border-green-500/50 transition-colors">
              <h5 className="text-lg font-bold text-foreground mb-2 flex items-center">
                <Shield className="w-5 h-5 text-green-500 mr-2" /> Organization
                Network Security Policy 2024
              </h5>
              <p className="text-text-muted mb-6 flex-1">
                This policy outlines the standards for network security within
                our organization.
              </p>
              <a
                href="/Security policy 2024_Final version.pdf"
                download
                className="w-full text-center px-4 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors"
              >
                Download PDF
              </a>
            </div>

            <div className="bg-bg-main border border-border-subtle rounded-2xl p-6 flex flex-col hover:border-green-500/50 transition-colors">
              <h5 className="text-lg font-bold text-foreground mb-2 flex items-center">
                <Wrench className="w-5 h-5 text-green-500 mr-2" /> SOP for
                Technical Troubleshooting
              </h5>
              <p className="text-text-muted mb-6 flex-1">
                Standard Operating Procedures for handling network and technical
                issues.
              </p>
              <a
                href="/SOP Manual for Network Troubleshoot 2024_Final version submitted for approval.pdf"
                download
                className="w-full text-center px-4 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors"
              >
                Download PDF
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
