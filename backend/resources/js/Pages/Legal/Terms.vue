<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import LegalLayout from '@/Layouts/LegalLayout.vue'
</script>

<template>
  <Head>
    <title>Terms of Service — The Clear Move</title>
    <meta name="description" content="Terms governing use of The Clear Move and its Atlas marketing service." />
  </Head>

  <LegalLayout>
    <article class="legal-copy">
      <p class="text-sm font-medium uppercase tracking-widest text-[var(--color-text-muted)]">Effective August 6, 2026</p>
      <h1>Terms of Service</h1>
      <p>These Terms govern your use of The Clear Move’s Atlas service. By creating an account or using Atlas, you agree to these Terms. If you use Atlas for a company, you represent that you have authority to bind that company.</p>

      <h2>1. The service</h2>
      <p>Atlas observes business information, prepares marketing recommendations and content, supports approval workflows, executes approved actions through connected services, and measures results. Atlas is currently an early-access beta. Features may change, contain errors, or be unavailable.</p>

      <h2>2. Your account</h2>
      <p>You must provide accurate information, protect your login credentials, and promptly notify us of suspected unauthorized access. You are responsible for activity under your account and for managing your team members’ access.</p>

      <h2>3. Your content, audiences, and connected services</h2>
      <p>You retain ownership of content and data you provide. You grant us a limited license to host, process, reproduce, modify, and transmit that material only as needed to provide and improve Atlas.</p>
      <p>You are responsible for ensuring that you have the necessary rights, notices, consents, and lawful basis for content, customer or subscriber data, and marketing communications you process through Atlas. You must comply with applicable advertising, privacy, intellectual-property, anti-spam, and consumer-protection laws, as well as the rules of connected platforms.</p>
      <p>You authorize Atlas to act through services you connect after the required approval step. You are responsible for reviewing content, recipients, destinations, timing, and claims before approval. You may disconnect a service at any time, but that may not recall an action already sent or published.</p>

      <h2>4. Acceptable use</h2>
      <p>You may not use Atlas to break the law; infringe rights; send deceptive, abusive, or unsolicited communications; distribute malware; probe or disrupt systems; bypass security or usage limits; impersonate others; or process highly sensitive information that Atlas is not designed to handle.</p>

      <h2>5. AI-generated output</h2>
      <p>AI-generated recommendations and content may be incomplete, inaccurate, or unsuitable. You must independently review output before relying on or approving it. Atlas does not provide legal, financial, medical, or other professional advice, and output is not guaranteed to be unique.</p>

      <h2>6. Fees</h2>
      <p>Any paid plan, price, billing period, taxes, cancellation terms, or trial conditions will be disclosed before you incur a charge. The current private beta may be offered without charge unless we agree otherwise in writing.</p>

      <h2>7. Ownership</h2>
      <p>We and our licensors own Atlas, including its software, design, documentation, and trademarks. Except for the limited right to use the service under these Terms, no rights are transferred to you.</p>

      <h2>8. Confidentiality and privacy</h2>
      <p>Each party will use reasonable care to protect the other party’s confidential information and use it only for the service relationship. Our handling of personal information is described in our <a href="/privacy">Privacy Policy</a>.</p>

      <h2>9. Suspension and termination</h2>
      <p>You may stop using Atlas at any time. We may suspend or terminate access if you violate these Terms, create security or legal risk, fail to pay agreed fees, or if continuing the service is no longer practical. Where reasonable, we will give notice and an opportunity to export your information.</p>

      <h2>10. Disclaimers</h2>
      <p>To the fullest extent permitted by law, Atlas is provided “as is” and “as available.” We disclaim implied warranties, including merchantability, fitness for a particular purpose, non-infringement, and warranties arising from course of dealing. We do not guarantee uninterrupted operation, specific marketing outcomes, delivery by third-party platforms, or that generated output will be error-free.</p>

      <h2>11. Limitation of liability</h2>
      <p>To the fullest extent permitted by law, The Clear Move and its suppliers will not be liable for indirect, incidental, special, consequential, exemplary, or punitive damages, or for lost profits, revenue, goodwill, data, or business opportunities. Our total liability arising from the service will not exceed the greater of $100 or the amount you paid us for the service during the three months before the event giving rise to the claim. Some jurisdictions do not allow certain limitations, so parts of this section may not apply to you.</p>

      <h2>12. Indemnity</h2>
      <p>To the extent permitted by law, you will defend and indemnify The Clear Move against third-party claims arising from your content, audiences, approved campaigns, connected services, unlawful use of Atlas, or violation of these Terms or another person’s rights.</p>

      <h2>13. Changes</h2>
      <p>We may update these Terms as Atlas evolves. We will post the revised Terms, update the effective date, and provide additional notice for material changes. Continued use after the effective date means you accept the revised Terms.</p>

      <h2>14. General</h2>
      <p>These Terms and any written order or beta agreement are the entire agreement about Atlas. If a provision is unenforceable, the remaining provisions stay effective. You may not assign these Terms without our consent; we may assign them as part of a merger, acquisition, financing, or sale of assets. Our failure to enforce a provision is not a waiver.</p>

      <h2>15. Contact</h2>
      <p>Questions about these Terms can be sent to <a href="mailto:admin@theclearmove.com">admin@theclearmove.com</a>.</p>
    </article>
  </LegalLayout>
</template>

<style scoped>
.legal-copy h1 { margin-top: 0.75rem; font-size: 2.25rem; font-weight: 600; letter-spacing: -0.025em; }
.legal-copy h2 { margin-top: 2.5rem; font-size: 1.25rem; font-weight: 600; }
.legal-copy p { margin-top: 1rem; line-height: 1.75rem; color: var(--color-text-secondary); }
.legal-copy a { color: var(--color-text-link); text-decoration: underline; text-underline-offset: 2px; }
</style>
