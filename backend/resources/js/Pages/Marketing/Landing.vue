<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import MarketingNav from '@/Components/Marketing/MarketingNav.vue'
import HeroSection from '@/Components/Marketing/HeroSection.vue'
import TrustBar from '@/Components/Marketing/TrustBar.vue'
import ProblemStatement from '@/Components/Marketing/ProblemStatement.vue'
import HowItWorks from '@/Components/Marketing/HowItWorks.vue'
import DigitalTwinSection from '@/Components/Marketing/DigitalTwinSection.vue'
import RecommendationShowcase from '@/Components/Marketing/RecommendationShowcase.vue'
import ApprovalMoment from '@/Components/Marketing/ApprovalMoment.vue'
import FeaturesSection from '@/Components/Marketing/FeaturesSection.vue'
import LearningOverTime from '@/Components/Marketing/LearningOverTime.vue'
import IndustriesSection from '@/Components/Marketing/IndustriesSection.vue'
import SocialProofSection from '@/Components/Marketing/SocialProofSection.vue'
import TrustSecuritySection from '@/Components/Marketing/TrustSecuritySection.vue'
import FinalCta from '@/Components/Marketing/FinalCta.vue'
import FaqSection from '@/Components/Marketing/FaqSection.vue'
import MarketingFooter from '@/Components/Marketing/MarketingFooter.vue'
</script>

<template>
  <Head>
    <title>Atlas — AI Marketing for Independent Businesses</title>
    <meta
      name="description"
      content="Atlas connects to your business, builds a living model of what you sell, and recommends the right campaign at the right moment — with a full explanation of why. You approve it. Atlas handles the rest."
    />
  </Head>

  <div class="bg-[var(--color-surface)]">
    <MarketingNav />

    <main id="main-content">
      <HeroSection />
      <TrustBar />
      <ProblemStatement />
      <HowItWorks />
      <DigitalTwinSection />
      <RecommendationShowcase />
      <ApprovalMoment />
      <FeaturesSection />
      <LearningOverTime />
      <IndustriesSection />
      <SocialProofSection />
      <TrustSecuritySection />
      <FinalCta />
      <FaqSection />
    </main>

    <MarketingFooter />
  </div>
</template>
