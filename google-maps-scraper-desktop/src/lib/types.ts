export type View =
  | "welcome"
  | "responsible"
  | "dashboard"
  | "progress"
  | "results"
  | "settings"
  | "batch";

export interface AppSettings {
  welcomeDone: boolean;
  responsibleUseAck: boolean;
  defaultDepth: string;
  defaultCoverage: string;
  defaultRate: string;
  exportFolder: string;
  emailExtraction: boolean;
  deduplication: boolean;
  requestDelayMs: number;
  concurrency: number;
  timeoutSecs: number;
  retryCount: number;
  telemetry: boolean;
}

export interface JobRecord {
  id: string;
  name: string;
  keywordsJson: string;
  locationsJson: string;
  status: string;
  resultCount: number;
  duplicatesRemoved: number;
  createdAt: string;
}

export interface BusinessRow {
  id: string;
  jobId: string;
  title: string;
  category: string;
  city: string;
  phone: string;
  email: string;
  website: string;
  reviewRating: string;
  reviewCount: string;
  address: string;
  mapsUrl: string;
  state: string;
  country: string;
  placeId: string;
  latitude: string;
  longitude: string;
  openHours: string;
  description: string;
  searchKeyword: string;
  searchLocation: string;
  scrapedAt: string;
  status: string;
}

export interface JobProgress {
  jobId: string;
  phase: string;
  location: string;
  query: string;
  completedUnits: number;
  totalUnits: number;
  businessesDiscovered: number;
  businessesProcessed: number;
  currentBusiness: string;
  elapsedSecs: number;
  estimatedRemainingSecs?: number;
  paused: boolean;
  captchaPause: boolean;
  batchItems: { location: string; status: string }[];
}

export interface SearchParams {
  keywords: string[];
  locations: string[];
  depthLabel: string;
  coverageMode: string;
  rateMode: string;
  emailExtraction: boolean;
  fields: string[];
  projectName?: string;
}
