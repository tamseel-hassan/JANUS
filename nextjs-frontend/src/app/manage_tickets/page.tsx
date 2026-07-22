import ManageTickets from "@/components/soc/ManageTickets";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Manage Tickets | JANUS",
  description: "Manage SOC tickets and incidents",
};

export default function ManageTicketsPage() {
  return <ManageTickets />;
}
