import ActiveIncidents from "@/components/soc/ActiveIncidents";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Active Incidents | JANUS",
  description: "View and manage active SOC incidents",
};

export default function ActiveIncidentsPage() {
  return <ActiveIncidents />;
}
