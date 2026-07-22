import ReportIncident from "@/components/soc/ReportIncident";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Report Incident | JANUS",
  description: "Report a new SOC incident",
};

export default function ReportIncidentPage() {
  return <ReportIncident />;
}
