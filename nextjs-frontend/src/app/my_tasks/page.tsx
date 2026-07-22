import MyTasks from "@/components/soc/MyTasks";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "My Tasks | JANUS",
  description: "View and manage your assigned SOC tasks",
};

export default function MyTasksPage() {
  return <MyTasks />;
}
