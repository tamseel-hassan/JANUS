export interface Device {
  id: number;
  name: string;
  ip: string;
  type: string;
  model: string;
  status: string;
  x: number;
  y: number;
  is_visible: boolean;
  icon_size: number;
}

export interface Link {
  id: number;
  name: string;
  from_device_id: number;
  to_device_id: number;
  status: string;
}

export interface Map {
  id: number;
  name: string;
}
