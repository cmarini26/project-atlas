<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import LegalLayout from '@/Layouts/LegalLayout.vue'
</script>

<template>
  <Head>
    <title>Privacy Policy — The Clear Move</title>
    <meta name="description" content="How The Clear Move collects, uses, protects, and shares personal information." />
  </Head>

  <LegalLayout>
    <article class="legal-copy">
      <p class="text-sm font-medium uppercase tracking-widest text-[var(--color-text-muted)]">Effective August 6, 2026</p>
      <h1>Privacy Policy</h1>
      <p>
        The Clear Move (also called “Atlas,” “we,” “us,” or “our”) provides an AI-assisted marketing service for small businesses. This policy explains what information we collect, why we use it, and the choices available to you.
      </p>

      <h2>Information we collect</h2>
      <p>We collect information you provide, including your name, email address, account credentials, business profile, marketing goals, uploaded or connected business content, team membership, feedback, and support communications.</p>
      <p>When you connect a marketing service, we process the credentials and data needed to operate that connection. Depending on what you connect, this may include website content, business listings, social content, WordPress data, campaign assets, email audiences and recipient addresses, delivery events, engagement metrics, and publishing results.</p>
      <p>We also collect ordinary technical data needed to operate and secure the service, such as IP address, browser and device information, timestamps, application logs, authentication events, and error diagnostics. We do not intentionally collect payment-card details in the current beta.</p>

      <h2>How we use information</h2>
      <ul>
        <li>Provide, secure, troubleshoot, and improve Atlas.</li>
        <li>Build your company’s Business Brain and prepare recommendations and campaign content.</li>
        <li>Publish or send approved content through channels you connect.</li>
        <li>Measure campaign outcomes and learn from approvals, edits, rejections, and results.</li>
        <li>Communicate about your account, service changes, security, and support.</li>
        <li>Comply with law and enforce our agreements.</li>
      </ul>
      <p>Atlas requires human approval before external publishing in the beta unless we clearly tell you otherwise and you expressly enable a different workflow.</p>

      <h2>How we share information</h2>
      <p>We do not sell personal information. We share information only as needed with service providers that help us host, secure, monitor, analyze, generate content for, or deliver the service; with marketing platforms you choose to connect; when required by law; or as part of a business transaction such as a merger or acquisition.</p>
      <p>Current categories of providers may include cloud hosting and database vendors, AI model providers, email-delivery services, error monitoring, and connected publishing or analytics platforms. Connected platforms process information under their own terms and privacy policies.</p>

      <h2>Your customers’ information</h2>
      <p>If you upload or connect information about your customers or subscribers, you are responsible for having the rights and permissions needed to provide it to Atlas and to contact those people. We process that information to provide the service on your behalf and do not use it for our own independent marketing.</p>

      <h2>Retention and security</h2>
      <p>We retain information while your account is active and as reasonably needed to provide the service, maintain security and audit records, resolve disputes, and meet legal obligations. Retention periods vary by data type. We use administrative, technical, and organizational safeguards designed to protect information, but no system is completely secure.</p>

      <h2>Your choices and rights</h2>
      <p>You may update account and business information in Atlas, disconnect integrations, and request access, correction, export, or deletion of personal information. Depending on where you live, additional privacy rights may apply. We will verify requests before acting and may retain information where legally permitted or required.</p>

      <h2>Cookies and browser signals</h2>
      <p>We use cookies and similar storage needed for authentication, security, preferences, and core service operation. We do not currently use cross-site behavioral advertising. Because there is no uniform industry response to “Do Not Track” signals, Atlas does not respond to them at this time.</p>

      <h2>Children</h2>
      <p>Atlas is a business service and is not directed to children under 13. We do not knowingly collect personal information from children.</p>

      <h2>International use</h2>
      <p>Atlas is operated from the United States. If you use it from another country, information may be processed in the United States and other locations where our service providers operate.</p>

      <h2>Changes to this policy</h2>
      <p>We may update this policy as Atlas changes. We will post the revised version here, update the effective date, and provide additional notice when a change is material.</p>

      <h2>Contact us</h2>
      <p>Questions or privacy requests can be sent to <a href="mailto:admin@theclearmove.com">admin@theclearmove.com</a>.</p>
    </article>
  </LegalLayout>
</template>

<style scoped>
.legal-copy h1 { margin-top: 0.75rem; font-size: 2.25rem; font-weight: 600; letter-spacing: -0.025em; }
.legal-copy h2 { margin-top: 2.5rem; font-size: 1.25rem; font-weight: 600; }
.legal-copy p { margin-top: 1rem; line-height: 1.75rem; color: var(--color-text-secondary); }
.legal-copy ul { margin-top: 1rem; padding-left: 1.5rem; list-style: disc; line-height: 1.75rem; color: var(--color-text-secondary); }
.legal-copy li + li { margin-top: 0.5rem; }
.legal-copy a { color: var(--color-text-link); text-decoration: underline; text-underline-offset: 2px; }
</style>
