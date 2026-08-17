// Shared API types for the MarQira Pulse dashboard. These mirror the Laravel
// API resources under apps/api/app/Http/Resources.

export type SiteStatus = 'online' | 'offline' | 'unknown';

export interface Organization {
  uuid: string;
  name: string;
  slug: string;
  role?: string | null;
}

export interface User {
  uuid: string;
  name: string;
  email: string;
  organization: Organization | null;
}

export interface Site {
  uuid: string;
  domain: string;
  home_url: string | null;
  site_url: string | null;
  status: SiteStatus;
  server_ip: string | null;
  origin_ip: string | null;
  origin_ip_confidence: string | null;
  origin_ip_verified: boolean;
  wp_version: string | null;
  php_version: string | null;
  plugin_version: string | null;
  is_multisite: boolean;
  last_heartbeat_at: string | null;
  last_seen_at: string | null;
  enrolled_at: string | null;
}

export interface SiteDetail extends Site {
  server_hostname: string | null;
  server_software: string | null;
  origin_ip_source: string | null;
  origin_ip_verified_at: string | null;
  origin_ip_verified_by: string | null;
  disconnected_at: string | null;
  created_at: string | null;
}

export interface Heartbeat {
  received_at: string | null;
  wp_version: string | null;
  php_version: string | null;
  plugin_version: string | null;
  server_ip: string | null;
  server_hostname: string | null;
  origin_ip_candidate: string | null;
  is_multisite: boolean;
}

export interface SiteUser {
  snapshot_at: string | null;
  wp_user_id: number;
  user_login: string;
  user_email: string | null;
  display_name: string | null;
  user_registered: string | null;
  roles: string[] | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  metadata: Record<string, unknown> | null;
}

export interface SitePost {
  snapshot_at: string | null;
  wp_post_id: number;
  post_type: string;
  post_status: string;
  post_title: string | null;
  post_date: string | null;
  post_modified: string | null;
  post_author_id: number | null;
  post_author_name: string | null;
  guid: string | null;
  metadata: Record<string, unknown> | null;
}

export interface OverviewCards {
  total: number;
  online: number;
  offline: number;
  needs_attention: number;
  updates_available: number;
}

export interface OverviewResponse {
  cards: OverviewCards;
  latest_plugin_version: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
  };
}

export interface ApiToken {
  uuid: string;
  name: string;
  abilities: string[];
  allowed_ips: string[];
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  is_active: boolean;
  created_at: string | null;
  created_by?: { uuid: string; name: string } | null;
}

export interface ApiTokenListResponse {
  data: ApiToken[];
  available_abilities: string[];
}

export interface EnrollmentToken {
  uuid: string;
  created_at: string | null;
  expires_at: string | null;
  is_expired: boolean;
  is_used: boolean;
  used_at: string | null;
  used_by_site?: { uuid: string; domain: string } | null;
}

export interface AuditLog {
  uuid: string;
  event: string;
  actor_type: string | null;
  actor: { uuid: string; name: string; email: string } | null;
  subject_type: string | null;
  subject_uuid: string | null;
  ip_address: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string | null;
}

export interface SettingsResponse {
  user: User;
  organization: {
    uuid: string;
    name: string;
    slug: string;
    created_at: string | null;
  };
}
