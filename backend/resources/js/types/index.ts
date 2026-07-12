// Domain types — mirror actual controller response shapes

export type TwinStatus = 'initializing' | 'crawling' | 'analyzing' | 'ready' | 'error'
export type RecommendationStatus = 'pending' | 'approved' | 'rejected' | 'expired'
export type CampaignStatus = 'draft' | 'approved' | 'active' | 'published' | 'cancelled' | 'completed'
export type OpportunityStatus = 'open' | 'selected' | 'expired' | 'dismissed'
export type ExecutionStatus = 'pending' | 'scheduled' | 'executing' | 'published' | 'completed' | 'failed' | 'cancelled'
export type PerformanceRating = 'exceeded' | 'met' | 'below' | 'insufficient_data'

export interface AuthUser {
    id: string
    name: string
    email: string
    has_completed_tour: boolean
    has_dismissed_checklist: boolean
}

export interface Company {
    id: string
    name: string
    slug: string | null
    industry: string | null
}

// DigitalTwin as returned by backend controllers
export interface DigitalTwin {
    status: TwinStatus
    health_score: number
    last_enriched_at: string | null
}

// Fact as returned by BusinessBrainController
export interface Fact {
    id: string
    key: string
    value: unknown
    data_type: string
    confidence: number | null
    created_at: string
}

// Knowledge as returned by BusinessBrainController
export interface Knowledge {
    id: string
    subject: string
    body: string
    confidence: number | null
    type: string
    expires_at: string | null
}

// Observation as returned by BusinessBrainController
export interface BrainObservation {
    id: string
    status: string
    created_at: string
}

// Recommendation as returned by RecommendationController.formatRecommendation()
// campaign_type can be null for legacy recommendations created before
// RecommendationService copied it from the campaign — templates must guard it.
export interface Recommendation {
    id: string
    status: RecommendationStatus
    campaign_type: string | null
    rationale_display: Record<string, string>
    expected_impact: Record<string, string>
    responded_at: string | null
    created_at: string
    decision?: { confidence_score: number; rationale?: Record<string, string> } | null
}

// Decision as returned in show() response (separate prop)
export interface DecisionDetail {
    id?: string
    rationale: Record<string, string> | null
    expected_impact: Record<string, string | number> | null
    confidence_score: number
    campaign_type?: string
}

// ContentAsset as returned by controllers (uses 'type' not 'asset_type')
export interface ContentAsset {
    id: string
    type: string
    body: string
    title: string | null
    status: string
    metadata: Record<string, unknown>
    channel?: { type: string; marketing_channel?: { supports_publishing: boolean } | null } | null
}

// One entry in a Recommendation's channel mix (RecommendationController::show())
export interface ExecutableChannelMixEntry {
    type: string
    name: string
    marketing_channel: { supports_publishing: boolean } | null
}

export interface DraftOnlyChannelMixEntry {
    type: string
    name: string
}

export interface UnavailableChannelMixEntry {
    type: string
    name: string
    reason: 'inactive' | 'planned'
}

// Channel mix as returned by RecommendationController::show() — see
// Milestone 11 Phase 7 (docs/reviews/Milestone-11-Phase-7-Review.md)
export interface ChannelMix {
    primary: ExecutableChannelMixEntry[]
    supporting: ExecutableChannelMixEntry[]
    draft_only: DraftOnlyChannelMixEntry[]
    unavailable: UnavailableChannelMixEntry[]
}

export interface Campaign {
    id: string
    title: string
    campaign_type: string
    status: CampaignStatus
    created_at: string
    completed_at: string | null
    blueprint?: Record<string, unknown> | null
}

// Execution as returned by controllers
export interface Execution {
    id: string
    status: ExecutionStatus
    scheduled_at: string | null
    executed_at?: string | null
    completed_at: string | null
    last_error?: string | null
    channel?: { type: string } | null
}

// CampaignKpiSnapshot as returned by controllers (uses actual_kpis, snapshotted_at)
export interface CampaignKpiSnapshot {
    id: string
    snapshot_type: 'interim' | 'final'
    actual_kpis: Record<string, number>
    performance_rating: PerformanceRating | null
    snapshotted_at: string
    campaign?: { id: string; title: string; campaign_type: string }
}

// ExecutionMetric as returned by controllers (uses channel_type, retrieved_at)
export interface ExecutionMetric {
    id: string
    channel_type: string
    provider_type: string
    metrics: Record<string, number>
    normalised_reach: number | null
    normalised_engagement: number | null
    normalised_engagement_rate: number | null
    retrieved_at: string
    is_final: boolean
}

// Opportunity as returned by OpportunityController (uses 'type' not 'opportunity_type')
export interface Opportunity {
    id: string
    type: string
    title: string
    description: string
    composite_score: number | null
    relevance_score: number | null
    timing_score: number | null
    confidence_score: number | null
    urgency_score: number | null
    status: OpportunityStatus
    detected_at: string | null
    expires_at: string | null
    subject_type: string | null
    subject_id: string | null
}

// Learning as returned by LearningController
export interface Learning {
    id: string
    signal: string
    value: Record<string, unknown>
    applied_at: string | null
    source_type: string
    created_at: string
}

// LearningApplication effect as returned by LearningController
export interface AppliedEffect {
    id: string
    effects: Array<{ type: string; description: string; entity_type?: string }>
    rolled_back_at: string | null
    created_at: string
}

// Page prop shapes (Inertia shared data)
export interface CompanyOption {
    id: string
    name: string
}

export interface SharedProps {
    auth: { user: AuthUser | null }
    company: Company | null
    companies: CompanyOption[]
    flash: { success: string | null; error: string | null }
    show_feedback_prompt: boolean
}
