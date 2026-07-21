export interface Firewall {
  firewall_ip: string;
  username: string;
  is_active: number;
  added_at: string;
}

export interface Action {
  id: number;
  firewall_ip: string;
  action_type: string;
  target_ip: string;
  status: string;
  created_at: string;
  username: string;
}

export interface AutomationStats {
  totalPlaybooks: number;
  enabledPlaybooks: number;
  totalExecutions: number;
  failedExecutions: number;
  totalBlocked: number;
  activeBlocked: number;
}

export interface PlaybookExecution {
  id: number;
  playbook_id: number;
  pb_name?: string;
  status: string;
  triggered_by: string;
  created_at: string;
}

export interface AutomationData {
  needsInstall: boolean;
  stats: AutomationStats;
  recentExecutions: PlaybookExecution[];
}
