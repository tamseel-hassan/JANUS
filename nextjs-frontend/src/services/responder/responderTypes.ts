import { Firewall, Action, AutomationStats, PlaybookExecution, AutomationData } from "@/types/responder";

export type { Firewall, Action, AutomationStats, PlaybookExecution, AutomationData };

export interface ResponderDataResponse {
  firewalls?: Firewall[];
  actions?: Action[];
  error?: string;
}

export interface ResponderActionResponse {
  success?: boolean;
  message?: string;
  error?: string;
}

export interface InstallAutomationResponse {
  success?: boolean;
  error?: string;
}
