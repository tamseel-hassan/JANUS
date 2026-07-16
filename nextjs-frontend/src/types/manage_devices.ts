export interface Device {
  id: number;
  name: string;
  type: string;
  ip: string;
  model: string;
  city: string;
  sub_office: string;
  country: string;
  contact_number: string;
  email: string;
  snmp_community: string;
  snmp_version: string;
  snmp_port: number;
}

export interface Link {
  id: number;
  name: string;
  ip: string;
  from_device_id: number;
  to_device_id: number;
  from_name?: string;
  to_name?: string;
}
