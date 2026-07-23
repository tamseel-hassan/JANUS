import { SiemData, LiveLog } from "@/types/logs";

export type { SiemData, LiveLog };

export interface LiveLogsResponse {
  logs: LiveLog[];
}

export interface SiemActionResponse {
  success: boolean;
  error?: string;
  deleted?: number;
}
