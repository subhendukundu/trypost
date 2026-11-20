export type Format = 'reel' | 'ad' | 'long-form';

export type Project = {
    id: number;
    title: string;
    brief: string;
    format: Format;
    status: string;
    updated_at: string;
};

export type Provider = { id: string; label: string; configured: boolean };
export type Channel = { id: string; label: string; platform: string };
export type Settings = {
    enabled: boolean;
    run_at: string;
    timezone: string;
    brand_guide: string;
    creative_brief: string;
    provider: string;
    alternative_provider: string | null;
    max_model_calls: number;
    max_voice_characters: number;
    voice_id: string | null;
    can_manage: boolean;
};

export type RunEvent = {
    stage: string;
    message: string | null;
    provider?: string | null;
    at?: string | null;
};

export type Run = RunEvent & {
    id: number;
    kind: string;
    status: string;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    revision_id: number | null;
    events?: RunEvent[];
};

export type Artifact = {
    id: number;
    kind: string;
    title: string;
    url: string;
    sha256: string;
};

export type Treatment = {
    title: string;
    provider: string | null;
    model?: string;
    script: string;
    assessment: string | null;
    selected: boolean;
};

export type StoryboardShot = {
    setting: string;
    camera: string;
    character: string;
    secondary: string;
    expression: string;
    action: string;
    prop: string;
    speech: string;
};

export type StoryboardTreatment = Treatment & {
    angle: string;
    hook: string;
    payoff: string;
    shots: StoryboardShot[];
};

export type Storyboard = {
    run_id: number;
    fingerprint: string;
    treatments: StoryboardTreatment[];
    review: {
        selected: 0 | 1;
        rationale: string;
        assessment_a: string;
        assessment_b: string;
    };
};

export type Revision = {
    id: number;
    parent_id: number | null;
    version: number;
    title: string;
    summary: string;
    caption?: string;
    duration_seconds: number;
    treatments: Treatment[];
    review: {
        rationale?: string;
        quality_score?: number;
        notes?: string | string[];
    } | null;
    artifacts: Artifact[];
    releases?: { url: string; channel: string; status: string }[];
    created_at: string;
};

export type Note = {
    id: number;
    revision_id: number;
    time_seconds: number;
    note: string;
    created_at: string;
};

export type ActiveProject = Project & {
    finalist_revision_id: number | null;
    storyboard: Storyboard | null;
    runs: Run[];
    revisions: Revision[];
    notes: Note[];
};

export type Studio = {
    settings: Settings;
    projects: Project[];
    activeProject: ActiveProject | null;
    providers: Provider[];
    channels: Channel[];
    can_create: boolean;
};

export const formats: Record<Format, string> = {
    reel: 'Reel',
    ad: 'Ad',
    'long-form': 'Long-form video',
};

const labels: Record<string, string> = {
    queued: 'Queued',
    running: 'In production',
    ready_for_review: 'Ready for review',
    needs_review: 'Needs review',
    awaiting_storyboard: 'Storyboard ready',
    completed: 'Complete',
    research: 'Research',
    writing: 'Writing',
    render: 'Rendering',
    reviewing: 'Editorial review',
    editorial_review: 'Editorial review',
    verify: 'Media verification',
    producing: 'Production',
    rendering: 'Rendering',
    verifying: 'Media verification',
    auto: 'Autopilot',
    scheduled: 'Autopilot',
    manual: 'New brief',
    produce: 'Production run',
    revise: 'Revision run',
    source: 'Editable source',
    revision: 'Revision',
    retry: 'Retry',
};

export const displayLabel = (value: string): string =>
    labels[value] ??
    value
        .replaceAll(/[-_]/g, ' ')
        .replace(/^./, (initial) => initial.toUpperCase());

export const isActive = (status: string): boolean =>
    status === 'queued' || status === 'running';

export const canRetry = (status: string): boolean =>
    ['blocked', 'failed', 'interrupted'].includes(status);

export const formatTimestamp = (seconds: number): string => {
    const wholeSeconds = Math.max(0, Math.floor(seconds));
    const minutes = Math.floor(wholeSeconds / 60);
    return `${String(minutes).padStart(2, '0')}:${String(wholeSeconds % 60).padStart(2, '0')}`;
};

export const providerLabel = (providers: Provider[], id: string): string =>
    providers.find((provider) => provider.id === id)?.label ?? displayLabel(id);
