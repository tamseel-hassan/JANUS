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
