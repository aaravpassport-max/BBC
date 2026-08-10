import { api } from './client';

export interface UserProfile {
  id: string;
  phone: string;
  country_code: string;
  email: string | null;
  name: string | null;
  locale: string;
  gstin: string | null;
  billing_address: string | null;
  business_name: string | null;
}

export interface SavedAddress {
  id: string;
  label: string;
  address_line: string;
  lat: number;
  lng: number;
  landmark: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  is_default: boolean;
  created_at: string;
}

export function getProfile() {
  return api.get<UserProfile>('/v1/profile');
}

export function updateProfile(data: Partial<Omit<UserProfile, 'id' | 'phone' | 'country_code'>>) {
  return api.put<UserProfile>('/v1/profile', data);
}

export function listAddresses() {
  return api.get<SavedAddress[]>('/v1/addresses');
}

export function createAddress(data: Omit<SavedAddress, 'id' | 'created_at'>) {
  return api.post<SavedAddress>('/v1/addresses', data);
}

export function updateAddress(id: string, data: Partial<Omit<SavedAddress, 'id' | 'created_at'>>) {
  return api.put<SavedAddress>(`/v1/addresses/${id}`, data);
}

export function deleteAddress(id: string) {
  return api.del<{ deleted: boolean }>(`/v1/addresses/${id}`);
}
