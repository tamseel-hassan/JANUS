import IncidentsHistory from "@/components/soc/IncidentsHistory";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Incidents History | JANUS",
  description: "View history of SOC incidents",
};

export default function IncidentsHistoryPage() {
  return <IncidentsHistory />;
}
