export interface DevicesResponse {
  devices?: any[];
  links?: any[];
  error?: string;
}

export interface DeviceActionResponse {
  success?: boolean;
  message?: string;
  error?: string;
}

export interface ConfigBackupResponse {
  backups?: any[];
  error?: string;
}
