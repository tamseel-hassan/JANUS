export interface Device {
  id: number;
  name: string;
  city: string;
  sub_office: string;
  country: string;
}

export interface Period {
  status: string;
  start: string;
  end: string;
  duration: number;
  comment_data: {
    comments: string;
    action_taken: string;
    escalation_level: number;
    vendor_contacted: string;
    ticket_number: string;
    resolution_time: string;
  } | null;
}

export interface Report {
  device: Device;
  start_time: string;
  end_time: string;
  total_up_time: number;
  total_down_time: number;
  availability_percent: number;
  avg_rtt: number;
  num_outages: number;
  avg_outage_duration: number;
  periods: Period[];
  rtt_data: { time: string; rtt: number | null; status: string }[];
}
