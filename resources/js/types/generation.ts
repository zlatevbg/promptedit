export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** Statuses that will still change; mirrors GenerationStatus ranks below Succeeded/Failed. */
export const IN_FLIGHT_STATUSES: readonly GenerationStatus[] = [
    'pending',
    'queued',
    'processing',
];

export type Quality = 'low' | 'medium' | 'high';

export type Size = '1024x1024' | '1536x1024' | '1024x1536';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
    estimated_charge_nanos: number;
    max_charge_nanos: number;
    charge_nanos: number | null;
    error_message: string | null;
    image_url: string | null;
    is_image_expired: boolean;
    created_at: string;
    completed_at: string | null;
};

/** Mirrors App\DTO\Pricing. Rates are US dollars per 1M tokens. */
export type Pricing = {
    rates: Record<string, string>;
    charactersPerTextToken: number;
    textTokenVariance: string;
    tokensPerReferenceImage: number;
    outputTokens: Record<Quality, Record<Size, number>>;
    reasoningTokens: Record<Quality, { min: number; max: number }>;
};

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
};
