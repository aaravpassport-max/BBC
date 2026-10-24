export interface LocationManifest {
  schemaVersion: number;
  generatedAt: string;
  statesAndUnionTerritories: number;
  districts: number;
  places: number;
  activeStates: number;
  activeDistricts: number;
  activePlaces: number;
  seedVersion: string;
}

export interface LocationNode {
  id: string;
  parentId: string | null;
  level: "state" | "district" | "place" | string;
  name: string;
  active: boolean;
  regionType: string | null;
  placeType: string | null;
  latitude: string | null;
  longitude: string | null;
  population: number;
  iso3166_2: string | null;
  iso2: string | null;
  aliases: string[];
  source: string;
  mergedIntoId: string | null;
  stateName: string | null;
  districtName: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface LocationNodeInput {
  id?: string;
  parentId?: string | null;
  level: string;
  name: string;
  active?: boolean;
  regionType?: string | null;
  placeType?: string | null;
  latitude?: string | null;
  longitude?: string | null;
  population?: number;
  iso3166_2?: string | null;
  iso2?: string | null;
  aliases?: string[];
}

export interface LocationSearchHit {
  id: string;
  label: string;
  placeName: string;
  districtName: string;
  stateName: string;
  placeType: string;
  latitude: string;
  longitude: string;
  population: number;
  active: boolean;
}

export interface DuplicateGroup {
  parentId: string | null;
  parentName: string;
  level: string;
  normalizedName: string;
  nodes: LocationNode[];
}

export function levelLabel(level: string): string {
  switch (level) {
    case "state":
      return "State / UT";
    case "district":
      return "District";
    case "place":
      return "City / town";
    default:
      return level;
  }
}

export function hierarchyLine(node: LocationNode): string {
  if (node.level === "state") return node.name;
  if (node.level === "district") return node.stateName ? `${node.name} · ${node.stateName}` : node.name;
  const parts = [node.name, node.districtName, node.stateName].filter(Boolean);
  return parts.join(" · ");
}
